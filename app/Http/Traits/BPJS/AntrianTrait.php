<?php

namespace App\Http\Traits\BPJS;


use Carbon\Carbon;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

use Exception;


trait AntrianTrait
{

    private function sendResponseAntrianTrait($message, $data, $code = 200, $url = null, $requestTransferTime = null, $payload = null)
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

    private function sendErrorAntrianTrait($error, $errorMessages = [], $code = 404, $url = null, $requestTransferTime = null, $payload = null)
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


    //
    // API FUNCTION
    public function signature()
    {
        $cons_id =  env('ANTRIAN_CONS_ID');
        $secretKey = env('ANTRIAN_SECRET_KEY');
        $userkey = env('ANTRIAN_USER_KEY');
        date_default_timezone_set('UTC');
        $tStamp = strval(time() - strtotime('1970-01-01 00:00:00'));
        $signature = hash_hmac('sha256', $cons_id . "&" . $tStamp, $secretKey, true);
        $encodedSignature = base64_encode($signature);
        $data['user_key'] =  $userkey;
        $data['x-cons-id'] = $cons_id;
        $data['x-timestamp'] = $tStamp;
        $data['x-signature'] = $encodedSignature;
        $data['decrypt_key'] = $cons_id . $secretKey . $tStamp;
        return $data;
    }

