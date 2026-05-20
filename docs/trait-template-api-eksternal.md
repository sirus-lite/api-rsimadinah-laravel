# Template Trait untuk API Eksternal

Dokumen ini adalah **template & konvensi** untuk membuat trait baru yang berinteraksi dengan API eksternal (BPJS V-Claim, Antrean, Aplicares, iCare, SIRS, iDRG, atau API non-BPJS).

Konvensi ini sudah dipakai di:
- `App\Http\Traits\BPJS\VclaimTrait`
- `App\Http\Traits\BPJS\AntrianTrait`
- (project `sirus-php82`) `AplicaresTrait`, `iCareTrait`, `SirsTrait`, `iDrgTrait`

Ikuti pola yang sama supaya halaman monitoring `/database-monitor/log-bpjs` bisa otomatis menampilkan log-nya.

---

## 1. Struktur kanonik trait

Tiap trait API eksternal punya **3 grup method**:

```
┌──── Response Helpers ────┐  ┌──── Auth & Crypto ────┐  ┌──── API Methods ────┐
│ sendResponse($..., $payload) │  signature()              │  method1($params)
│ sendError($..., $payload)    │  stringEncrypt($key, $s)  │  method2($params)
│ response_decrypt($r, ...)    │  stringDecrypt($key, $s)  │  ...
└──────────────────────────┘  └───────────────────────┘  └─────────────────────┘
```

### Visualisasi alur

```
Caller (controller)
    │
    │ method1($params)
    ▼
[Trait method]
    │
    ├─► validate($params)  →  sendError($msg, $errs, 201, null, null)  → return JSON
    │
    ├─► signature() = HMAC headers
    │
    ├─► Http::post($url, $body) ───────────► EXTERNAL API
    │                                              │
    │   ◄──────── $response ───────────────────────┘
    │
    └─► response_decrypt($response, $signature, $url, $rtt)
            │
            ├─► sniff payload via $response->transferStats
            ├─► decrypt body kalau success
            │
            └─► sendResponse($msg, $data, $code, $url, $rtt, $payload)
                ↓
                INSERT web_log_status (code, response, http_req, http_payload, requestTransferTime)
                ↓
                return JSON ke caller
```

---

## 2. Template file

Buat file di `app/Http/Traits/{Namespace}/{Service}Trait.php`. Ganti `XYZ` dengan nama service (mis. `VCLAIM`, `ICARE`).

