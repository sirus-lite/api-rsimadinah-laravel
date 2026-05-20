# Modul Antrian BPJS Mobile JKN

Dokumen ini menjelaskan arsitektur, endpoint, dan alur modul **Antrean BPJS Mobile JKN** di project `api-rsimadinah-laravel`. Project ini berperan sebagai **server-side API** yang dipanggil oleh aplikasi **Mobile JKN** (BPJS Kesehatan), sekaligus **client** yang memanggil API publik **Antrean RS BPJS** untuk sinkronisasi.

Referensi spec: **Manual API Antrean RS BPJS Kesehatan**.

---

## 1. Arsitektur

Modul antrian punya **dua arah panggilan**:

```
┌────────────────┐                                    ┌──────────────────┐
│  Mobile JKN    │ ── POST /ambilantrean ──────►      │  api-rsimadinah  │
│  (BPJS app)    │                                    │  ↓ controller    │
│                │ ◄── 200 (nobooking, antrian) ──    │  ↓ DB referensi  │
│                │                                    │    _mobilejkn    │
│                │ ── POST /checkinantrean ──►        │                  │
│                │ ── POST /batalantrean ──►          │                  │
│                │ ── ... ──►                         │                  │
└────────────────┘                                    └──────────────────┘
                                                              │
                                                              │  outbound
                                                              ▼
                                                      ┌─────────────────┐
                                                      │  Antrean RS API │
                                                      │  apijkn.bpjs    │
                                                      │  -kesehatan.go.id│
                                                      └─────────────────┘
                                                      (tambah_antrean,
                                                       update_antrean, dll)
```

### 1.1 Arah Inbound (Mobile JKN → kita)

**File:** `app/Http/Controllers/AntrolBPJSController.php`

Mobile JKN POST ke endpoint kita. Kita validasi → simpan ke tabel `referensi_mobilejkn_bpjs` → return JSON ke Mobile JKN.

### 1.2 Arah Outbound (kita → Antrean RS BPJS)

**File:** `app/Http/Traits/BPJS/AntrianTrait.php`

Setelah booking terbuat / diubah / dibatalkan, kita sinkron ke server BPJS supaya tampil di Mobile JKN user lain. Endpoint contoh: `antrean/add`, `antrean/updatewaktu`, `antrean/batal`.

---

## 2. Endpoint Inbound (Mobile JKN → kita)

Semua route ada di `routes/api.php`. Authentication via header `x-username` + `x-token` (dicek di `AntrolBPJSController::authenticate()`).

| Method | Path | Handler | Tujuan |
|--------|------|---------|--------|
| GET    | `/auth`                  | `token()`              | Generate token akses |
| POST   | `/ambilantrean`          | `ambilantrean()`       | **Booking baru** — pasien pilih dokter/tanggal |
| POST   | `/checkinantrean`        | `checkinantrean()`     | Check-in pasien hari-H, buat row di `rstxn_rjhdrs` |
| POST   | `/batalantrean`          | `batalantrean()`       | Batalkan booking |
| POST   | `/statusantrean`         | `statusantrean()`      | Cek status antrian |
| POST   | `/sisaantrean`           | `sisaantrean()`        | Cek sisa kuota dokter/tanggal |
| POST   | `/pasienbaru`            | `pasienbaru()`         | Daftarkan pasien baru (nik belum ada di `rsmst_pasiens`) |
| POST   | `/jadwaloperasirs`       | `jadwaloperasirs()`    | List jadwal operasi |
| POST   | `/jadwaloperasipasien`   | `jadwaloperasipasien()`| Jadwal operasi per pasien |

### 2.1 Alur `ambilantrean()` (booking baru)

Source: `AntrolBPJSController.php::ambilantrean()`

