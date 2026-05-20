<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use App\Http\Traits\TraitJWTRsiMadinah;
use App\Http\Traits\BPJS\AntrianTrait;
use App\Http\Traits\Txn\Rj\EmrRJTrait;
use App\Models\User;
use Exception;

/**
 * Controller untuk integrasi Antrol (Antrean Online) BPJS.
 *
 * Alur utama pendaftaran JKN Mobile:
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ 1. token()              → Autentikasi & generate JWT token             │
 * │ 2. ambilantrean()       → Booking antrian (validasi pasien, quota,     │
 * │                           duplikasi, lalu simpan ke referensi_mobilejkn)│
 * │ 3. checkinantrean()     → Checkin (buat record RJ di rstxn_rjhdrs,    │
 * │                           push antrean & task ID 3 ke BPJS)           │
 * │ 4. batalantrean()       → Pembatalan antrian (sebelum checkin)        │
 * │ 5. statusantrean()      → Cek status & sisa quota poli/dokter        │
 * │ 6. sisaantrean()        → Cek sisa antrean per kodebooking            │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Endpoint tambahan:
 * - jadwaloperasirs()       → Jadwal operasi RS berdasarkan rentang tanggal
 * - jadwaloperasipasien()   → Jadwal operasi per peserta BPJS
 * - pasienbaru()            → Placeholder (belum didukung, harus daftar offline)
 *
 * Tabel utama:
 * - referensi_mobilejkn_bpjs → Data booking JKN Mobile (status: Belum/Checkin/Batal)
 * - rstxn_rjhdrs             → Header transaksi rawat jalan (dibuat saat checkin)
 * - rsview_rjkasir            → View gabungan RJ untuk cek quota & status pasien
 * - scview_scpolis            → View jadwal & kuota dokter per poli per hari
 * - rsmst_pasiens             → Master data pasien
 * - rsmst_doctors             → Master data dokter
 * - rsmst_polis               → Master data poli
 * - booking_operasi           → Data jadwal operasi
 */
class AntrolBPJSController extends Controller
{
    use TraitJWTRsiMadinah, AntrianTrait, EmrRJTrait;
    /////////////
    // API SIMRS
    /////////////


    /**
     * Metode autentikasi terpusat.
     *
     * Proses:
     * 1. Ambil x-username dan x-token dari header request
     * 2. Validasi keberadaan credentials (username & token)
     * 3. Cari user berdasarkan username di database
     * 4. Verifikasi token JWT melalui method cektoken()
     * 5. Return user object jika valid, atau JsonResponse error jika gagal
     *
     * @param Request $request
     * @return mixed (User atau JsonResponse error)
     */
    protected function authenticate(Request $request)
    {
        $username = $request->header('x-username');
        $token    = $request->header('x-token');

        if (!$username || !$token) {
            return $this->sendError($request, "Unauthorized: Missing credentials", 201);
        }

        $user = User::where('name', $username)->first();
        if (!$user) {
            return $this->sendError($request, "Unauthorized: User not found", 201);
        }

        if ($this->cektoken($token) !== 1) {
            return $this->cektoken($token);
        }

        return $user;
    }

    /**
     * Endpoint untuk mendapatkan token.
     *
     * Proses:
     * 1. Ambil x-username dan x-password dari header request
     * 2. Autentikasi credentials via Auth::attempt()
     * 3. Jika berhasil, generate JWT token via createToken()
     * 4. Return token dalam response
     */
    public function token(Request $request)
    {
        $credentials = [
            'name'     => $request->header('x-username'),
            'password' => $request->header('x-password')
        ];

        if (Auth::attempt($credentials)) {
            $token = $this->createToken($credentials['name'], $credentials['password']);
            return $this->sendResponse($request, ['token' => $token], 200);
        } else {
            return $this->sendError($request, "Unauthorized (Username dan Password Salah)", 201);
        }
    }

