<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\Otp;
use App\Models\User;
use Illuminate\Support\Str;

class OTPController extends Controller
{
    // SMS API Configuration
    private $smsApiUrl = 'https://restapi.smscountry.com/v0.1/Accounts/g3NwQZX8qbjHARPZktFZ/SMSes/';
    private $authKey = 'Basic ZzNOd1FaWDhxYmpIQVJQWmt0Rlo6Y2lXdzBZRHUzbTFRY3hkMEFBSmZXaHNmczQ4TXRXdEs4Sk91TnR0Zg==';
    private $senderId = 'JIPPYM';
    /**
     * DLT Template ID - Required for sending SMS in India
     * Register your template in SMS Country dashboard and set the Template ID here
     * Template format should be: "Your OTP for jippymart login is {#}. Please do not share this OTP with anyone. It is valid for the next 2 minutes-jippymart.in."
     */
    private $templateId = null;


    /**
     * Send OTP to phone number
     */
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|regex:/^[0-9]{10,15}$/'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $phone = $request->phone;

        // Check if there's a recent OTP request (rate limiting)
        if ($phone === '9999999999') {
            return response()->json([
                'success' => true,
                'message' => 'OTP sent successfully',
                'expires_in' => 600 // 10 minutes in seconds
            ]);
        }

        // Check if there's a recent OTP request (rate limiting)
        $recentOtp = Otp::where('phone', $phone)
            ->where('created_at', '>', Carbon::now()->subMinutes(1))
            ->first();

        if ($recentOtp) {
            return response()->json([
                'success' => false,
                'message' => 'Please wait 1 minute before requesting another OTP'
            ], 429);
        }

        // Generate 6-digit OTP
        $otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = Carbon::now()->addMinutes(10);

        // Save OTP to database
        Otp::updateOrCreate(
            ['phone' => $phone],
            [
                'otp' => $otp,
                'expires_at' => $expiresAt,
                'verified' => false,
                'attempts' => 0
            ]
        );

        // Send SMS using multiple methods
        $smsSent = $this->sendSms($phone, $otp);

        if ($smsSent) {
            return response()->json([
                'success' => true,
                'message' => 'OTP sent successfully',
                'expires_in' => 600 // 10 minutes in seconds
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send OTP. Please try again.'
            ], 500);
        }
    }


    /**
     * Verify OTP
     */
    public function verifyOtp(Request $request)
    {
        // Accept either 'phone' or 'phoneNumber'
        $phone = $request->input('phone') ?? $request->input('phoneNumber');

        $validator = Validator::make(
            ['phone' => $phone, 'otp' => $request->input('otp')],
            [
                'phone' => 'required|string|regex:/^[0-9]{10,15}$/',
                'otp' => 'required|string|size:6'
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $otpValue = $request->input('otp');

        // Test override: allow fixed test number/otp without DB OTP check
        if ($phone === '9999999999' && $otpValue === '123456') {
            $user = User::where('phoneNumber', $phone)
                ->where('role', 'customer')
                ->first();

            if (!$user) {
                $firebaseId = 'user_' . Str::uuid();

                $user = User::create([
                    'firstName'     => 'User',
                    'lastName'      => substr($phone, -4),
                    'phoneNumber'   => $phone,
                    'firebase_id'   => $firebaseId,
                    'email'         => null,
                    'password'      => bcrypt(Str::random(16)),
                    'active'        => 1,
                    'isActive'      => true,
                    'role'          => 'customer',
                    'wallet_amount' => 0,
                    'orderCompleted'=> 0,
                    '_created_at'   => Carbon::now()->format('Y-m-d H:i:s'),
                    '_updated_at'   => Carbon::now()->format('Y-m-d H:i:s'),
                    'createdAt'     => Carbon::now()->format('Y-m-d H:i:s'),
                ]);
            }

            $isRegistered = !(
                $user->firstName === 'User' ||
                empty($user->email) ||
                $user->email === $user->phoneNumber . '@jippymart.in'
            );

            $token = $user->createToken('otp-auth')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'OTP verified successfully',
                'is_registered' => $isRegistered,
                'user' => [
                    'id' => $user->id,
                    'firstName' => $user->firstName,
                    'lastName' => $user->lastName,
                    'phoneNumber' => $user->phoneNumber,
                    'email' => $user->email,
                    'firebase_id' => $user->firebase_id,
                    'wallet_amount' => $user->wallet_amount
                ],
                'token' => $token,
                'token_type' => 'Bearer'
            ]);
        }

        // Normal OTP record lookup
        // Find OTP record
        $otpRecord = Otp::where('phone', $phone)
            ->where('otp', $otpValue)
            ->where('expires_at', '>', Carbon::now())
            ->where('verified', false)
            ->first();

        if (!$otpRecord) {
            Otp::where('phone', $phone)
                ->where('verified', false)
                ->increment('attempts');

            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP'
            ], 401);
        }

        if ($otpRecord->attempts >= 5) {
            return response()->json([
                'success' => false,
                'message' => 'Too many failed attempts. Please request a new OTP.'
            ], 429);
        }

        // Mark OTP as verified
        $otpRecord->verified = true;
        $otpRecord->save();

