<?php

namespace App\Http\Traits\BPJS;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RealRashid\SweetAlert\Facades\Alert;

use App\Http\Traits\customErrorMessagesTrait;
use Illuminate\Support\Facades\DB;


use Exception;

trait VclaimTrait
{
    public static function sendResponse($message, $data, $code = 200, $url = null, $requestTransferTime = null, $payload = null)
    {
        $response = [
            'response' => $data,
            'metadata' => [
                'message' => $message,
                'code' => $code,
            ],
        ];

        // Insert webLogStatus
        DB::table('web_log_status')->insert([
            'code' =>  $code,
            'date_ref' => Carbon::now(env('APP_TIMEZONE')),
            'response' => json_encode($response, true),
            'http_req' => $url,
            'http_payload' => $payload,
            'requestTransferTime' => $requestTransferTime
        ]);

        return response()->json($response, $code);
    }
    public static function sendError($error, $errorMessages = [], $code = 404, $url = null, $requestTransferTime = null, $payload = null)
    {
        $response = [
            'metadata' => [
                'message' => $error,
                'code' => $code,
            ],
        ];
        if (!empty($errorMessages)) {
            $response['response'] = $errorMessages;
        }
        // Insert webLogStatus
        DB::table('web_log_status')->insert([
            'code' =>  $code,
            'date_ref' => Carbon::now(env('APP_TIMEZONE')),
            'response' => json_encode($response, true),
            'http_req' => $url,
            'http_payload' => $payload,
            'requestTransferTime' => $requestTransferTime
        ]);

        return response()->json($response, $code);
    }