    /**
     * Endpoint jadwal operasi RS.
     *
     * Proses:
     * 1. Autentikasi user via header x-username & x-token
     * 2. Validasi input: tanggalawal & tanggalakhir (format Y-m-d, akhir > awal)
     * 3. Query tabel booking_operasi JOIN rsmst_doctors & rsmst_polis
     *    untuk mendapatkan jadwal operasi dalam rentang tanggal
     * 4. Mapping hasil query ke format response BPJS:
     *    kodebooking, tanggaloperasi, jenistindakan, kodepoli, namapoli,
     *    kodedokter, terlaksana (0=Menunggu, 1=Selesai), nopeserta
     * 5. Return list jadwal operasi
     */
    public function jadwaloperasirs(Request $request)
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof \Illuminate\Http\JsonResponse) {
            return $auth;
        }

        $validator = Validator::make(
            $request->all(),
            [
                "tanggalawal"  => "required|date|date_format:Y-m-d",
                "tanggalakhir" => "required|date|date_format:Y-m-d|after:tanggalawal",
            ],
            [
                'tanggalawal.required' => 'Tanggal awal wajib diisi.',
                'tanggalawal.date' => 'Tanggal awal harus berupa tanggal yang valid.',
                'tanggalawal.date_format' => 'Format tanggal awal harus YYYY-MM-DD.',

                'tanggalakhir.required' => 'Tanggal akhir wajib diisi.',
                'tanggalakhir.date' => 'Tanggal akhir harus berupa tanggal yang valid.',
                'tanggalakhir.date_format' => 'Format tanggal akhir harus YYYY-MM-DD.',
                'tanggalakhir.after' => 'Tanggal akhir harus setelah tanggal awal.',
            ]
        );

        if ($validator->fails()) {
            return $this->sendError($request, $validator->errors()->first(), 201);
        }

        $startDate = Carbon::parse($request->tanggalawal)->startOfDay();
        $endDate   = Carbon::parse($request->tanggalakhir)->endOfDay();

        $jadwalops = DB::table('booking_operasi')
            ->leftJoin('rsmst_doctors', 'booking_operasi.dr_id', '=', 'rsmst_doctors.dr_id')
            ->leftJoin('rsmst_polis', 'booking_operasi.poli_id', '=', 'rsmst_polis.poli_id')
            ->select(
                'booking_operasi.*',
                'rsmst_doctors.kd_dr_bpjs',
                'rsmst_polis.kd_poli_bpjs',
                'rsmst_polis.poli_desc'
            )
            ->whereBetween('booking_operasi.tanggal', [$startDate, $endDate])
            ->get();

        if ($jadwalops->isEmpty()) {
            return $this->sendError($request, 'Data Tidak ditemukan', 201);
        }

        $jadwals = [];
        foreach ($jadwalops as $jadwalop) {
            $jadwals[] = [
                "kodebooking"    => $jadwalop->no_rawat,
                "tanggaloperasi" => Carbon::parse($jadwalop->tanggal)->format('Y-m-d'),
                "jenistindakan"  => $jadwalop->nm_paket,
                "kodepoli"       => $jadwalop->kd_poli_bpjs ?? 'BED',
                "namapoli"       => $jadwalop->poli_desc ?? 'BEDAH',
                "kodedokter"     => $jadwalop->kd_dr_bpjs ?? '',
                "terlaksana"     => $jadwalop->status === 'Menunggu' ? 0 : 1,
                "nopeserta"      => $jadwalop->no_peserta,
                "lastupdate"     => now(config('app.timezone'))->timestamp * 1000,
            ];
        }

        return $this->sendResponse($request, ["list" => $jadwals], 200);
    }

    /**
     * Endpoint jadwal operasi pasien.
     *
     * Proses:
     * 1. Autentikasi user via header x-username & x-token
     * 2. Validasi input: nopeserta (13 digit)
     * 3. Query tabel booking_operasi JOIN rsmst_doctors & rsmst_polis
     *    berdasarkan nomor peserta BPJS
     * 4. Mapping hasil query ke format response BPJS (sama seperti jadwaloperasirs)
     * 5. Return list jadwal operasi milik peserta tersebut
     */
    public function jadwaloperasipasien(Request $request)
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof \Illuminate\Http\JsonResponse) {
            return $auth;
        }

        $validator = Validator::make($request->all(), [
            "nopeserta" => "required|digits:13",
        ]);
        if ($validator->fails()) {
            return $this->sendError($request, $validator->errors()->first(), 201);
        }

        $jadwalops = DB::table('booking_operasi')
            ->leftJoin('rsmst_doctors', 'booking_operasi.dr_id', '=', 'rsmst_doctors.dr_id')
            ->leftJoin('rsmst_polis', 'booking_operasi.poli_id', '=', 'rsmst_polis.poli_id')
            ->select(
                'booking_operasi.*',
                'rsmst_doctors.kd_dr_bpjs',
                'rsmst_polis.kd_poli_bpjs',
                'rsmst_polis.poli_desc'
            )
            ->where('booking_operasi.no_peserta', $request->nopeserta)
            ->get();

        if ($jadwalops->isEmpty()) {
            return $this->sendError($request, 'Data Tidak ditemukan', 201);
        }

        $jadwals = [];
        foreach ($jadwalops as $jadwalop) {
            $jadwals[] = [
                "kodebooking"    => $jadwalop->no_rawat,
                "tanggaloperasi" => Carbon::parse($jadwalop->tanggal)->format('Y-m-d'),
                "jenistindakan"  => $jadwalop->nm_paket,
                "kodepoli"       => $jadwalop->kd_poli_bpjs ?? 'BED',
                "namapoli"       => $jadwalop->poli_desc ?? 'BEDAH',
                "kodedokter"     => $jadwalop->kd_dr_bpjs ?? '',
                "terlaksana"     => $jadwalop->status === 'Menunggu' ? 0 : 1,
                "nopeserta"      => $jadwalop->no_peserta,
                "lastupdate"     => now(config('app.timezone'))->timestamp * 1000,
            ];
        }

        return $this->sendResponse($request, ["list" => $jadwals], 200);
    }

    /**
     * Endpoint ambil antrean (booking) dari JKN Mobile.
     *
     * Proses:
     * 1. Autentikasi user via header x-username & x-token
     * 2. Validasi pasien:
     *    - Cek No Kartu BPJS di rsmst_pasiens (harus sudah terdaftar, bukan pasien baru)
     *    - Cocokkan NIK BPJS dengan NIK di RS
     *    - Sinkronisasi norm dengan reg_no pasien
     * 3. Validasi input: nomorkartu(13digit), nik(16digit), nohp, kodepoli, norm,
     *    tanggalperiksa, kodedokter, jampraktek, jeniskunjungan, nomorreferensi(jika bukan kontrol)
     * 4. Validasi tanggal periksa: tidak boleh lewat & maksimal 35 hari ke depan
     * 5. Cek duplikasi: tidak boleh ada antrian aktif dengan NIK sama di tanggal yang sama
     * 6. Cek keberadaan dokter (rsmst_doctors) dan poli (rsmst_polis) berdasarkan kode BPJS
     * 7. Cek quota: query scview_scpolis untuk kuota & jadwal, lalu hitung sisa quota
     *    dari rsview_rjkasir (pasien terdaftar yang belum batal). Tolak jika quota habis.
     * 8. Generate nomor booking: format YmdHis + 'JKN'
     * 9. Gunakan Cache::lock per dokter/tanggal untuk mencegah race condition:
     *    - Hitung nomor antrian MAX dari rstxn_rjhdrs dan referensi_mobilejkn_bpjs
     *    - Hitung estimasi waktu dilayani (10 menit per antrian)
     *    - Insert data booking ke referensi_mobilejkn_bpjs (status='Belum')
     * 10. Return: nomorantrean, angkaantrean, kodebooking, norm, namapoli, namadokter,
     *     estimasidilayani, sisa quota
     */
    public function ambilantrean(Request $request)
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof \Illuminate\Http\JsonResponse) {
            return $auth;
        }

        // Cek pasien
        $pasien = DB::table('rsmst_pasiens')
            ->select('reg_no', 'nokartu_bpjs', 'nik_bpjs')
            ->where('nokartu_bpjs', $request->nomorkartu)
            ->first();

        if (!$pasien) {
            return $this->sendError($request, "Nomor Kartu BPJS Pasien termasuk Pasien Baru di RSI Madinah. Silahkan daftar melalui pendaftaran offline", 201);
        }

        if ($pasien->nik_bpjs != $request->nik) {
            return $this->sendError($request, "NIK anda yang terdaftar di BPJS dengan Di RSI Madinah berbeda. Silahkan perbaiki melalui pendaftaran offline", 201);
        }

        // Pastikan norm sama dengan reg_no pasien
        if ($request->filled('norm')) {
            if ($request->norm != $pasien->reg_no) {
                $request->merge(['norm' => $pasien->reg_no]);
            }
        } else {
            if (!empty($pasien->reg_no)) {
                $request->merge(['norm' => $pasien->reg_no]);
            } else {
                return $this->sendError($request, "Nomor Rekam medis tidak ditemukan, silakan konfirmasi petugas untuk melakukan update data anda.", 201);
            }
        }

        $rules = [
            "nomorkartu"     => "required|numeric|digits:13",
            "nik"            => "required|numeric|digits:16",
            "nohp"           => "required",
            "kodepoli"       => "required",
            "norm"           => "required",
            "tanggalperiksa" => "required|date|date_format:Y-m-d",
            "kodedokter"     => "required",
            "jampraktek"     => "required",
            "jeniskunjungan" => "required|numeric|between:1,4",
        ];
        if ($request->jeniskunjungan != 2) {
            $rules['nomorreferensi'] = "required";
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->sendError($request, $validator->errors()->first(), 201);
        }

        // Validasi tanggal periksa
        if (Carbon::parse($request->tanggalperiksa)->endOfDay()->isPast()) {
            return $this->sendError($request, "Tanggal periksa sudah terlewat", 201);
        }

        // ── Toggle: blokir booking utk hari yang sama (minimal H+1) ───────
        // ANTRIAN_DISALLOW_SAMEDAY=true → tolak booking tanggal hari ini;
        // false → izinkan. Default true.
        $disallowSameDay = filter_var(env('ANTRIAN_DISALLOW_SAMEDAY', true), FILTER_VALIDATE_BOOLEAN);
        if ($disallowSameDay && Carbon::parse($request->tanggalperiksa)->isSameDay(Carbon::now(config('app.timezone')))) {
            return $this->sendError($request, "Booking untuk hari yang sama tidak diperbolehkan. Silakan booking minimal H+1 (besok).", 201);
        }

        // ── Setup batas hari pendaftaran ──────────────────────────────────
        $batasHari = 35;
        if (Carbon::parse($request->tanggalperiksa) > Carbon::now(config('app.timezone'))->addDays($batasHari)) {
            return $this->sendError($request, "Antrian hanya dapat dibuat untuk {$batasHari} hari ke depan", 201);
        }

        // Cek dokter dan poli
        $doctor = DB::table('rsmst_doctors')
            ->where('kd_dr_bpjs', $request->kodedokter)
            ->first();
        if (!$doctor) {
            return $this->sendError($request, "Dokter tidak ditemukan", 201);
        }

        $poli = DB::table('rsmst_polis')
            ->where('kd_poli_bpjs', $request->kodepoli)
            ->first();
        if (!$poli) {
            return $this->sendError($request, "Poli tidak ditemukan", 201);
        }

        $hari       = strtoupper($this->hariIndo(Carbon::parse($request->tanggalperiksa)->dayName));
        $jammulai   = substr($request->jampraktek, 0, 5);
        $jamselesai = substr($request->jampraktek, 6, 5);

        // Cek quota dan jadwal dokter-poli
        $cekQuota = DB::table('scview_scpolis')
            ->select('kuota', 'mulai_praktek', 'selesai_praktek', 'dr_id', 'poli_id', 'poli_desc', 'dr_name')
            ->where('kd_poli_bpjs', $request->kodepoli)
            ->where('kd_dr_bpjs', $request->kodedokter)
            ->where('day_desc', $hari)
            ->where('mulai_praktek', $jammulai . ':00')
            ->where('selesai_praktek', $jamselesai . ':00')
            ->first();
        if (!$cekQuota || !$cekQuota->kuota) {
            return $this->sendError($request, "Pendaftaran ke Poli " . $poli->poli_desc . " tanggal " . $request->tanggalperiksa . " tidak tersedia", 201);
        }

        $cekDaftar = DB::table('rsview_rjkasir')
            ->select('rj_no')
            ->where('kd_poli_bpjs', $request->kodepoli)
            ->where('kd_dr_bpjs', $request->kodedokter)
            ->where('rj_status', '!=', 'F')
            ->where(DB::raw("to_char(rj_date,'yyyy-mm-dd')"), '=', $request->tanggalperiksa)
            ->get();
        if (($cekQuota->kuota - $cekDaftar->count()) <= 0) {
            return $this->sendError($request, "Quota Poli " . $poli->poli_desc . " Dokter " . $doctor->dr_name . " tanggal " . $request->tanggalperiksa . " tidak tersedia", 201);
        }

        $noBooking = Carbon::now(config('app.timezone'))->format('YmdHis') . 'JKN';
        $lockKey = "lock:antrian:{$cekQuota->dr_id}:" . Carbon::parse($request->tanggalperiksa)->format('Ymd');

        try {
            $response = Cache::lock($lockKey, 15)->block(5, function () use ($request, $cekQuota, $cekDaftar, $noBooking, $jammulai) {
                return DB::transaction(function () use ($request, $cekQuota, $cekDaftar, $noBooking, $jammulai) {

                    // ── Cek duplikasi di dalam lock (cegah race condition) ──
                    // Cek 1: Pasien dengan NIK sama sudah daftar di tanggal yang sama?
                    $antrian_nik = DB::table('referensi_mobilejkn_bpjs')
                        ->where('tanggalperiksa', $request->tanggalperiksa)
                        ->where('nik', $request->nik)
                        ->where('status', '!=', 'Batal')
                        ->first();
                    if ($antrian_nik) {
                        throw new Exception("Terdapat Antrian (" . $antrian_nik->nobooking . ") dengan nomor NIK yang sama pada tanggal tersebut yang belum selesai. Silahkan batalkan terlebih dahulu jika ingin mendaftarkan lagi.");
                    }

                    // Cek 2: nobooking sudah ada? (idempotency — cegah insert ganda)
                    $existingBooking = DB::table('referensi_mobilejkn_bpjs')
                        ->where('nobooking', $noBooking)
                        ->exists();
                    if ($existingBooking) {
                        throw new Exception("Booking " . $noBooking . " sudah terdaftar. Silahkan coba beberapa saat lagi.");
                    }

                    // Hitung nomor antrian di dalam lock (cegah race condition)
                    // Note: referensi_mobilejkn_bpjs.angkaantrean bertipe VARCHAR2,
                    // jadi pakai to_number agar max() numeric (bukan lex sort).
                    $maxAntrianRjhdrs = (int) DB::table('rstxn_rjhdrs')
                        ->where('dr_id', $cekQuota->dr_id)
                        ->where('poli_id', $cekQuota->poli_id)
                        ->whereRaw("to_char(rj_date, 'ddmmyyyy') = ?", [
                            Carbon::createFromFormat('Y-m-d', $request->tanggalperiksa, config('app.timezone'))->format('dmY')
                        ])
                        ->where('klaim_id', '!=', 'KR')
                        ->max('no_antrian');

                    $maxAntrianBooking = (int) DB::table('referensi_mobilejkn_bpjs')
                        ->where('kodedokter', $request->kodedokter)
                        ->where('tanggalperiksa', $request->tanggalperiksa)
                        ->selectRaw("nvl(max(to_number(angkaantrean)), 0) as maxq")
                        ->value('maxq');

                    $noAntrian = max($maxAntrianRjhdrs, $maxAntrianBooking) + 1;

                    $tanggalperiksaFull = $request->tanggalperiksa . ' ' . $jammulai . ':00';
                    $jadwalEstimasiTimestamp = Carbon::createFromFormat('Y-m-d H:i:s', $tanggalperiksaFull, config('app.timezone'))
                        ->addMinutes(10 * ($noAntrian + 1))
                        ->timestamp * 1000;

                    DB::table('referensi_mobilejkn_bpjs')->insert([
                        "nobooking"         => $noBooking,
                        "no_rawat"          => $noBooking,
                        "nomorkartu"        => $request->nomorkartu,
                        "nik"               => $request->nik,
                        "nohp"              => $request->nohp,
                        "kodepoli"          => $request->kodepoli,
                        "pasienbaru"        => 0,
                        "norm"              => strtoupper($request->norm),
                        "tanggalperiksa"    => $request->tanggalperiksa,
                        "kodedokter"        => $request->kodedokter,
                        "jampraktek"        => $request->jampraktek,
                        "jeniskunjungan"    => $request->jeniskunjungan,
                        "nomorreferensi"    => $request->nomorreferensi ?? null,
                        "nomorantrean"      => $request->kodepoli . '-' . $noAntrian,
                        "angkaantrean"      => $noAntrian,
                        "estimasidilayani"  => $jadwalEstimasiTimestamp,
                        "sisakuotajkn"      => $cekQuota->kuota - $cekDaftar->count(),
                        "kuotajkn"          => $cekQuota->kuota,
                        "sisakuotanonjkn"   => $cekQuota->kuota - $cekDaftar->count(),
                        "kuotanonjkn"       => $cekQuota->kuota,
                        "status"            => "Belum",
                        "validasi"          => "",
                        "statuskirim"       => "Belum",
                        "keterangan_batal"  => "",
                        "tanggalbooking"    => Carbon::now(config('app.timezone'))->format('Y-m-d H:i:s'),
                        "daftardariapp"     => "JKNMobileAPP",
                    ]);

                    return [
                        "nomorantrean"     => $request->kodepoli . '-' . $noAntrian,
                        "angkaantrean"     => $noAntrian,
                        "kodebooking"      => $noBooking,
                        "norm"             => $request->norm,
                        "namapoli"         => $cekQuota->poli_desc,
                        "namadokter"       => $cekQuota->dr_name,
                        "estimasidilayani" => $jadwalEstimasiTimestamp,
                        "sisakuotajkn"     => $cekQuota->kuota - $cekDaftar->count(),
                        "kuotajkn"         => $cekQuota->kuota,
                        "sisakuotanonjkn"  => $cekQuota->kuota - $cekDaftar->count(),
                        "kuotanonjkn"      => $cekQuota->kuota,
                        "keterangan"       => 'Peserta harap 60 menit lebih awal guna pencatatan administrasi',
                    ];
                });
            });

            return $this->sendResponse($request, $response, 200);
        } catch (Exception $e) {
            return $this->sendError($request, $e->getMessage(), 201);
        }
    }


    /**
     * Endpoint checkin antrean.
     *
     * Proses:
     * 1. Autentikasi user via header x-username & x-token
     * 2. Validasi input: kodebooking & waktu (timestamp milidetik)
     * 3. Cari data antrian di referensi_mobilejkn_bpjs berdasarkan nobooking
     * 4. Validasi status antrian:
     *    - Tanggal periksa harus hari ini
     *    - Status tidak boleh 'Batal'
     *    - Status tidak boleh sudah 'Checkin' (duplikasi)
     * 5. Validasi waktu checkin:
     *    - Parse jampraktek (format "HH:mm-HH:mm") untuk batas waktu
     *    - Konversi timestamp waktu checkin ke Carbon
     *    - Checkin diizinkan: 1 jam sebelum mulai s/d waktu selesai pelayanan
     * 6. Cek jadwal dokter-poli di scview_scpolis (memastikan jadwal masih berlaku)
     *    Catatan: Quota TIDAK dicek ulang karena sudah divalidasi saat booking.
     * 7. Buat record rawat jalan di rstxn_rjhdrs:
     *    - Generate rj_no baru (MAX+1)
     *    - Pakai angkaantrean dari booking (konsisten)
     *    - rj_date = waktu realtime checkin (bukan jadwal dokter)
     *    - Tentukan shift dari rstxn_shiftctls berdasarkan jam checkin
     *    - Set status awal: txn_status='A', rj_status='A', erm_status='A', klaim_id='JM'
     * 8. Update status antrian di referensi_mobilejkn_bpjs menjadi 'Checkin'
     * 9. Push data ke BPJS Antrol:
     *    - Tambah antrean ke BPJS (tambah_antrean)
     *    - Update task ID 3 (checkin) dengan waktu realtime
     * 10. Simpan data daftar poli RJ (datadaftarpolirj_json):
     *     - Catat hasil push BPJS, waktu checkin task 3, referensi, dan jenis kunjungan
     */
    public function checkinantrean(Request $request)
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof \Illuminate\Http\JsonResponse) {
            return $auth;
        }

        $validator = Validator::make($request->all(), [
            "kodebooking" => "required",
            "waktu"       => "required",
        ]);

        if ($validator->fails()) {
            return $this->sendError($request, $validator->errors()->first(), 201);
        }

        $antrian = DB::table('referensi_mobilejkn_bpjs')
            ->where('nobooking', $request->kodebooking)
            ->first();
        if (!$antrian) {
            return $this->sendError($request, "No Booking (" . $request->kodebooking . ") invalid.", 201);
        }
        if (!Carbon::parse($antrian->tanggalperiksa)->isToday()) {
            return $this->sendError($request, "Tanggal periksa bukan hari ini, tetapi tgl " . $antrian->tanggalperiksa, 201);
        }
        if ($antrian->status == 'Batal') {
            return $this->sendError($request, "Antrian telah dibatalkan sebelumnya.", 201);
        }
        if ($antrian->status == 'Checkin') {
            return $this->sendError($request, "Anda Sudah Checkin pada " . $antrian->validasi, 201);
        }

        // cek waktucheckin
        // Misalnya, kolom jampraktek berisi "17:00-20:00"
        $jamPraktek = $antrian->jampraktek;

        // Pisahkan waktu mulai dan waktu selesai dengan menggunakan explode
        list($jammulai, $jamselesai) = explode('-', $jamPraktek);

        // Buat format tanggal lengkap untuk waktu mulai dan selesai
        $tanggalperiksaMulai  = $antrian->tanggalperiksa . ' ' . trim($jammulai) . ':00';
        $tanggalperiksaSelesai = $antrian->tanggalperiksa . ' ' . trim($jamselesai) . ':00';

        // Konversi waktu checkin dari timestamp (dalam milidetik) menjadi objek Carbon
        $waktuCheckin = Carbon::createFromTimestamp($request->waktu / 1000)
            ->timezone(config('app.timezone'));

        // Hitung batas waktu checkin:
        // Batas awal: 1 jam sebelum waktu mulai pelayanan subHour();
        $startCheckinAllowed = Carbon::createFromFormat('Y-m-d H:i:s', $tanggalperiksaMulai, config('app.timezone'))
            ->subHour();
        // Batas akhir: waktu selesai pelayanan
        $endCheckinAllowed = Carbon::createFromFormat('Y-m-d H:i:s', $tanggalperiksaSelesai, config('app.timezone'));

        // Validasi: waktu checkin tidak boleh lebih awal dari batas awal
        // lt() adalah kependekan dari less than (kurang dari)
        if ($waktuCheckin->lt($startCheckinAllowed)) {
            return $this->sendError(
                $request,
                "Lakukan checkin minimal 1 jam sebelum pelayanan. Pelayanan dimulai pada: " . $tanggalperiksaMulai,
                201
            );
        }

        // Validasi: waktu checkin tidak boleh melebihi waktu selesai pelayanan
        // gt() adalah kependekan dari greater than (lebih dari)
        if ($waktuCheckin->gt($endCheckinAllowed)) {
            return $this->sendError(
                $request,
                "Checkin sudah expired karena pelayanan telah berakhir pada: " . $tanggalperiksaSelesai,
                201
            );
        }


        $hari = strtoupper($this->hariIndo(Carbon::parse($tanggalperiksaMulai)->dayName));
        $cekQuota = DB::table('scview_scpolis')
            ->select('kuota', 'mulai_praktek', 'selesai_praktek', 'poli_id', 'dr_id', 'poli_desc', 'dr_name', 'shift')
            ->where('kd_poli_bpjs', $antrian->kodepoli)
            ->where('kd_dr_bpjs', $antrian->kodedokter)
            ->where('day_desc', $hari)
            ->where('mulai_praktek', $jammulai . ':00')
            ->where('selesai_praktek', substr($antrian->jampraktek, 6, 5) . ':00')
            ->first();
        if (!$cekQuota) {
            return $this->sendError($request, "Ada perubahan jadwal pelayanan, jadwal Dokter di Poli tersebut tidak ditemukan.", 201);
        }

        $cekDaftar = DB::table('rsview_rjkasir')
            ->select('rj_no')
            ->where('kd_poli_bpjs', $antrian->kodepoli)
            ->where('kd_dr_bpjs', $antrian->kodedokter)
            ->where('rj_status', '!=', 'F')
            ->where(DB::raw("to_char(rj_date,'yyyy-mm-dd')"), '=', $antrian->tanggalperiksa)
            ->get();
        // Skip quota check: pasien sudah lolos validasi quota saat booking,
        // jadi tidak boleh ditolak saat checkin meskipun slot terisi oleh pendaftaran admin/loket.

        try {
            $rjNo = DB::table('rstxn_rjhdrs')
                ->select(DB::raw("nvl(max(rj_no) + 1, 1) as rjno_max"))
                ->value('rjno_max');

            // Pakai angkaantrean yang sudah ditetapkan saat booking (konsisten dengan admin checkin)
            $noAntrian = (int) $antrian->angkaantrean;

            // rj_date = waktu realtime checkin (bukan jadwal dokter)
            // Alasan: jika dokter datang lebih awal dan mulai pelayanan,
            // task 3 (checkin) harus mencerminkan waktu sebenarnya agar
            // tidak terjadi anomali task 4 < task 3 di BPJS
            $rjDateStr = $waktuCheckin->format('Y-m-d H:i:s');

            // Shift dari rstxn_shiftctls berdasarkan jam checkin realtime
            $shiftRow = DB::table('rstxn_shiftctls')
                ->whereRaw("? BETWEEN shift_start AND shift_end", [$waktuCheckin->format('H:i:s')])
                ->first();
            $shift = (string) ($shiftRow->shift ?? $cekQuota->shift);

            // Admin prices dari master dokter — checkin BPJS Antrol selalu klaim 'JM' (BPJS)
            // + pass_status 'O', jadi pakai poli_price_bpjs & rj_admin = 0. Konsisten dengan
            // recomputeAdminPrices() di sirus-php82/daftar-rj-actions agar DB header tidak NULL
            // saat checkin via mobile JKN.
            $dokter = DB::table('rsmst_doctors')
                ->select('rs_admin', 'poli_price_bpjs')
                ->where('dr_id', $cekQuota->dr_id)
                ->first();

            DB::table('rstxn_rjhdrs')->insert([
                'rj_no'                => $rjNo,
                'rj_date'              => DB::raw("to_date('" . $rjDateStr . "', 'yyyy-mm-dd hh24:mi:ss')"),
                'reg_no'               => strtoupper($antrian->norm),
                'nobooking'            => $request->kodebooking,
                'no_antrian'           => $noAntrian,
                'klaim_id'             => 'JM',
                'poli_id'              => $cekQuota->poli_id,
                'dr_id'                => $cekQuota->dr_id,
                'shift'                => $shift,
                'txn_status'           => 'A',
                'rj_status'            => 'A',
                'erm_status'           => 'A',
                'pass_status'          => 'O',
                'cek_lab'              => '0',
                'sl_codefrom'          => '02',
                'kunjungan_internal_status' => $antrian->jeniskunjungan == 2 ? '1' : '0',
                'waktu_masuk_pelayanan' => DB::raw("to_date('" . $rjDateStr . "', 'yyyy-mm-dd hh24:mi:ss')"),
                'rs_admin'             => (int) ($dokter->rs_admin ?? 0),
                'rj_admin'             => 0, // pass_status 'O' → tidak charge admin OB
                'poli_price'           => (int) ($dokter->poli_price_bpjs ?? 0),
            ]);
        } catch (Exception $e) {
            return $this->sendError($request, $e->getMessage(), 201);
        }

        try {
            DB::table('referensi_mobilejkn_bpjs')
                ->where('nobooking', $request->kodebooking)
                ->update([
                    'status'   => 'Checkin',
                    'validasi' => Carbon::now(config('app.timezone'))->format('Y-m-d H:i:s')
                ]);

            // Siapkan data untuk push ke antrean BPJS
            $myAntreanadd = [
                "kodebooking"   => $request->kodebooking,
                "jenispasien"   => 'JKN',
                "nomorkartu"    => $antrian->nomorkartu,
                "nik"           => $antrian->nik,
                "nohp"          => $antrian->nohp,
                "kodepoli"      => $antrian->kodepoli,
                "namapoli"      => $cekQuota->poli_desc,
                "pasienbaru"    => 0,
                "norm"          => $antrian->norm,
                "tanggalperiksa" => $antrian->tanggalperiksa,
                "kodedokter"    => $antrian->kodedokter,
                "namadokter"    => $cekQuota->dr_name,
                "jampraktek"    => $jammulai . '-' . substr($antrian->jampraktek, 6, 5),
                "jeniskunjungan" => $antrian->jeniskunjungan,
                "nomorreferensi" => $antrian->nomorreferensi,
                "nomorantrean"  => $antrian->kodepoli . '-' . $noAntrian,
                "angkaantrean"  => $noAntrian,
                "estimasidilayani" => $antrian->estimasidilayani,
                "sisakuotajkn"     => $cekQuota->kuota - $noAntrian,
                "kuotajkn"         => $cekQuota->kuota,
                "sisakuotanonjkn"  => $cekQuota->kuota - $noAntrian,
                "kuotanonjkn"      => $cekQuota->kuota,
                "keterangan"       => "Peserta harap 1 jam lebih awal guna pencatatan administrasi.",
            ];

            // ── 1. Tambah Antrean BPJS ──
            $antrianResult = $this->pushDataAntrian($myAntreanadd, $rjNo, $request->kodebooking, $request->waktu);

            // Task 1 & 2 tidak dipush di sini — itu untuk pendaftaran pasien baru
            // (regDate & regDateStore), dipush oleh daftar-rj-actions saat save

            // ── 2. Simpan datadaftarpolirj_json ──
            $dataDaftarPoliRJ = $this->findDataRJ($rjNo);
            $dataDaftarPoliRJ['taskIdPelayanan']['tambahPendaftaran'] = $antrianResult['tambahPendaftaran'];
            $dataDaftarPoliRJ['taskIdPelayanan']['taskId3'] = $waktuCheckin->format('d/m/Y H:i:s');
            $dataDaftarPoliRJ['taskIdPelayanan']['taskId3Status'] = $antrianResult['taskId3'];
            $dataDaftarPoliRJ['noReferensi'] = $antrian->nomorreferensi ?? '';
            $dataDaftarPoliRJ['kunjunganId'] = (string) $antrian->jeniskunjungan;
            $dataDaftarPoliRJ['kunjunganInternalStatus'] = $antrian->jeniskunjungan == 2 ? '1' : '0';
            $this->updateJsonRJ($rjNo, $dataDaftarPoliRJ);

            return $this->sendResponse($request, "OK Peserta harap 1 jam lebih awal guna pencatatan administrasi " . $request->kodebooking, 200);
        } catch (Exception $e) {
            return $this->sendError($request, $e->getMessage(), 201);
        }
    }

    /**
     * Endpoint batal antrean.
     *
     * Proses:
     * 1. Autentikasi user via header x-username & x-token
     * 2. Validasi input: kodebooking & keterangan (alasan pembatalan)
     * 3. Cari data antrian di referensi_mobilejkn_bpjs berdasarkan nobooking
     * 4. Validasi status antrian:
     *    - Tidak boleh sudah 'Batal' (duplikasi pembatalan)
     *    - Tidak boleh sudah 'Checkin' (sudah diproses, tidak bisa dibatalkan)
     * 5. Update status menjadi 'Batal' dengan timestamp pembatalan di keterangan_batal
     */
    public function batalantrean(Request $request)
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof \Illuminate\Http\JsonResponse) {
            return $auth;
        }

        $validator = Validator::make($request->all(), [
            "kodebooking" => "required",
            "keterangan"  => "required"
        ]);
        if ($validator->fails()) {
            return $this->sendError($request, $validator->errors()->first(), 201);
        }

        $antrian = DB::table('referensi_mobilejkn_bpjs')
            ->where('nobooking', $request->kodebooking)
            ->first();
        if (!$antrian) {
            return $this->sendError($request, "No Booking (" . $request->kodebooking . ") invalid.", 201);
        }
        if ($antrian->status == 'Batal') {
            return $this->sendError($request, "Antrian telah dibatalkan sebelumnya.", 201);
        }
        if ($antrian->status == 'Checkin') {
            return $this->sendError($request, "Pembatalan tidak bisa dilakukan, Anda Sudah Checkin pada " . $antrian->validasi, 201);
        }

        DB::table('referensi_mobilejkn_bpjs')
            ->where('nobooking', $request->kodebooking)
            ->update([
                'status'             => 'Batal',
                'keterangan_batal'   => Carbon::now(config('app.timezone'))->format('Y-m-d H:i:s')
            ]);

        return $this->sendResponse($request, "OK", 200);
    }



    /**
     * Endpoint status antrean.
     *
     * Proses:
     * 1. Autentikasi user via header x-username & x-token
     * 2. Validasi input: kodepoli, kodedokter, tanggalperiksa, jampraktek
     * 3. Validasi tanggal periksa: tidak boleh sudah lewat
     * 4. Cek keberadaan dokter (rsmst_doctors) dan poli (rsmst_polis)
     * 5. Cek quota dari scview_scpolis berdasarkan kode poli, dokter, dan hari
     * 6. Hitung jumlah pasien terdaftar dari rsview_rjkasir (rj_status != 'F')
     * 7. Tolak jika quota habis atau tidak tersedia
     * 8. Cari pasien yang sedang dilayani (sudah masuk poli tapi belum ke apotek)
     *    dari rsview_rjkasir, urut berdasarkan no_antrian ASC
     * 9. Return: namapoli, namadokter, totalantrean, sisaantrean, antreanpanggil,
     *    sisa quota JKN & non-JKN
     */
    public function statusantrean(Request $request)
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof \Illuminate\Http\JsonResponse) {
            return $auth;
        }

        $validator = Validator::make($request->all(), [
            "kodepoli"       => "required",
            "kodedokter"     => "required",
            "tanggalperiksa" => "required|date",
            "jampraktek"     => "required",
        ]);
        if ($validator->fails()) {
            return $this->sendError($request, $validator->errors()->first(), 201);
        }

        if (Carbon::parse($request->tanggalperiksa)->endOfDay()->isPast()) {
            return $this->sendError($request, "Tanggal periksa sudah terlewat", 201);
        }

        $doctor = DB::table('rsmst_doctors')
            ->where('kd_dr_bpjs', $request->kodedokter)
            ->get();
        if ($doctor->isEmpty()) {
            return $this->sendError($request, "Dokter tidak ditemukan", 201);
        }

        $poli = DB::table('rsmst_polis')
            ->where('kd_poli_bpjs', $request->kodepoli)
            ->get();
        if ($poli->isEmpty()) {
            return $this->sendError($request, "Poli tidak ditemukan", 201);
        }

        $hari = strtoupper($this->hariIndo(Carbon::parse($request->tanggalperiksa)->dayName));
        $cekQuota = DB::table('scview_scpolis')
            ->select('kuota', 'mulai_praktek', 'selesai_praktek', 'poli_id', 'dr_id', 'poli_desc', 'dr_name', 'shift')
            ->where('kd_poli_bpjs', $request->kodepoli)
            ->where('kd_dr_bpjs', $request->kodedokter)
            ->where('day_desc', $hari)
            ->first();

        $cekDaftar = DB::table('rsview_rjkasir')
            ->select('rj_no')
            ->where('kd_poli_bpjs', $request->kodepoli)
            ->where('kd_dr_bpjs', $request->kodedokter)
            ->where('rj_status', '!=', 'F')
            ->where(DB::raw("to_char(rj_date,'yyyy-mm-dd')"), '=', $request->tanggalperiksa)
            ->get();

        if (!$cekQuota || !$cekQuota->kuota || ($cekQuota->kuota - $cekDaftar->count()) == 0) {
            return $this->sendError($request, "Quota tidak tersedia", 201);
        }

        $queryPasienDilayani = DB::table('rsview_rjkasir')
            ->select(
                DB::raw("to_char(rj_date,'dd/mm/yyyy hh24:mi:ss') AS rj_date"),
                DB::raw("to_char(rj_date,'yyyymmddhh24miss') AS rj_date1"),
                'rj_no',
                'reg_no',
                'reg_name',
                'sex',
                'address',
                'thn',
                'poli_desc',
                'dr_name',
                'no_antrian',
                'waktu_masuk_poli',
                'waktu_masuk_apt'
            )
            ->where('rj_status', '=', 'A')
            ->where('klaim_id', '!=', 'KR')
            ->where(DB::raw("to_char(rj_date,'yyyy-mm-dd')"), $request->tanggalperiksa)
            ->where('kd_dr_bpjs', $request->kodedokter)
            ->whereNotNull('waktu_masuk_poli')
            ->whereNull('waktu_masuk_apt')
            ->orderBy('no_antrian', 'asc')
            ->orderBy(DB::raw("to_char(rj_date,'yyyymmddhh24miss')"), 'desc')
            ->first();

        $noAntrian      = $queryPasienDilayani->no_antrian ?? 0;
        $waktuMasukPoli = $queryPasienDilayani->waktu_masuk_poli ?? null;

        $response = [
            "namapoli"         => $cekQuota->poli_desc,
            "namadokter"       => $cekQuota->dr_name,
            "totalantrean"     => $cekDaftar->count(),
            "sisaantrean"      => $cekDaftar->count() - $noAntrian,
            "antreanpanggil"   => $waktuMasukPoli,
            "sisakuotajkn"     => $cekQuota->kuota - $noAntrian,
            "kuotajkn"         => $cekQuota->kuota,
            "sisakuotanonjkn"  => $cekQuota->kuota - $noAntrian,
            "kuotanonjkn"      => $cekQuota->kuota,
            "keterangan"       => "Informasi antrian poliklinik " . Carbon::now(config('app.timezone'))->format('Y-m-d H:i:s'),
        ];

        return $this->sendResponse($request, $response, 200);
    }


    /**
     * Endpoint sisa antrean.
     *
     * Proses:
     * 1. Autentikasi user via header x-username & x-token
     * 2. Validasi input: kodebooking
     * 3. Cari data antrian di referensi_mobilejkn_bpjs berdasarkan nobooking
     * 4. Validasi status antrian:
     *    - Tidak boleh 'Batal'
     *    - Harus sudah 'Checkin' (belum checkin = belum terdaftar di rawat jalan)
     * 5. Query data pasien RJ dari rsview_rjkasir berdasarkan nobooking
     *    untuk mendapatkan no_antrian, poli_desc, dr_name, waktu_masuk_poli
     * 6. Return: nomorantrean, namapoli, namadokter, sisaantrean, antreanpanggil,
     *    waktutunggu
     */
    public function sisaantrean(Request $request)
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof \Illuminate\Http\JsonResponse) {
            return $auth;
        }

        $validator = Validator::make($request->all(), [
            "kodebooking" => "required",
        ]);
        if ($validator->fails()) {
            return $this->sendError($request, $validator->errors()->first(), 201);
        }

        $antrian = DB::table('referensi_mobilejkn_bpjs')
            ->where('nobooking', $request->kodebooking)
            ->first();
        if (!$antrian) {
            return $this->sendError($request, "No Booking (" . $request->kodebooking . ") invalid.", 201);
        }
        if ($antrian->status == 'Batal') {
            return $this->sendError($request, "Antrian telah dibatalkan sebelumnya.", 201);
        }
        if ($antrian->status != 'Checkin') {
            return $this->sendError($request, "Status Belum Checkin " . $request->kodebooking, 201);
        }

        $queryPasienRJ = DB::table('rsview_rjkasir')
            ->select(
                DB::raw("to_char(rj_date,'dd/mm/yyyy hh24:mi:ss') AS rj_date"),
                DB::raw("to_char(rj_date,'yyyymmddhh24miss') AS rj_date1"),
                'rj_no',
                'reg_no',
                'reg_name',
                'sex',
                'address',
                'thn',
                'poli_desc',
                'dr_name',
                'no_antrian',
                'waktu_masuk_poli',
                'waktu_masuk_apt',
                'kd_poli_bpjs',
                'kd_dr_bpjs'
            )
            ->where('nobooking', $request->kodebooking)
            ->first();

        $noAntrian = $queryPasienRJ->no_antrian ?? 0;
        if (!$noAntrian) {
            return $this->sendError($request, "Data pasien tidak ditemukan " . $request->kodebooking, 201);
        }

        $waktuMasukPoli = $queryPasienRJ->waktu_masuk_poli ?? null;
        $response = [
            "nomorantrean" => $noAntrian,
            "namapoli"     => $queryPasienRJ->poli_desc ?? '',
            "namadokter"   => $queryPasienRJ->dr_name ?? '',
            "sisaantrean"  => ($queryPasienRJ->kuota ?? 0) - $noAntrian,
            "antreanpanggil" => $waktuMasukPoli,
            "waktutunggu"   => ($queryPasienRJ->kuota ?? 0) - $noAntrian,
            "keterangan"   => "Informasi antrian poliklinik " . Carbon::now(config('app.timezone'))->format('Y-m-d H:i:s'),
        ];

        return $this->sendResponse($request, $response, 200);
    }

    /**
     * Endpoint untuk pasien baru.
     *
     * Proses:
     * 1. Autentikasi user via header x-username & x-token
     * 2. Validasi input: kodebooking
     * 3. Selalu return error karena RS belum mendukung pendaftaran pasien baru via JKN Mobile.
     *    Pasien baru harus daftar offline terlebih dahulu untuk mendapatkan No RM.
     */
    public function pasienbaru(Request $request)
    {
        $auth = $this->authenticate($request);
        if ($auth instanceof \Illuminate\Http\JsonResponse) {
            return $auth;
        }

        $validator = Validator::make($request->all(), [
            "kodebooking" => "required",
        ]);
        if ($validator->fails()) {
            return $this->sendError($request, $validator->errors()->first(), 201);
        }

        return $this->sendError($request, "Anda belum memiliki No RM di RSI Madinah (Pasien Baru). Silahkan daftar secara offline.", 201);
    }

    /////////////////////////////
    // Push ke BPJS Antrol task ID
    /////////////////////////////

    /**
     * Metode private untuk push data antrean ke BPJS.
     *
     * Proses:
     * 1. Cek apakah antrean sudah pernah di-push ke BPJS (status 200/208 di rstxn_rjhdrs)
     * 2. Jika belum, push data antrean ke BPJS via tambah_antrean()
     *    dan simpan status & JSON response ke rstxn_rjhdrs
     * 3. Push task ID 3 (waktu checkin) ke BPJS via update_antrean()
     * 4. Return kode status tambahPendaftaran dan taskId3
     *
     * @return array{tambahPendaftaran: int|string, taskId3: int|string}
     */
    private function pushDataAntrian($myAntreanadd, $rjNo, $kodebooking, $waktu): array
    {
        $tambahCode = '';

        $cekAntrianBPJS = DB::table('rstxn_rjhdrs')
            ->select('push_antrian_bpjs_status', 'push_antrian_bpjs_json')
            ->where('rj_no', $rjNo)
            ->first();

        $statusBPJS = $cekAntrianBPJS->push_antrian_bpjs_status ?? "";
        if ($statusBPJS == 200 || $statusBPJS == 208) {
            $tambahCode = (int) $statusBPJS;
        } else {
            // Push tambah_antrean ke BPJS
            $response = $this->tambah_antrean($myAntreanadd)->getOriginalContent();
            $tambahCode = $response['metadata']['code'] ?? '';

            DB::table('rstxn_rjhdrs')
                ->where('rj_no', $rjNo)
                ->update([
                    'push_antrian_bpjs_status' => $tambahCode,
                    'push_antrian_bpjs_json'   => json_encode($myAntreanadd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ]);
        }

        $taskId3Code = $this->pushDataTaskId($kodebooking, 3, $waktu);

        return [
            'tambahPendaftaran' => $tambahCode,
            'taskId3' => $taskId3Code,
        ];
    }

    /**
     * Metode private untuk update task ID ke BPJS.
     *
     * Proses:
     * 1. Panggil update_antrean() dari AntrianTrait dengan parameter:
     *    noBooking, taskId (1-7), waktu (timestamp milidetik)
     * 2. Return kode response dari BPJS
     *
     * Task ID BPJS:
     * - 1: Mulai pendaftaran (regDate)
     * - 2: Selesai pendaftaran (regDateStore)
     * - 3: Checkin / masuk poli
     * - 4: Selesai pelayanan poli
     * - 5: Masuk farmasi/apotek
     * - 6: Selesai farmasi/apotek (obat selesai)
     * - 7: Selesai pelayanan (pasien pulang)
     */
    private function pushDataTaskId($noBooking, $taskId, $waktu): int|string
    {
        $response = $this->update_antrean($noBooking, $taskId, $waktu, "")->getOriginalContent();
        return $response['metadata']['code'] ?? '';
    }

    /**
     * Metode debugging (tidak untuk produksi).
     * Digunakan untuk testing flow checkin secara manual tanpa proses insert RJ.
     */
    public function x(Request $request)
    {
        // $timestampMillis = Carbon::now()->valueOf();
        // return ($timestampMillis);

        $validator = Validator::make($request->all(), [
            "kodebooking" => "required",
            "waktu"       => "required",
        ]);
        if ($validator->fails()) {
            return $this->sendError($request, $validator->errors()->first(), 201);
        }

        $antrian = DB::table('referensi_mobilejkn_bpjs')
            ->where('nobooking', $request->kodebooking)
            ->first();
        if (!$antrian) {
            return $this->sendError($request, "No Booking (" . $request->kodebooking . ") invalid.", 201);
        }
        if (!Carbon::parse($antrian->tanggalperiksa)->isToday()) {
            return $this->sendError($request, "Tanggal periksa bukan hari ini, tetapi tgl " . $antrian->tanggalperiksa, 201);
        }
        if ($antrian->status == 'Batal') {
            return $this->sendError($request, "Antrian telah dibatalkan sebelumnya.", 201);
        }
        if ($antrian->status == 'Checkin') {
            return $this->sendError($request, "Anda Sudah Checkin pada " . $antrian->validasi, 201);
        }
        // echo "Difference in hours: " . $hoursDifference;


        $validator = Validator::make($request->all(), [
            "kodebooking" => "required",
            "waktu"       => "required",
        ]);
        if ($validator->fails()) {
            return $this->sendError($request, $validator->errors()->first(), 201);
        }
        return ('x');
        return ('y');

        $antrian = DB::table('referensi_mobilejkn_bpjs')
            ->where('nobooking', $request->kodebooking)
            ->first();
        if (!$antrian) {
            return $this->sendError($request, "No Booking (" . $request->kodebooking . ") invalid.", 201);
        }
        if (!Carbon::parse($antrian->tanggalperiksa)->isToday()) {
            return $this->sendError($request, "Tanggal periksa bukan hari ini, tetapi tgl " . $antrian->tanggalperiksa, 201);
        }
        if ($antrian->status == 'Batal') {
            return $this->sendError($request, "Antrian telah dibatalkan sebelumnya.", 201);
        }
        if ($antrian->status == 'Checkin') {
            return $this->sendError($request, "Anda Sudah Checkin pada " . $antrian->validasi, 201);
        }
        // $noAntrian = 10;
        // $jammulai = '11:00';
        // $tanggalperiksa = $request->tanggalperiksa . ' ' . $jammulai . ':00';
        // $jadwalEstimasiTimestamp = Carbon::createFromFormat('Y-m-d H:i:s', $tanggalperiksa, 'Asia/Jakarta')->addMinutes(10 * ($noAntrian + 1))->timestamp * 1000;

        // $date = Carbon::createFromTimestamp($jadwalEstimasiTimestamp / 1000)->toDateTimeString();
        // return $tanggalperiksa . '  ' . $date . '  ' . $jadwalEstimasiTimestamp;
    }
}


    /////////////
    // API SIMRS
    /////////////
