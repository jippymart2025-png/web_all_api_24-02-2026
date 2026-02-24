<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    public function updateWallet(Request $request)
    {
        // ✅ Validate request data
        $validator = Validator::make($request->all(), [
            'firebase_id' => 'required|exists:users,firebase_id',
            'amount'      => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // ✅ Find user by Firebase ID (not by primary key)
            $user = User::where('firebase_id', $request->firebase_id)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // ✅ Safely handle current wallet value
            $currentAmount = (float) ($user->wallet_amount ?? 0);
            $addAmount     = (float) $request->amount;

            $user->wallet_amount = $currentAmount + $addAmount;
            $user->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Wallet updated successfully',
                'wallet_amount' => $user->wallet_amount,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update wallet',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
