<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Carbon\Carbon;

class DriverControllerLogin extends Controller
{
    public function driverLogin(Request $request)
    {
        $request->validate([
            "email" => "required|email",
            "password" => "required"
        ]);

        // Check if user exists
        $user = User::where("email", $request->email)
	->where("role", "driver")
	->first();

        if (!$user) {
            return response()->json([
                "success" => false,
                "message" => "No user found for that email."
            ], 404);
        }

        // Check driver role only
        if ($user->role !== "driver") {
            return response()->json([
                "success" => false,
                "message" => "This user is not created in driver application."
            ], 403);
        }

        // Check active
        if ((int)$user->active !== 1) {
            return response()->json([
                "success" => false,
                "message" => "This user is disabled, please contact administrator."
            ], 403);
        }

        // Check password
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                "success" => false,
                "message" => "Wrong password provided for that user."
            ], 401);
        }

        // Update FCM token
        if ($request->has('fcmToken')) {
            $user->fcm_token = $request->fcm_token;
            $user->save();
        }

        return response()->json([
            "success" => true,
            "message" => "Login successful",
            "data" => $user
        ], 200);
    }


public function driverSignup(Request $request): \Illuminate\Http\JsonResponse
{
    // Common validation
    $request->validate([
        "type" => "required",  // email / mobileNumber / google / apple
        "first_name" => "required",
        "last_name" => "required",
        "zone_id" => "required|string",
        "app_identifier" => "required"  // android or ios
    ]);

    $autoApprove = true;

    // EMAIL SIGNUP
    if ($request->type === "email") {

        $request->validate([
            "email" => "required|email",
            "password" => "required|min:6"
        ]);

        // Check uniqueness of email for drivers only
        $existing = User::where("email", strtolower($request->email))
            ->where("role", "driver")
            ->first();

        if ($existing) {
            return response()->json([
                "success" => false,
                "message" => "Email already exists"
            ], 409);
        }

        $firebaseId = $this->generateFirebaseId();

        $user = User::create([
            "firebase_id" => $firebaseId,
            "firstName" => $request->first_name,
            "lastName" => $request->last_name,
            "email" => strtolower($request->email),
            "phoneNumber" => $request->phone_number,
            "countryCode" => $request->country_code,
            "password" => Hash::make($request->password),
            "role" => "driver",
            "fcmToken" => $request->fcm_token,
            "active" => $autoApprove ? 1 : 0,
            "isDocumentVerify" => 0,
            "wallet_amount" => 0,
            "deliveryAmount" => 0,
            "zoneId" => $request->zone_id,
            "provider" => "email",
            "appIdentifier" => $request->app_identifier,
            "createdAt" => now(),
        ]);

        return response()->json([
            "success" => true,
            "auto_approve" => $autoApprove,
            "message" => $autoApprove ? "Account created successfully" : "Your signup is under approval.",
            "data" => $user
        ]);
    }

    // GOOGLE / APPLE / MOBILE SIGNUP
    if (in_array($request->type, ["google", "apple", "mobileNumber"])) {

        if ($request->email) {
            $existing = User::where("email", strtolower($request->email))
                ->where("role", "driver")
                ->first();
            if ($existing) {
                return response()->json([
                    "success" => false,
                    "message" => "Email already exists"
                ], 409);
            }
        }

        $firebaseId = $this->generateFirebaseId();

        $user = User::create([
            "firebase_id" => $firebaseId,
            "firstName" => $request->first_name,
            "lastName" => $request->last_name,
            "email" => strtolower($request->email),
            "phoneNumber" => $request->phone_number,
            "countryCode" => $request->country_code,
            "password" => Hash::make($request->password ?? bin2hex(random_bytes(4))), // fallback password
            "role" => "driver",
            "fcmToken" => $request->fcm_token,
            "active" => $autoApprove ? 1 : 0,
            "isDocumentVerify" => 0,
            "wallet_amount" => 0,
            "deliveryAmount" => 0,
            "zoneId" => $request->zone_id,
            "provider" => $request->type,
            "appIdentifier" => $request->app_identifier,
            "createdAt" => now(),
        ]);

        return response()->json([
            "success" => true,
            "auto_approve" => $autoApprove,
            "message" => $autoApprove ? "Account created successfully" : "Your signup is under approval.",
            "data" => $user
        ]);
    }

    return response()->json([
        "success" => false,
        "message" => "Invalid signup type"
    ], 400);
}


    // --- FIREBASE ID GENERATOR ---
    private function generateFirebaseId($length = 20)
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $max = strlen($characters) - 1;

        do {
            $id = '';

            for ($i = 0; $i < $length; $i++) {
                $id .= $characters[random_int(0, $max)];
            }

            // Check if this ID exists in users table
            $exists = \DB::table('users')->where('firebase_id', $id)->exists();

        } while ($exists);

        return $id;
    }


}