```php
<?php

namespace App\Http\Traits\{Namespace};

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Exception;

trait {Service}Trait
{
    // ====================================================================
    // 1. RESPONSE HELPERS
    // ====================================================================

    /**
     * Helper sukses — insert log + return JSON.
     *
     * @param string $message       Pesan ke client
     * @param mixed  $data          Data response
     * @param int    $code          HTTP code (default 200)
     * @param string|null $url      URL endpoint eksternal (untuk log)
     * @param float|null  $rtt      Request transfer time (detik)
     * @param string|null $payload  Request body yang dikirim (untuk log) — auto dari transferStats
     */
    public static function sendResponse($message, $data, $code = 200, $url = null, $requestTransferTime = null, $payload = null)
    {
        $response = [
            'response' => $data,
            'metadata' => [
                'message' => $message,
                'code' => $code,
            ],
        ];

        DB::table('web_log_status')->insert([
            'code'                => $code,
            'date_ref'            => Carbon::now(env('APP_TIMEZONE')),
            'response'            => json_encode($response, true),
            'http_req'            => $url,
            'http_payload'        => $payload,
            'requestTransferTime' => $requestTransferTime,
        ]);

        return response()->json($response, $code);
    }

    /**
     * Helper error — sama dengan sendResponse tapi dipakai untuk validation/HTTP error.
     */
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

        DB::table('web_log_status')->insert([
            'code'                => $code,
            'date_ref'            => Carbon::now(env('APP_TIMEZONE')),
            'response'            => json_encode($response, true),
            'http_req'            => $url,
            'http_payload'        => $payload,
            'requestTransferTime' => $requestTransferTime,
        ]);

        return response()->json($response, $code);
    }

    // ====================================================================
    // 2. AUTH & CRYPTO (sesuaikan dengan spec API masing-masing)
    // ====================================================================

    public static function signature()
    {
        $cons_id   = env('XYZ_CONS_ID');
        $secretKey = env('XYZ_SECRET_KEY');
        $userkey   = env('XYZ_USER_KEY');

        date_default_timezone_set('UTC');
        $tStamp = strval(time() - strtotime('1970-01-01 00:00:00'));
        $signature = hash_hmac('sha256', $cons_id . '&' . $tStamp, $secretKey, true);
        $encodedSignature = base64_encode($signature);

        return [
            'user_key'      => $userkey,
            'x-cons-id'     => $cons_id,
            'x-timestamp'   => $tStamp,
            'x-signature'   => $encodedSignature,
            'decrypt_key'   => $cons_id . $secretKey . $tStamp,
            'Content-Type'  => 'application/json',
        ];
    }

    /** Decrypt response BPJS (AES-256-CBC + LZString). Pakai versi ini kalau API mengikuti spec BPJS. */
    public static function stringDecrypt($key, $string)
    {
        $encrypt_method = 'AES-256-CBC';
        $key_hash = hex2bin(hash('sha256', $key));
        $iv = substr(hex2bin(hash('sha256', $key)), 0, 16);
        $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key_hash, OPENSSL_RAW_DATA, $iv);
        $output = \LZCompressor\LZString::decompressFromEncodedURIComponent($output);
        return $output;
    }

    // ====================================================================
    // 3. RESPONSE HANDLER (universal)
    // ====================================================================

    public static function response_decrypt($response, $signature, $url, $requestTransferTime)
    {
        // ★ KEY POINT: sniff request body dari Guzzle.
        // Laravel HTTP client otomatis set $response->transferStats lewat Guzzle on_stats callback.
        // null-safe karena untuk catch block tanpa $response, payload-nya null.
        $payload = $response->transferStats?->getRequest()?->getBody()?->__toString();

        if ($response->failed()) {
            return self::sendError(
                $response->reason(),
                $response->json('response'),
                $response->status(),
                $url,
                $requestTransferTime,
                $payload
            );
        }

        $code = $response->json('metaData.code'); // atau 'metadata.code' tergantung API

        if ($code == 200) {
            $decrypt = self::stringDecrypt($signature['decrypt_key'], $response->json('response'));
            $data = json_decode($decrypt, true);
        } else {
            $data = json_decode($response, true);
        }

        return self::sendResponse(
            $response->json('metaData.message'),
            $data,
            $code,
            $url,
            $requestTransferTime,
            $payload
        );
    }

    // ====================================================================
    // 4. API METHODS (per endpoint)
    // ====================================================================

    public static function get_something($param1, $param2)
    {
        // 1. Validation rules
        $r = [
            'param1' => $param1,
            'param2' => $param2,
        ];
        $rules = [
            'param1' => 'required|string',
            'param2' => 'required|numeric',
        ];
        $validator = Validator::make($r, $rules);
        if ($validator->fails()) {
            // tanpa $response → payload null
            return self::sendError($validator->errors()->first(), $validator->errors(), 201, null, null);
        }

        // 2. HTTP call
        try {
            $url = env('XYZ_URL') . "endpoint/path";
            $signature = self::signature();

            $response = Http::timeout(10)
                ->withHeaders($signature)
                ->post($url, [
                    'param1' => $param1,
                    'param2' => $param2,
                ]);

            return self::response_decrypt($response, $signature, $url, $response->transferStats->getTransferTime());
        } catch (Exception $e) {
            return self::sendError($e->getMessage(), null, 408, $url ?? null, null);
        }
    }
}
```

---

## 3. Checklist saat membuat trait baru