1. **Authenticate** — `x-username` + `x-token`.
2. **Cek pasien** — `rsmst_pasiens` by `nokartu_bpjs`. Kalau tidak ada → tolak (suruh daftar offline). Cocokkan `nik_bpjs`.
3. **Sinkron norm** — pastikan `request->norm == pasien->reg_no`.
4. **Validasi input** — `nomorkartu(13)`, `nik(16)`, `nohp`, `kodepoli`, `tanggalperiksa(Y-m-d)`, `kodedokter`, `jampraktek`, `jeniskunjungan(1-4)`, `nomorreferensi` (wajib kecuali kontrol).
5. **Validasi tanggal**:
   - `endOfDay()->isPast()` → "Tanggal periksa sudah terlewat".
   - **Toggle `ANTRIAN_DISALLOW_SAMEDAY`** (default `true`) → tolak booking utk hari ini (wajib H+1).
   - `> Carbon::now()->addDays(35)` → tolak (max 35 hari ke depan).
6. **Cek master** — `rsmst_doctors` by `kd_dr_bpjs`, `rsmst_polis` by `kd_poli_bpjs`.
7. **Cek jadwal & kuota** — `scview_scpolis` cek dokter/poli/hari/jam, lalu hitung pasien terdaftar di `rsview_rjkasir` (yang `rj_status != 'F'`).
8. **Generate booking** — `YmdHis + 'JKN'` (mis. `20260520143000JKN`).
9. **Cache::lock per dokter/tanggal (15 detik, retry 5 detik)** — cegah race condition:
   - Cek duplikasi NIK aktif di tanggal sama.
   - Cek `nobooking` belum ada (idempotency).
   - Hitung `MAX(no_antrian)` dari `rstxn_rjhdrs` + `referensi_mobilejkn_bpjs` (cast `to_number` karena VARCHAR2).
   - Estimasi dilayani = jam_mulai + (10 menit × (noAntrian + 1)).
   - Insert ke `referensi_mobilejkn_bpjs` dengan `status = 'Belum'`.
10. **Return** — `nomorantrean`, `angkaantrean`, `kodebooking`, `norm`, `namapoli`, `namadokter`, `estimasidilayani`, kuota, sisa kuota.

### 2.2 Toggle ENV

| Key | Default | Fungsi |
|-----|---------|--------|
| `ANTRIAN_DISALLOW_SAMEDAY` | `true`  | Tolak booking utk hari yg sama (wajib H+1). `false` = izinkan |
| `ANTRIAN_URL`             | (BPJS)  | Base URL Antrean RS BPJS untuk outbound |
| `ANTRIAN_CONS_ID`         | (RS)    | Consumer ID dari aplicares BPJS |
| `ANTRIAN_SECRET_KEY`      | (RS)    | Secret untuk HMAC signature |
| `ANTRIAN_USER_KEY`        | (RS)    | User key API |

---

## 3. Endpoint Outbound (kita → Antrean RS BPJS)

Trait: `AntrianTrait` (`use AntrianTrait;` di controller manapun yg butuh push ke BPJS).

Pola call:
```php
$this->tambah_antrean([
    'kodebooking' => $noBooking,
    'nomorkartu'  => $nokartu,
    // ... field lain
]);
```

### 3.1 Method utama

| Method | Endpoint BPJS | Tujuan |
|--------|---------------|--------|
| `tambah_antrean()`     | `antrean/add`         | Push booking baru ke BPJS |
| `update_antrean()`     | `antrean/updatewaktu` | Update waktu pelayanan |
| `batal_antrean()`      | `antrean/batal`       | Batalkan booking di server BPJS |
| `status_antrean()`     | `antrean/getlisttask` | Cek status task list |

### 3.2 Authentication (HMAC)

Helper `signature()` di trait:
```php
$tStamp = strval(time() - strtotime('1970-01-01 00:00:00'));
$signature = hash_hmac('sha256', $cons_id . "&" . $tStamp, $secretKey, true);
$encodedSignature = base64_encode($signature);
```

Header request:
- `x-cons-id`
- `x-timestamp`
- `x-signature`
- `user_key`

Decrypt key (untuk decode response 200): `cons_id + secretKey + tStamp`. BPJS pakai AES-256-CBC + LZString.

### 3.3 Pola response handler