    public function stringDecrypt($key, $string)
    {
        $encrypt_method = 'AES-256-CBC';
        $key_hash = hex2bin(hash('sha256', $key));
        $iv = substr(hex2bin(hash('sha256', $key)), 0, 16);
        $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key_hash, OPENSSL_RAW_DATA, $iv);
        $output = \LZCompressor\LZString::decompressFromEncodedURIComponent($output);
        return $output;
    }

    public function response_decrypt($response, $signature, $url, $requestTransferTime)
    {
        // Sniff request body dari Guzzle (lewat $response->transferStats yg di-set Laravel HTTP client).
        $payload = $response->transferStats?->getRequest()?->getBody()?->__toString();

        if ($response->failed()) {
            // error, msgError,Code,url,ReqtrfTime,payload
            return $this->sendErrorAntrianTrait($response->reason(),  $response->json('response'), $response->status(), $url, $requestTransferTime, $payload);
        } else {

            // Check Response !200          -> metadata d kecil
            $code = $response->json('metadata.code'); //code 200 -201 500 dll

            if ($code == 200) {
                $decrypt = $this->stringDecrypt($signature['decrypt_key'], $response->json('response'));
                $data = json_decode($decrypt, true);
            } else {
                $data = json_decode($response, true);
            }
            return $this->sendResponseAntrianTrait($response->json('metadata.message'), $data, $code, $url, $requestTransferTime, $payload);
        }
    }



    public function tambah_antrean($antreanadd)
    {

        // customErrorMessages
        // $messages = customErrorMessagesTrait::messages();
        $messages = [];


        $r = [
            "kodebooking" => $antreanadd['kodebooking'],
            "nomorkartu" =>  $antreanadd['nomorkartu'],
            "nomorreferensi" =>  $antreanadd['nomorreferensi'],
            "nik" =>  $antreanadd['nik'],
            "nohp" => $antreanadd['nohp'],
            "kodepoli" =>  $antreanadd['kodepoli'],
            "norm" =>  $antreanadd['norm'],
            "pasienbaru" =>  $antreanadd['pasienbaru'],
            "tanggalperiksa" =>   $antreanadd['tanggalperiksa'],
            "kodedokter" =>  $antreanadd['kodedokter'],
            "jampraktek" =>  $antreanadd['jampraktek'],
            "jeniskunjungan" => $antreanadd['jeniskunjungan'],
            "jenispasien" =>  $antreanadd['jenispasien'],
            "namapoli" =>  $antreanadd['namapoli'],
            "namadokter" =>  $antreanadd['namadokter'],
            "nomorantrean" =>  $antreanadd['nomorantrean'],
            "angkaantrean" =>  $antreanadd['angkaantrean'],
            "estimasidilayani" =>  $antreanadd['estimasidilayani'],
            "sisakuotajkn" =>  $antreanadd['sisakuotajkn'],
            "kuotajkn" => $antreanadd['kuotajkn'],
            "sisakuotanonjkn" => $antreanadd['sisakuotanonjkn'],
            "kuotanonjkn" => $antreanadd['kuotanonjkn'],
            "keterangan" =>  $antreanadd['keterangan'],
            // "nama" =>  $antreanadd['nama'],
        ];


        $rules = [
            "kodebooking" => "required",
            "nomorkartu" =>  "digits:13|numeric",
            // "nomorreferensi" =>  "required",
            "nik" =>  "required|digits:16|numeric",
            "nohp" => "required|numeric",
            "kodepoli" =>  "required",
            "norm" =>  "required",
            "pasienbaru" =>  "required",
            "tanggalperiksa" =>  "required|date|date_format:Y-m-d",
            "kodedokter" =>  "required",
            "jampraktek" =>  "required",
            "jeniskunjungan" => "required",
            "jenispasien" =>  "required",
            // "namapoli" =>  "required",
            // "namadokter" =>  "required",
            "nomorantrean" =>  "required",
            "angkaantrean" =>  "required",
            "estimasidilayani" =>  "required",
            "sisakuotajkn" =>  "required",
            "kuotajkn" => "required",
            "sisakuotanonjkn" => "required",
            "kuotanonjkn" => "required",
            "keterangan" =>  "required",
            // "nama" =>  "required",
        ];

        // ketika pasien umum nik dan noka boleh kosong
        $rules['nomorkartu'] = ($antreanadd['jenispasien'] == 'JKN') ? 'digits:13|numeric' : '';
        $rules['nik'] = ($antreanadd['jenispasien'] == 'JKN') ? 'required|digits:16|numeric' : '';

        $validator = Validator::make($r, $rules, $messages);
        // dd($validator->errors());

        if ($validator->fails()) {
            // error, msgError,Code,url,ReqtrfTime
            return $this->sendErrorAntrianTrait($validator->errors()->first(), $validator->errors(), 201, null, null);
        }


        // handler when time out and off line mode
        try {

            $antreanadd = $r;

            $url = env('ANTRIAN_URL') . "antrean/add";
            $signature = $this->signature();

            $response = Http::timeout(10)
                ->withHeaders($signature)
                ->post(
                    $url,
                    [
                        "kodebooking" => $antreanadd['kodebooking'],
                        "jenispasien" => $antreanadd['jenispasien'],
                        "nomorkartu" => $antreanadd['nomorkartu'],
                        "nik" => $antreanadd['nik'],
                        "nohp" => $antreanadd['nohp'],
                        "kodepoli" => $antreanadd['kodepoli'],
                        "namapoli" => $antreanadd['namapoli'],
                        "pasienbaru" => $antreanadd['pasienbaru'],
                        "norm" => $antreanadd['norm'],
                        "tanggalperiksa" => $antreanadd['tanggalperiksa'],
                        "kodedokter" => $antreanadd['kodedokter'],
                        "namadokter" => $antreanadd['namadokter'],
                        "jampraktek" => $antreanadd['jampraktek'],
                        "jeniskunjungan" => $antreanadd['jeniskunjungan'],
                        "nomorreferensi" => $antreanadd['nomorreferensi'],
                        "nomorantrean" => $antreanadd['nomorantrean'],
                        "angkaantrean" => $antreanadd['angkaantrean'],
                        "estimasidilayani" => $antreanadd['estimasidilayani'],
                        "sisakuotajkn" => $antreanadd['sisakuotajkn'],
                        "kuotajkn" => $antreanadd['kuotajkn'],
                        "sisakuotanonjkn" => $antreanadd['sisakuotanonjkn'],
                        "kuotanonjkn" => $antreanadd['kuotanonjkn'],
                        "keterangan" => $antreanadd['keterangan'],
                    ]
                );

            // dd($response->getBody()->getContents());

            // dd($response->transferStats->getTransferTime()); //Get Transfertime request
            // semua response error atau sukses dari BPJS di handle pada logic response_decrypt
            return $this->response_decrypt($response, $signature, $url, $response->transferStats->getTransferTime());
            /////////////////////////////////////////////////////////////////////////////
        } catch (Exception $e) {
            // error, msgError,Code,url,ReqtrfTime

            return $this->sendErrorAntrianTrait($e->getMessage(), $validator->errors(), 408, $url, null);
        }
    }

    public function update_antrean($kodebooking, $taskid, $waktu, $jenisresep)
    {

        // customErrorMessages
        $messages = [];

        $r = [
            "kodebooking" => $kodebooking,
            "taskid" =>  $taskid,
            "waktu" =>  $waktu,
            "jenisresep" => $jenisresep //  "Tidak ada/Racikan/Non racikan" ---> khusus yang sudah implementasi antrean farmasi
        ];
        // dd(Carbon::createFromTimestamp($waktu / 1000)->toDateTimeString());


        $rules = [
            "kodebooking" => "required",
            "taskid" =>  "required",
            "waktu" =>  "required",
            "jenisresep" => "",
        ];


        $validator = Validator::make($r, $rules, $messages);

        if ($validator->fails()) {
            // error, msgError,Code,url,ReqtrfTime
            return $this->sendErrorAntrianTrait($validator->errors()->first(), $validator->errors(), 201, null, null);
        }


        // handler when time out and off line mode
        try {


            $url = env('ANTRIAN_URL') . "antrean/updatewaktu";
            $signature = $this->signature();

            $response = Http::timeout(10)
                ->withHeaders($signature)
                ->post(
                    $url,
                    [
                        "kodebooking" => $kodebooking,
                        "taskid" => $taskid,
                        "waktu" => $waktu,
                        "jenisresep" => $jenisresep,
                    ]
                );

            // dd($response->getBody()->getContents());

            // dd($response->transferStats->getTransferTime()); //Get Transfertime request
            // semua response error atau sukses dari BPJS di handle pada logic response_decrypt
            return $this->response_decrypt($response, $signature, $url, $response->transferStats->getTransferTime());
            /////////////////////////////////////////////////////////////////////////////
        } catch (Exception $e) {
            // error, msgError,Code,url,ReqtrfTime

            return $this->sendErrorAntrianTrait($e->getMessage(), $validator->errors(), 408, $url, null);
        }
    }
}