    public static function referensi_index(Request $request)
    {
        return view('bpjs.vclaim.referensi_index', compact([
            'request',
        ]));
    }
    public static function ref_diagnosa_api(Request $request)
    {
        $data = array();
        $response = self::ref_diagnosa($request);
        if ($response->status() == 200) {
            $diagnosa = $response->getData()->response->diagnosa;
            foreach ($diagnosa as $item) {
                $data[] = array(
                    "id" => $item->kode,
                    "text" => $item->nama
                );
            }
        }
        return response()->json($data);
    }
    public static function ref_poliklinik_api(Request $request)
    {
        $data = array();
        $response = self::ref_poliklinik($request);
        if ($response->status() == 200) {
            $poli = $response->getData()->response->poli;
            foreach ($poli as $item) {
                $data[] = array(
                    "id" => $item->kode,
                    "text" => $item->nama . " (" . $item->kode . ")"
                );
            }
        }
        return response()->json($data);
    }
    public static function ref_faskes_api(Request $request)
    {
        $data = array();
        $response = self::ref_faskes($request);
        if ($response->status() == 200) {
            $faskes = $response->getData()->response->faskes;
            foreach ($faskes as $item) {
                $data[] = array(
                    "id" => $item->kode,
                    "text" => $item->nama . " (" . $item->kode . ")"
                );
            }
        }
        return response()->json($data);
    }
    public static function ref_dpjp_api(Request $request)
    {
        $data = array();
        $response = self::ref_dpjp($request);
        if ($response->status() == 200) {
            $dokter = $response->getData()->response->list;
            foreach ($dokter as $item) {
                if ((strpos(strtoupper($item->nama), strtoupper($request->nama)) !== false)) {
                    $data[] = array(
                        "id" => $item->kode,
                        "text" => $item->nama . " (" . $item->kode . ")"
                    );
                }
            }
        }
        return response()->json($data);
    }
    public static function ref_provinsi_api(Request $request)
    {
        $data = array();
        $response = self::ref_provinsi($request);
        if ($response->status() == 200) {
            $provinsi = $response->getData()->response->list;
            foreach ($provinsi as $item) {
                if ((strpos(strtoupper($item->nama), strtoupper($request->nama)) !== false)) {
                    $data[] = array(
                        "id" => $item->kode,
                        "text" => $item->nama . " (" . $item->kode . ")"
                    );
                }
            }
        }
        return response()->json($data);
    }
    public static function ref_kabupaten_api(Request $request)
    {
        $data = array();
        $response = self::ref_kabupaten($request);
        if ($response->status() == 200) {
            $kabupaten = $response->getData()->response->list;
            foreach ($kabupaten as $item) {
                if ((strpos(strtoupper($item->nama), strtoupper($request->nama)) !== false)) {
                    $data[] = array(
                        "id" => $item->kode,
                        "text" => $item->nama . " (" . $item->kode . ")"
                    );
                }
            }
        }
        return response()->json($data);
    }
    public static function ref_kecamatan_api(Request $request)
    {
        $data = array();
        $response = self::ref_kecamatan($request);
        if ($response->status() == 200) {
            $kecamatan = $response->getData()->response->list;
            foreach ($kecamatan as $item) {
                if ((strpos(strtoupper($item->nama), strtoupper($request->nama)) !== false)) {
                    $data[] = array(
                        "id" => $item->kode,
                        "text" => $item->nama . " (" . $item->kode . ")"
                    );
                }
            }
        }
        return response()->json($data);
    }
    public static function surat_kontrol_index(Request $request)
    {
        $suratkontrol = null;
        if ($request->tanggal && $request->formatFilter) {
            $tanggal = explode('-', $request->tanggal);
            $request['tanggalMulai'] = Carbon::parse($tanggal[0])->format('Y-m-d');
            $request['tanggalAkhir'] = Carbon::parse($tanggal[1])->format('Y-m-d');
            $response =  self::suratkontrol_tanggal($request);
            if ($response->status() == 200) {
                $suratkontrol = $response->getData()->response->list;
                Alert::success($response->getData()->metadata->message, 'Total Data Kunjungan BPJS ' . count($suratkontrol) . ' Pasien');
            } else {
                Alert::error('Error ' . $response->status(), $response->getData()->metadata->message);
            }
        }
        if ($request->nomorKartu) {
            $bulan = explode('-', $request->bulan);
            $request['tahun'] = $bulan[0];
            $request['bulan'] = $bulan[1];
            $response =  self::suratkontrol_peserta($request);
            if ($response->status() == 200) {
                $suratkontrol = $response->getData()->response->list;
                Alert::success($response->getData()->metadata->message, 'Total Data Kunjungan BPJS ' . count($suratkontrol) . ' Pasien');
            } else {
                Alert::error('Error ' . $response->status(), $response->getData()->metadata->message);
            }
        }
        return view('bpjs.vclaim.surat_kontrol_index', compact([
            'request',
            'suratkontrol'
        ]));
    }
    // API VCLAIM
    public static function signature()
    {
        $cons_id =  env('VCLAIM_CONS_ID');
        $secretKey = env('VCLAIM_SECRET_KEY');
        $userkey = env('VCLAIM_USER_KEY');

        date_default_timezone_set('UTC');
        $tStamp = strval(time() - strtotime('1970-01-01 00:00:00'));
        $signature = hash_hmac('sha256', $cons_id . "&" . $tStamp, $secretKey, true);
        $encodedSignature = base64_encode($signature);

        $response = array(
            'user_key' => $userkey,
            'x-cons-id' => $cons_id,
            'x-timestamp' => $tStamp,
            'x-signature' => $encodedSignature,
            'decrypt_key' => $cons_id . $secretKey . $tStamp,
        );
        return $response;
    }
    public static function stringDecrypt($key, $string)
    {
        $encrypt_method = 'AES-256-CBC';
        $key_hash = hex2bin(hash('sha256', $key));
        $iv = substr(hex2bin(hash('sha256', $key)), 0, 16);
        $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key_hash, OPENSSL_RAW_DATA, $iv);
        $output = \LZCompressor\LZString::decompressFromEncodedURIComponent($output);
        return $output;
    }
    public static function response_decrypt($response, $signature, $url, $requestTransferTime)
    {
        // Sniff request body dari Guzzle (di-set Laravel HTTP client via transferStats).
        $payload = $response->transferStats?->getRequest()?->getBody()?->__toString();

        if ($response->failed()) {
            return self::sendError($response->reason(),  $response->json('response'), $response->status(), $url, $requestTransferTime, $payload);
        } else {
            // Check Response !200           -> metaData D besar
            $code = $response->json('metaData.code'); //code 200 -201 500 dll

            if ($code == 200) {
                $decrypt = self::stringDecrypt($signature['decrypt_key'], $response->json('response'));
                $data = json_decode($decrypt, true);
            } else {

                $data = json_decode($response, true);
            }

            return self::sendResponse($response->json('metaData.message'), $data, $code, $url, $requestTransferTime, $payload);
        }
    }
    public static function response_no_decrypt($response)
    {
        if ($response->failed()) {
            return self::sendError($response->reason(),  $response->json('response'), $response->status(), null, null);
        } else {
            return self::sendResponse($response->json('metaData.message'), $response->json('response'), $response->json('metaData.code'), null, null);
        }
    }
    // MONITORING
    public static function monitoring_data_kunjungan(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "tanggal" => "required|date",
            "jenisPelayanan" => "required",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 201, null, null);
        }
        $url = env('VCLAIM_URL') . "Monitoring/Kunjungan/Tanggal/" . $request->tanggal . "/JnsPelayanan/" . $request->jenisPelayanan;
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }
    public static function monitoring_data_klaim(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "tanggalPulang" => "required|date",
            "jenisPelayanan" => "required",
            "statusKlaim" => "required",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 201, null, null);
        }
        $url = env('VCLAIM_URL') . "Monitoring/Klaim/Tanggal/" . $request->tanggalPulang . "/JnsPelayanan/" . $request->jenisPelayanan . "/Status/" . $request->statusKlaim;
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }
    public static function monitoring_pelayanan_peserta(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "nomorKartu" => "required",
            "tanggalMulai" => "required|date",
            "tanggalAkhir" => "required|date",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 201, null, null);
        }
        $url = env('VCLAIM_URL') . "monitoring/HistoriPelayanan/NoKartu/" . $request->nomorKartu . "/tglMulai/" . $request->tanggalMulai . "/tglAkhir/" . $request->tanggalAkhir;
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }
    public static function monitoring_klaim_jasaraharja(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "jenisPelayanan" => "required",
            "tanggalMulai" => "required|date",
            "tanggalAkhir" => "required|date",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 201, null, null);
        }
        $url = env('VCLAIM_URL') . "monitoring/JasaRaharja/JnsPelayanan/" . $request->jenisPelayanan . "/tglMulai/" . $request->tanggalMulai . "/tglAkhir/" . $request->tanggalAkhir;
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }
    // PESERTA
    public static function peserta_nomorkartu($nomorKartu, $tanggal)
    {
        // customErrorMessages
        // $messages = customErrorMessagesTrait::messages();
        $messages = [];

        // Masukkan Nilai dari parameter

        $r = [
            'nomorKartu' => $nomorKartu,
            "tanggal" => $tanggal,

        ];

        $validator = Validator::make($r, [
            "nomorKartu" => "required|digits:13",
            "tanggal" => "required|date",
        ], $messages);


        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 400, null, null);
        }

        // handler when time out and off line mode
        try {

            $url = env('VCLAIM_URL') . "Peserta/nokartu/" . $nomorKartu . "/tglSEP/" . $tanggal;

            $signature = self::signature();
            $response = Http::timeout(10)
                ->withHeaders($signature)
                ->get($url);


            // dd($response->transferStats->getTransferTime()); Get Transfertime request
            // semua response error atau sukses dari BPJS di handle pada logic response_decrypt
            return self::response_decrypt($response, $signature, $url, $response->transferStats->getTransferTime());
            /////////////////////////////////////////////////////////////////////////////
        } catch (Exception $e) {
            return self::sendError($e->getMessage(), $validator->errors(), 408, $url, null);
        }
    }


    public static function peserta_nik($nik, $tanggal)
    {
        // customErrorMessages
        $messages = customErrorMessagesTrait::messages();

        // Masukkan Nilai dari parameter
        $r = [
            'nik' => $nik,
            'tanggal' => $tanggal,
        ];
        // lakukan validasis
        $validator = Validator::make($r, [
            "nik" => "required|digits:16",
            "tanggal" => "required|date",
        ], $messages);

        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), $validator->errors(), 201, null, null);
        }



        // handler when time out and off line mode
        try {

            $url = env('VCLAIM_URL') . "Peserta/nik/" . $nik . "/tglSEP/" . $tanggal;
            $signature = self::signature();
            $response = Http::timeout(10)
                ->withHeaders($signature)
                ->get($url);


            // dd($response->transferStats->getTransferTime()); Get Transfertime request
            // semua response error atau sukses dari BPJS di handle pada logic response_decrypt
            return self::response_decrypt($response, $signature, $url, $response->transferStats->getTransferTime());
            /////////////////////////////////////////////////////////////////////////////
        } catch (Exception $e) {
            return self::sendError($e->getMessage(), $validator->errors(), 408, $url, null);
        }
    }



    // REFERENSI
    public static function ref_diagnosa(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "diagnosa" => "required",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 201, null, null);
        }
        $url = env('VCLAIM_URL') . "referensi/diagnosa/" . $request->diagnosa;
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }















    public static function ref_poliklinik($poliklinik)
    {

        $messages = customErrorMessagesTrait::messages();
        $r = ['poliklinik' => $poliklinik];
        $validator = Validator::make($r, ["poliklinik" => "required"], $messages);

        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), $validator->errors(), 201, null, null);
        }

        // handler when time out and off line mode
        try {

            $url = env('VCLAIM_URL') . "referensi/poli/" . $poliklinik;
            $signature = self::signature();
            $response = Http::timeout(10)
                ->withHeaders($signature)
                ->get($url);

            return self::response_decrypt($response, $signature, $url, $response->transferStats->getTransferTime());
            /////////////////////////////////////////////////////////////////////////////
        } catch (Exception $e) {
            return self::sendError($e->getMessage(), $validator->errors(), 408, $url, null);
        }
    }
















    public static function ref_faskes(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "nama" => "required",
            "jenisFaskes" => "required",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 201, null, null);
        }
        $url = env('VCLAIM_URL') . "referensi/faskes/" . $request->nama . "/" . $request->jenisFaskes;
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }
    public static function ref_dpjp(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "jenisPelayanan" => "required",
            "tanggal" => "required|date",
            "kodespesialis" => "required",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 201, null, null);
        }
        $url = env('VCLAIM_URL') . "referensi/dokter/pelayanan/" . $request->jenisPelayanan . "/tglPelayanan/" . $request->tanggal . "/Spesialis/" . $request->kodespesialis;
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }
    public static function ref_provinsi(Request $request)
    {
        $url = env('VCLAIM_URL') . "referensi/propinsi";
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }
    public static function ref_kabupaten(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "kodeprovinsi" => "required",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 201, null, null);
        }
        $url = env('VCLAIM_URL') . "referensi/kabupaten/propinsi/" . $request->kodeprovinsi;
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }
    public static function ref_kecamatan(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "kodekabupaten" => "required",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 201, null, null);
        }
        $url = env('VCLAIM_URL') . "referensi/kecamatan/kabupaten/" . $request->kodekabupaten;
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }



    // RENCANA KONTROL
    public static function suratkontrol_insert($kontrol)
    {

        // customErrorMessages
        // $messages = customErrorMessagesTrait::messages();
        $messages = [];
        // Masukkan Nilai dari parameter
        $r = [
            "request" => [
                "noSEP" => $kontrol['noSEP'],
                "tglRencanaKontrol" => Carbon::createFromFormat('d/m/Y', $kontrol['tglKontrol'])->format('Y-m-d'),
                "poliKontrol" => $kontrol['poliKontrolBPJS'],
                "kodeDokter" => $kontrol['drKontrolBPJS'],
                "user" =>  'Sirus',
            ]
        ];
        // lakukan validasis
        $validator = Validator::make($r, [
            "request.noSEP" => "required",
            "request.tglRencanaKontrol" => "required|date",
            "request.kodeDokter" => "required",
            "request.poliKontrol" => "required",
            "request.user" => "required",
        ], $messages);

        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), $validator->errors(), 201, null, null);
        }



        // handler when time out and off line mode
        try {

            $url = env('VCLAIM_URL') . "RencanaKontrol/insert";
            $signature = self::signature();
            $signature['Content-Type'] = 'application/x-www-form-urlencoded';
            $data = $r;

            $response = Http::timeout(10)
                ->withHeaders($signature)
                ->post($url, $data);


            // dd($response->transferStats->getTransferTime()); Get Transfertime request
            // semua response error atau sukses dari BPJS di handle pada logic response_decrypt
            return self::response_decrypt($response, $signature, $url, $response->transferStats->getTransferTime());
            /////////////////////////////////////////////////////////////////////////////
        } catch (Exception $e) {
            return self::sendError($e->getMessage(), $validator->errors(), 408, $url, null);
        }
    }

    public static function suratkontrol_update($kontrol)
    {

        // customErrorMessages
        // $messages = customErrorMessagesTrait::messages();
        $messages = [];
        // Masukkan Nilai dari parameter
        $r = [
            "request" => [
                "request.noSuratKontrol" => $kontrol['noSKDPBPJS'],
                "noSEP" => $kontrol['noSEP'],
                "tglRencanaKontrol" => Carbon::createFromFormat('d/m/Y', $kontrol['tglKontrol'])->format('Y-m-d'),
                "poliKontrol" => $kontrol['poliKontrolBPJS'],
                "kodeDokter" => $kontrol['drKontrolBPJS'],
                "user" =>  'Sirus',
            ]
        ];

        // lakukan validasis
        $validator = Validator::make($r, [
            "request.noSuratKontrol" => "required",
            "request.noSEP" => "required",
            "request.tglRencanaKontrol" => "required|date",
            "request.kodeDokter" => "required",
            "request.poliKontrol" => "required",
            "request.user" => "required",
        ], $messages);

        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), $validator->errors(), 201, null, null);
        }



        // handler when time out and off line mode
        try {
            $url = env('VCLAIM_URL') . "RencanaKontrol/Update";
            $signature = self::signature();
            $signature['Content-Type'] = 'application/x-www-form-urlencoded';
            $data = $r;

            $response = Http::timeout(10)
                ->withHeaders($signature)
                ->post($url, $data);


            // dd($response->transferStats->getTransferTime()); Get Transfertime request
            // semua response error atau sukses dari BPJS di handle pada logic response_decrypt
            return self::response_decrypt($response, $signature, $url, $response->transferStats->getTransferTime());
            /////////////////////////////////////////////////////////////////////////////
        } catch (Exception $e) {
            return self::sendError($e->getMessage(), $validator->errors(), 408, $url, null);
        }
    }
    public static function suratkontrol_delete(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "noSuratKontrol" => "required",
            "user" => "required",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 201, null, null);
        }
        $url = env('VCLAIM_URL') . "RencanaKontrol/Delete";
        $signature = self::signature();
        $signature['Content-Type'] = 'application/x-www-form-urlencoded';
        $data = [
            "request" => [
                "t_suratkontrol" => [
                    "noSuratKontrol" => $request->noSuratKontrol,
                    "user" =>  $request->user,
                ]
            ]
        ];
        $response = Http::withHeaders($signature)->delete($url, $data);
        return self::response_decrypt($response, $signature, null, null);
    }
    public static function suratkontrol_nomor(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "noSuratKontrol" => "required",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 201, null, null);
        }
        $url = env('VCLAIM_URL') . "RencanaKontrol/noSuratKontrol/" . $request->noSuratKontrol;
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }
    public static function suratkontrol_peserta(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "bulan" => "required",
            "tahun" => "required",
            "nomorKartu" => "required",
            "formatFilter" => "required",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 400, null, null);
        }
        $url = env('VCLAIM_URL') . "RencanaKontrol/ListRencanaKontrol/Bulan/" . sprintf("%02d", $request->bulan)  . "/Tahun/" . $request->tahun . "/Nokartu/" . $request->nomorKartu . "/filter/" . $request->formatFilter;
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }
    public static function suratkontrol_tanggal(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "tanggalMulai" => "required|date",
            "tanggalAkhir" => "required|date",
            "formatFilter" => "required",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 201, null, null);
        }
        $url = env('VCLAIM_URL') . "RencanaKontrol/ListRencanaKontrol/tglAwal/" . $request->tanggalMulai . "/tglAkhir/" . $request->tanggalAkhir .  "/filter/" . $request->formatFilter;
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }
    public static function suratkontrol_poli(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "jenisKontrol" => "required",
            "nomor" => "required",
            "tanggalKontrol" => "required|date",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 201, null, null);
        }
        $url = env('VCLAIM_URL') . "RencanaKontrol/ListSpesialistik/JnsKontrol/" . $request->jenisKontrol  . "/nomor/" . $request->nomor . "/TglRencanaKontrol/" . $request->tanggalKontrol;
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }
    public static function suratkontrol_dokter(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "jenisKontrol" => "required",
            "kodePoli" => "required",
            "tanggalKontrol" => "required",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 201, null, null);
        }
        $url = env('VCLAIM_URL') . "RencanaKontrol/JadwalPraktekDokter/JnsKontrol/" . $request->jenisKontrol . "/KdPoli/" . $request->kodePoli . "/TglRencanaKontrol/" . $request->tanggalKontrol;
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }




    // RUJUKAN
    public static function rujukan_nomor(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "nomorRujukan" => "required",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 201, null, null);
        }
        $url = env('VCLAIM_URL') . "Rujukan/" . $request->nomorRujukan;
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }

    public static function rujukan_peserta($nomorKartu) //fktp dari
    {
        // customErrorMessages
        $messages = customErrorMessagesTrait::messages();

        // Masukkan Nilai dari parameter
        $r = [
            'nomorKartu' => $nomorKartu,
        ];
        // lakukan validasis
        $validator = Validator::make($r, [
            "nomorKartu" => "required|digits:13",
        ], $messages);

        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), $validator->errors(), 201, null, null);
        }



        // handler when time out and off line mode
        try {

            $url = env('VCLAIM_URL') . "Rujukan/List/Peserta/" . $nomorKartu;
            $signature = self::signature();
            $response = Http::timeout(10)
                ->withHeaders($signature)
                ->get($url);


            // dd($response->transferStats->getTransferTime()); Get Transfertime request
            // semua response error atau sukses dari BPJS di handle pada logic response_decrypt
            return self::response_decrypt($response, $signature, $url, $response->transferStats->getTransferTime());
            /////////////////////////////////////////////////////////////////////////////
        } catch (Exception $e) {
            return self::sendError($e->getMessage(), $validator->errors(), 408, $url, null);
        }
    }


    public static function rujukan_rs_nomor(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "nomorRujukan" => "required",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 201, null, null);
        }
        $url = env('VCLAIM_URL') . "Rujukan/RS/" . $request->nomorRujukan;
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }
    public static function rujukan_rs_peserta($nomorKartu) //fktl dari
    {
        // customErrorMessages
        $messages = customErrorMessagesTrait::messages();

        // Masukkan Nilai dari parameter
        $r = [
            'nomorKartu' => $nomorKartu,
        ];
        // lakukan validasis
        $validator = Validator::make($r, [
            "nomorKartu" => "required|digits:13",
        ], $messages);

        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), $validator->errors(), 201, null, null);
        }



        // handler when time out and off line mode
        try {

            $url = env('VCLAIM_URL') . "Rujukan/RS/List/Peserta/" . $nomorKartu;
            $signature = self::signature();
            $response = Http::timeout(10)
                ->withHeaders($signature)
                ->get($url);


            // dd($response->transferStats->getTransferTime()); Get Transfertime request
            // semua response error atau sukses dari BPJS di handle pada logic response_decrypt
            return self::response_decrypt($response, $signature, $url, $response->transferStats->getTransferTime());
            /////////////////////////////////////////////////////////////////////////////
        } catch (Exception $e) {
            return self::sendError($e->getMessage(), $validator->errors(), 408, $url, null);
        }
    }

    public static function rujukan_jumlah_sep(Request $request)
    {
        // checking request
        $validator = Validator::make(request()->all(), [
            "jenisRujukan" => "required",
            "nomorRujukan" => "required",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 400, null, null);
        }
        $url = env('VCLAIM_URL') . "Rujukan/JumlahSEP/" . $request->jenisRujukan . "/" . $request->nomorRujukan;
        $signature = self::signature();
        $response = Http::withHeaders($signature)->get($url);
        return self::response_decrypt($response, $signature, null, null);
    }


    // SEP
    public static function sep_insert($SEPJsonReq)
    {

        // customErrorMessages
        // $messages = customErrorMessagesTrait::messages();
        $messages = [];
        // Masukkan Nilai dari parameter
        $r = [
            "noKartu" => $SEPJsonReq['request']['t_sep']['noKartu'],
            "tglSep" => $SEPJsonReq['request']['t_sep']['tglSep'],
            "ppkPelayanan" => $SEPJsonReq['request']['t_sep']['ppkPelayanan'],
            "jnsPelayanan" => $SEPJsonReq['request']['t_sep']['jnsPelayanan'],
            "klsRawatHak" => $SEPJsonReq['request']['t_sep']['klsRawat']['klsRawatHak'],
            "asalRujukan" => $SEPJsonReq['request']['t_sep']['rujukan']['asalRujukan'],
            "tglRujukan" => $SEPJsonReq['request']['t_sep']['rujukan']['tglRujukan'],
            "noRujukan" => $SEPJsonReq['request']['t_sep']['rujukan']['noRujukan'],
            "ppkRujukan" => $SEPJsonReq['request']['t_sep']['rujukan']['ppkRujukan'],
            "catatan" => $SEPJsonReq['request']['t_sep']['catatan'],
            "diagAwal" => $SEPJsonReq['request']['t_sep']['diagAwal'],
            "tujuan" => $SEPJsonReq['request']['t_sep']['poli']['tujuan'],
            "eksekutif" => $SEPJsonReq['request']['t_sep']['poli']['eksekutif'],
            "tujuanKunj" => $SEPJsonReq['request']['t_sep']['tujuanKunj'],
            // "flagProcedure" => $SEPJsonReq['request']['t_sep']['flagProcedure'],
            // "kdPenunjang" => $SEPJsonReq['request']['t_sep']['kdPenunjang'],
            // "assesmentPel" => $SEPJsonReq['request']['t_sep']['assesmentPel'],
            // "noSurat" => $SEPJsonReq['request']['t_sep']['noSurat'],
            // "kodeDPJP" => $SEPJsonReq['request']['t_sep']['kodeDPJP'],
            "dpjpLayan" => $SEPJsonReq['request']['t_sep']['dpjpLayan'],
            "noTelp" => $SEPJsonReq['request']['t_sep']['noTelp'],
            "user" => $SEPJsonReq['request']['t_sep']['user'],
        ];

        // lakukan validasis
        $validator = Validator::make($r, [
            "noKartu" => "required",
            "tglSep" => "required",
            "ppkPelayanan" => "required",
            "jnsPelayanan" => "required",
            "klsRawatHak" => "required",
            "asalRujukan" => "required",
            "tglRujukan" => "required",
            // "noRujukan" => "required",
            // "ppkRujukan" => "required",
            "catatan" => "required",
            "diagAwal" => "required",
            "tujuan" => "required",
            "eksekutif" => "required",
            "tujuanKunj" => "required",
            // "flagProcedure" => "required",
            // "kdPenunjang" => "required",
            // "assesmentPel" => "required",
            // "noSurat" => "required",
            // "kodeDPJP" => "required",
            "dpjpLayan" => "required",
            "noTelp" => "required",
            "user" => "required",
        ], $messages);

        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), $validator->errors(), 201, null, null);
        }



        // handler when time out and off line mode
        try {

            $url = env('VCLAIM_URL') . "SEP/2.0/insert";
            $signature = self::signature();
            $signature['Content-Type'] = 'application/x-www-form-urlencoded';
            $data = $SEPJsonReq;

            $response = Http::timeout(10)
                ->withHeaders($signature)
                ->post($url, $data);


            // dd($response->transferStats->getTransferTime()); Get Transfertime request
            // semua response error atau sukses dari BPJS di handle pada logic response_decrypt
            return self::response_decrypt($response, $signature, $url, $response->transferStats->getTransferTime());
            /////////////////////////////////////////////////////////////////////////////
        } catch (Exception $e) {
            return self::sendError($e->getMessage(), $validator->errors(), 408, $url, null);
        }
    }

    public static function sep_delete(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            "noSep" => "required",
        ]);
        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), null, 201, null, null);
        }
        $url = env('VCLAIM_URL') . "SEP/2.0/delete";
        $signature = self::signature();
        $signature['Content-Type'] = 'application/x-www-form-urlencoded';
        $data = [
            "request" => [
                "t_sep" => [
                    "noSep" => $request->noSep,
                    "user" => 'RSUD Waled',
                ]
            ]
        ];
        $response = Http::withHeaders($signature)->delete($url, $data);
        return self::response_decrypt($response, $signature, null, null);
    }
    public static function sep_nomor($noSep)
    {

        // customErrorMessages
        // $messages = customErrorMessagesTrait::messages();
        $messages = [];
        // Masukkan Nilai dari parameter
        $r = [
            "noSep" => $noSep,
        ];

        // lakukan validasis
        $validator = Validator::make($r, [
            "noSep" => "required",
        ], $messages);

        if ($validator->fails()) {
            return self::sendError($validator->errors()->first(), $validator->errors(), 201, null, null);
        }



        // handler when time out and off line mode
        try {

            $url = env('VCLAIM_URL') . "SEP/" . $noSep;
            $signature = self::signature();
            $signature['Content-Type'] = 'application/x-www-form-urlencoded';

            $response = Http::timeout(10)
                ->withHeaders($signature)
                ->get($url);


            // dd($response->transferStats->getTransferTime()); Get Transfertime request
            // semua response error atau sukses dari BPJS di handle pada logic response_decrypt
            return self::response_decrypt($response, $signature, $url, $response->transferStats->getTransferTime());
            /////////////////////////////////////////////////////////////////////////////
        } catch (Exception $e) {
            return self::sendError($e->getMessage(), $validator->errors(), 408, $url, null);
        }
    }
}