```
sendResponseAntrianTrait($message, $data, $code, $url, $rtt, $payload)
sendErrorAntrianTrait($error, $errors, $code, $url, $rtt, $payload)
response_decrypt($response, $signature, $url, $rtt)
```

`response_decrypt` cek `$response->failed()`, decrypt body kalau code=200, lalu dispatch ke `sendResponse*` atau `sendError*`.

---

## 4. Logging ke `web_log_status`

Semua call outbound (dan beberapa error inbound) tercatat di tabel `web_log_status`:

| Kolom | Isi |
|-------|-----|
| `code`                | HTTP status code |
| `date_ref`            | Timestamp |
| `http_req`            | URL endpoint BPJS |
| `http_payload`        | **Request body** (JSON yg kita kirim) — dapat di-sniff otomatis dari `transferStats` |
| `response`            | Response body (sudah di-decrypt kalau encrypted) |
| `requestTransferTime` | Durasi (detik) |

**Catatan penting:** kolom `http_payload` ditambah lewat DDL:
```sql
ALTER TABLE WEB_LOG_STATUS ADD (http_payload CLOB);
```

Visualisasi log: lihat halaman `/database-monitor/log-bpjs` di project `sirus-php82` (dua project share DB Oracle yang sama).

---

## 5. Tabel kunci

| Tabel | Tipe | Fungsi |
|-------|------|--------|
| `referensi_mobilejkn_bpjs` | Buffer | Booking dari Mobile JKN sebelum checkin (status: Belum / Sudah / Batal) |
| `rstxn_rjhdrs`             | Master | Master pendaftaran rawat jalan (dibuat saat checkin) |
| `rsmst_pasiens`            | Master | Data master pasien — sumber `nokartu_bpjs`, `nik_bpjs`, `reg_no` |
| `rsmst_doctors`            | Master | Master dokter — link `kd_dr_bpjs` ↔ `dr_id` |
| `rsmst_polis`              | Master | Master poli — link `kd_poli_bpjs` ↔ `poli_id` |
| `scview_scpolis`           | View   | Jadwal & kuota dokter per hari (mulai/selesai praktek) |
| `rsview_rjkasir`           | View   | Pasien terdaftar (untuk hitung sisa kuota) |
| `web_log_status`           | Log    | Audit trail semua call API BPJS |

---

## 6. Field `referensi_mobilejkn_bpjs` (skema penting)

Beberapa field punya catatan khusus:

- `angkaantrean` — tipe `VARCHAR2`. Saat hitung `MAX()`, harus pakai `TO_NUMBER` agar tidak lex-sort.
- `tanggalperiksa` — `VARCHAR2` dengan format `Y-m-d`. Banding dengan `format('Y-m-d')` di PHP, bukan parse.
- `status` — enum string: `'Belum'` / `'Sudah'` / `'Batal'`.
- `estimasidilayani` — timestamp Unix milisecond (×1000).
- `daftardariapp` — `'JKNMobileAPP'` untuk yg dari Mobile JKN.

---

## 7. Catatan praktis

- **Tanggal:** Mobile JKN pakai timezone UTC tapi `tanggalperiksa` selalu local-date Indonesia. Wajib `Carbon::now(config('app.timezone'))` saat banding.
- **Race condition:** SELALU pakai `Cache::lock` per `dr_id+tanggal` saat insert booking (komponen `noAntrian` rentan dobel).
- **Quota:** quota di `scview_scpolis` adalah master, sisa = `kuota - count(rsview_rjkasir where rj_status != 'F')`.
- **Pasien baru:** kalau `nokartu_bpjs` belum ada di `rsmst_pasiens`, JANGAN auto-insert dari Mobile JKN — tolak dan suruh datang offline (kebijakan RS).
- **Sinkron norm:** `request->norm` boleh kosong; backend isi otomatis dari `rsmst_pasiens.reg_no`.

---

## 8. Trait baru untuk API eksternal

Kalau nanti perlu trait baru (misalnya iCare, Aplicares, SIRS, atau API non-BPJS), ikuti template di [`trait-template-api-eksternal.md`](trait-template-api-eksternal.md).
