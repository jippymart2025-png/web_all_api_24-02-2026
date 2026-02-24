<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use Illuminate\Http\Request;
use App\Models\Referral;
use App\Models\User;



class ReferralController extends Controller
{
    /* -------------------------------------------------
       POST /referral/apply-code
    --------------------------------------------------*/
    public function applyCode(Request $request)
    {
        $request->validate([
            'firebase_id' => 'required|string',
            'code' => 'required|string',
            'idempotency_key' => 'nullable|string'
        ]);

        // 1️⃣ Resolve user
        $user = AppUser::where('firebase_id', $request->firebase_id)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // 2️⃣ Prevent self referral
        $referrer = AppUser::where('referral_code', $request->code)->first();

        if (!$referrer || $referrer->id == $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired referral code'
            ], 400);
        }

        // 3️⃣ Check already applied
        if (Referral::where('referee_user_id', $user->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'You have already applied a referral code'
            ], 400);
        }

        // 4️⃣ Idempotency check
        if ($request->idempotency_key) {
            $existing = Referral::where('referee_user_id', $user->id)
                ->where('idempotency_key', $request->idempotency_key)
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'Already applied',
                    'data' => [
                        'referrer_user_id' => $existing->referrer_user_id,
                        'status' => $existing->status
                    ]
                ]);
            }
        }

        // 5️⃣ Create referral record
        $ref = Referral::create([
            'referral_code' => $request->code,
            'referrer_user_id' => $referrer->id,
            'referee_user_id' => $user->id,
            'status' => 'PENDING',
            'idempotency_key' => $request->idempotency_key
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Referral code applied successfully',
            'data' => [
                'referrer_user_id' => $referrer->id,
                'status' => 'PENDING'
            ]
        ]);
    }

    /* -------------------------------------------------
       GET /referral/my-referrals
    --------------------------------------------------*/
    public function myReferrals(Request $request)
    {
        $request->validate([
            'firebase_id' => 'required|string'
        ]);

        $user = AppUser::where('firebase_id', $request->firebase_id)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $refs = Referral::where('referrer_user_id', $user->id)
            ->orWhere('referee_user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($ref) {
                return [
                    'id' => (string) $ref->id,
                    'referralCode' => $ref->referral_code,
                    'referralBy' => $ref->referrer_user_id,
                    'status' => $ref->status,
                    'refereeUserId' => $ref->referee_user_id,
                    'referrerUserId' => $ref->referrer_user_id,
                    'codeUsed' => $ref->referral_code,
                    'rewardedAt' => optional($ref->rewarded_at)->toISOString()
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $refs
        ]);
    }
}