// Find or create user
        $user = User::where('phoneNumber', $phone)
        ->where('role', 'customer')
        ->first();

        if ($user) {
            // Allow login only if role = customer
            if ($user->role !== 'customer') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Only customers can log in using this app.'
                ], 403);
            }
        } else {
            // Create new customer
            $firebaseId = 'user_' . Str::uuid();

            $user = User::create([
                'firstName'     => 'User',
                'lastName'      => substr($phone, -4),
                'phoneNumber'   => $phone,
                'firebase_id'   => $firebaseId,
                'email'         => null,
                'password'      => bcrypt(Str::random(16)),
                'active'        => 1,
                'isActive'      => true,
                'role'          => 'customer', // force default role
                'wallet_amount' => 0,
                'orderCompleted'=> 0,
                '_created_at'   => Carbon::now()->format('Y-m-d H:i:s'),
                '_updated_at'   => Carbon::now()->format('Y-m-d H:i:s'),
                'createdAt'     => Carbon::now()->format('Y-m-d H:i:s'),
            ]);
        }

        // Determine registration status
        $isRegistered = !(
            $user->firstName === 'User' ||
            empty($user->email) ||
            $user->email === $user->phoneNumber . '@jippymart.in'
        );

        // Generate API token
        $token = $user->createToken('otp-auth')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'OTP verified successfully',
            'is_registered' => $isRegistered,
            'user' => [
                'id' => $user->id,
                'firstName' => $user->firstName,
                'lastName' => $user->lastName,
                'phoneNumber' => $user->phoneNumber,
                'email' => $user->email,
                'firebase_id' => $user->firebase_id,
                'wallet_amount' => $user->wallet_amount
            ],
            'token' => $token,
            'token_type' => 'Bearer'
        ]);
    }



    /**
     * Send SMS using multiple HTTP client methods
     */
    private function sendSms($phone, $otp)
    {
        $smsText = "Your OTP for jippymart login is {$otp}. Please do not share this OTP with anyone. It is valid for the next 10 minutes-jippymart.in.";

        $payload = [
            "Text" => $smsText,
            "Number" => $phone,
            "SenderId" => $this->senderId,
            "DRNotifyUrl" => config('app.url') . "/api/sms-delivery-status",
            "DRNotifyHttpMethod" => "POST",
            "Tool" => "API"
        ];

        // Add Template ID if configured (required for DLT compliance in India)
        if ($this->templateId) {
            $payload["TemplateId"] = $this->templateId;
        }

        // Try multiple methods to send SMS (prioritize cURL since it's working)
        $methods = [
            'curl' => fn() => $this->sendSmsWithCurl($payload),
            'guzzle' => fn() => $this->sendSmsWithGuzzle($payload),
            'http_request2' => fn() => $this->sendSmsWithHttpRequest2($payload),
            'pecl_http' => fn() => $this->sendSmsWithPeclHttp($payload)
        ];

        foreach ($methods as $method => $callback) {
            try {
                $result = $callback();
                if ($result) {
                    if (config('app.debug')) {
                        Log::debug("SMS sent successfully using {$method}", [
                            'phone' => $phone,
                            'method' => $method
                        ]);
                    }
                    return true;
                }
            } catch (\Exception $e) {
                Log::error("Failed to send SMS using {$method}", [
                    'phone' => $phone,
                    'method' => $method,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return false;
    }


    public function signUp(Request $request)
    {
        // Accept either 'phone' or 'phoneNumber'
        $phone = $request->input('phone') ?? $request->input('phoneNumber');

        // Validate input
        $validator = Validator::make(
            array_merge($request->all(), ['phone' => $phone]),
            [
                'firstName' => 'required|string|max:100',
                'lastName' => 'required|string|max:100',
                'email' => 'nullable|email|max:191',
                'phone' => 'required|string|regex:/^[0-9]{10,15}$/',
                'referralCode' => 'nullable|string|max:50'
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid input',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Find existing user (should exist from OTP verification)
            $user = User::where('phoneNumber', $phone)
                ->where('role', 'customer')
                ->first();

            if ($user) {
                // Check if already fully registered
                $alreadyRegistered = !empty($user->email) && $user->firstName !== 'User';
                if ($alreadyRegistered) {
                    return response()->json([
                        'success' => false,
                        'message' => 'User already registered with this phone number'
                    ], 409);
                }

                // Update user details
                $updateData = [
                    'firstName' => $request->input('firstName'),
                    'lastName' => $request->input('lastName'),
                    'email' => $request->input('email', $phone . '@jippymart.in'),
                    'isActive' => 1,
                    'active' => 1,
                    '_updated_at' => Carbon::now()->format('Y-m-d H:i:s')
                ];

                // Add referral code only if provided
                if ($request->filled('referralCode')) {
                    $updateData['referral_code'] = $request->input('referralCode');
                }

                $user->update($updateData);
            } else {
                // Create new user if doesn't exist
                $newUserData = [
                    'firstName' => $request->input('firstName'),
                    'lastName' => $request->input('lastName'),
                    'email' => $request->input('email', $phone . '@jippymart.in')
                ];

                // Add referral code only if provided
                if ($request->filled('referralCode')) {
                    $newUserData['referral_code'] = $request->input('referralCode');
                }

                // Create new user
                $firebaseId = 'user_' . Str::uuid();

                $user = User::create([
                    'firstName' => $newUserData['firstName'],
                    'lastName' => $newUserData['lastName'],
                    'phoneNumber' => $phone,
                    'firebase_id' => $firebaseId,
                    'email' => $newUserData['email'],
                    'password' => bcrypt(Str::random(16)),
                    'active' => 1,
                    'isActive' => true,
                    'role' => 'customer',
                    'wallet_amount' => 0,
                    'orderCompleted' => 0,
                    'referral_code' => $newUserData['referral_code'] ?? null,
                    '_created_at' => Carbon::now()->format('Y-m-d H:i:s'),
                    '_updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
                    'createdAt' => Carbon::now()->format('Y-m-d H:i:s'),
                ]);
            }

            // Refresh user to get updated data
            $user->refresh();

            // Generate auth token
            $token = $user->createToken('signup-auth')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Signup successful',
                'user' => $user,
                'token' => $token,
                'token_type' => 'Bearer'
            ], 201);

        } catch (\Exception $e) {
            Log::error('Signup Error: ' . $e->getMessage(), [
                'phone' => $phone,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ]);

            // Return more detailed error in development
            $errorMessage = 'Signup failed. Please try again.';
            if (config('app.debug')) {
                $errorMessage .= ' Error: ' . $e->getMessage();
            }

            return response()->json([
                'success' => false,
                'message' => $errorMessage
            ], 500);
        }
    }
    /**
     * Send SMS using Guzzle HTTP Client
     */
    private function sendSmsWithGuzzle($payload)
    {
        try {
            $client = new \GuzzleHttp\Client();
            $headers = [
                'Authorization' => $this->authKey,
                'Content-Type' => 'application/json'
            ];
            $body = json_encode($payload);

            $request = new \GuzzleHttp\Psr7\Request('POST', $this->smsApiUrl, $headers, $body);
            $res = $client->sendAsync($request)->wait();

            return $res->getStatusCode() >= 200 && $res->getStatusCode() < 300;
        } catch (\Exception $e) {
            Log::error('Guzzle SMS Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send SMS using cURL
     */
    private function sendSmsWithCurl($payload)
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->smsApiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => array(
                'Authorization: ' . $this->authKey,
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return $httpCode >= 200 && $httpCode < 300;
    }



    /**
     * Send SMS using HTTP_Request2 (if available)
     */
    private function sendSmsWithHttpRequest2($payload)
    {
        if (!class_exists('HTTP_Request2')) {
            return false;
        }

        try {
            $request = new \HTTP_Request2();
            $request->setUrl($this->smsApiUrl);
            $request->setMethod(\HTTP_Request2::METHOD_POST);
            $request->setConfig(array(
                'follow_redirects' => TRUE
            ));
            $request->setHeader(array(
                'Authorization' => $this->authKey,
                'Content-Type' => 'application/json'
            ));
            $request->setBody(json_encode($payload));

            $response = $request->send();
            return $response->getStatus() == 200;
        } catch (\HTTP_Request2_Exception $e) {
            Log::error('HTTP_Request2 SMS Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send SMS using PECL HTTP (if available)
     */
    private function sendSmsWithPeclHttp($payload)
    {
        if (!extension_loaded('http')) {
            return false;
        }

        try {
            $request = new \http\Client\Request('POST', $this->smsApiUrl);
            $request->setHeaders([
                'Authorization' => $this->authKey,
                'Content-Type' => 'application/json'
            ]);
            $request->getBody()->append(json_encode($payload));

            $client = new \http\Client();
            $client->enqueue($request)->send();
            $response = $client->getResponse($request);

            return $response->getResponseCode() >= 200 && $response->getResponseCode() < 300;
        } catch (\Exception $e) {
            Log::error('PECL HTTP SMS Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * SMS Delivery Status Webhook
     */
    public function smsDeliveryStatus(Request $request)
    {
        Log::info('SMS Delivery Status', $request->all());

        return response()->json(['status' => 'received']);
    }

    /**
     * Resend OTP
     */
    public function resendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|regex:/^[0-9]{10,15}$/'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Delete existing OTP for this phone
        Otp::where('phone', $request->phone)->delete();

        // Call sendOtp method
        return $this->sendOtp($request);
    }

    /**
     * Debug OTP - Check current OTP status for a phone number
     */
    public function debugOtp($phone)
    {
        $otps = Otp::where('phone', $phone)->get();

        return response()->json([
            'phone' => $phone,
            'total_records' => $otps->count(),
            'records' => $otps->map(function($otp) {
                return [
                    'id' => $otp->id,
                    'otp' => $otp->otp,
                    'expires_at' => $otp->expires_at,
                    'expires_at_formatted' => $otp->expires_at->format('Y-m-d H:i:s'),
                    'is_expired' => $otp->expires_at < Carbon::now(),
                    'verified' => $otp->verified,
                    'attempts' => $otp->attempts,
                    'created_at' => $otp->created_at,
                    'current_time' => Carbon::now()->format('Y-m-d H:i:s')
                ];
            })
        ]);
    }
}
