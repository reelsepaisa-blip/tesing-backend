<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;


class Msg91Service
{
    protected $authKey;
    protected $templateId;
    protected $baseUrl = 'https://control.msg91.com/api/v5';

    public function __construct()
    {
        $this->authKey = config('services.msg91.auth_key');
        $this->templateId = config('services.msg91.template_id');
    }

    
    public function sendOtp(string $phone, string $otp, string $countryCode = '+91', ?string $signatureId = null): bool
    {
        try {
            $countryCode = ltrim($countryCode, '+');
            $mobile = $countryCode . $phone;

            $url = "https://control.msg91.com/api/v5/flow";

            $senderId = config('services.msg91.sender_id', 'NTSFRS');

            $signatureValue = $signatureId ?? '';

            $payload = [
                "template_id" => "693bdcb2ebf8c171341e3a43", // YOUR FLOW TEMPLATE ID
                "sender" => $senderId,
                "mobiles" => $mobile,

                "OTP" => $otp,
                "signature" => $signatureValue,
            ];

            

            $response = Http::withHeaders([
                "authkey" => $this->authKey,
                "Content-Type" => "application/json"
            ])->post($url, $payload);


            return $response->successful();

        } catch (\Exception $e) {
            return false;
        }
    }



    
    public function verifyOtp(string $phone, string $otp, string $countryCode = '+91'): array
    {
        try {
            $countryCode = ltrim($countryCode, '+');

            $mobile = $countryCode . $phone;

            $url = "{$this->baseUrl}/otp/verify";

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'authkey' => $this->authKey,
            ])->post($url, [
                'otp' => $otp,
                'mobile' => $mobile,
            ]);

            $responseData = $response->json();


            return [
                'success' => $response->successful(),
                'data' => $responseData,
            ];
        } catch (\Exception $e) {

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    
    public function resendOtp(string $phone, string $countryCode = '+91'): bool
    {
        try {
            $countryCode = ltrim($countryCode, '+');

            $mobile = $countryCode . $phone;

            $url = "{$this->baseUrl}/otp/retry";

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'authkey' => $this->authKey,
            ])->post($url, [
                'mobile' => $mobile,
                'retrytype' => 'text', // Can be 'voice' or 'text'
            ]);

            $responseData = $response->json();


            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