- [ ] **Namespace** `App\Http\Traits\{Namespace}` (mis. `BPJS`, `SIRS`, `Kemenkes`).
- [ ] **3 method standar**: `sendResponse`, `sendError`, `response_decrypt` — semua punya parameter `$payload = null` di akhir.
- [ ] **Helper auth** — `signature()` (atau apa pun nama-nya yang sesuai spec).
- [ ] **Helper decrypt** — kalau API enkripsi (BPJS pakai AES-256-CBC + LZString; iDRG pakai AES-256-CBC + HMAC signature 10-byte).
- [ ] **Per-method**: 5 langkah → validate → try → `Http::post` → `response_decrypt` → catch.
- [ ] **Sniff payload** di `response_decrypt` via `$response->transferStats?->getRequest()?->getBody()?->__toString()`.
- [ ] **Insert ke `web_log_status`** WAJIB termasuk `http_payload` (kolom CLOB).
- [ ] **ENV** — semua secret/URL via `env('PREFIX_*')`, tidak hardcode.
- [ ] **Carbon timezone** — pakai `Carbon::now(env('APP_TIMEZONE'))` untuk timestamp lokal.

---

## 4. Anti-pattern

❌ **JANGAN** hardcode URL/secret:
```php
$response = Http::post('https://apijkn.bpjs-kesehatan.go.id/...', ...);  // BAD
```

❌ **JANGAN** lewatkan logging:
```php
$response = Http::post($url, ...);
return $response->json();  // BAD — gak ada log, gak ada audit trail
```

❌ **JANGAN** sniff payload secara manual & teruskan dari semua caller:
```php
// BAD — ribet, 30+ caller harus diubah
$payload = json_encode($body);
return self::response_decrypt($response, $sig, $url, $rtt, $payload);
```

✅ **DO** sniff di `response_decrypt` saja:
```php
public static function response_decrypt($response, $signature, $url, $requestTransferTime)
{
    $payload = $response->transferStats?->getRequest()?->getBody()?->__toString();
    // ...
}
```

❌ **JANGAN** silently swallow exception tanpa log:
```php
try { ... } catch (Exception $e) { return null; }  // BAD
```

✅ **DO** kembalikan via `sendError`:
```php
catch (Exception $e) {
    return self::sendError($e->getMessage(), null, 408, $url ?? null, null);
}
```

---

## 5. Kasus khusus: API dengan enkripsi non-BPJS

Kalau API eksternal pakai **enkripsi berbeda** (mis. iDRG yang pakai AES + HMAC 10-byte signature), tetap ikuti pola yang sama tapi sesuaikan `signature()` / `stringDecrypt()`. Lihat contoh di `sirus-php82/app/Http/Traits/iDRG/iDrgTrait.php` — di sana `response_decrypt` punya parameter ekstra `$key` + `$debug`, dan ada auto-decrypt request body untuk log readable.

## 6. Kasus khusus: API tanpa enkripsi (REST JSON polos)

Contoh SIRS Online Kemenkes — tanpa HMAC, tanpa enkripsi response. Struktur trait-nya bisa lebih simple:
```php
// pakai sirsResponse / sirsError sebagai pengganti sendResponse / sendError
// gabungkan logic decrypt-handler ke sirsResponse karena tidak ada step decrypt
```

Tapi tetap **WAJIB log `http_payload`** dengan sniff `transferStats` (lihat `SirsTrait::sirsResponse` di project `sirus-php82`).

---

## 7. Halaman monitoring

Setelah trait baru dipakai, log otomatis muncul di:
- **`sirus-php82`** → `/database-monitor/log-bpjs` (filter "Lainnya" buat service yang belum dikenal pattern URL-nya)
- Tambah pattern di `detectService()` (di `log-bpjs.blade.php`) untuk badge warna khusus

```php
// Tambah di detectService() dan $serviceOptions kalau perlu badge khusus
str_contains($u, 'pattern-baru') => 'service-baru',
```

---

## 8. Referensi

- `app/Http/Traits/BPJS/VclaimTrait.php` — contoh trait lengkap dengan enkripsi BPJS
- `app/Http/Traits/BPJS/AntrianTrait.php` — contoh **instance method** (bukan static) — lihat naming `sendResponseAntrianTrait` / `sendErrorAntrianTrait`
- (`sirus-php82`) `app/Http/Traits/iDRG/iDrgTrait.php` — contoh auto-decrypt request body
- (`sirus-php82`) `app/Http/Traits/SIRS/SirsTrait.php` — contoh trait tanpa enkripsi
