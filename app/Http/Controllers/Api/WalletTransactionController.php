<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\Wallet;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WalletTransactionController extends Controller
{
    public function setWalletTransaction(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id'         => 'required|string',
            'amount'          => 'required|numeric',
            'isTopUp'         => 'required|boolean', // 1 = add , 0 = deduct
            'transactionUser' => 'nullable|string',
            'note'            => 'nullable|string',
            'payment_method'  => 'nullable|string',
            'payment_status'  => 'nullable|string|in:pending,paid,failed',
            'order_id'        => 'nullable|string',
            'subscription_id' => 'nullable|string',
            'date'            => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                "success" => false,
                "message" => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->all();

            if (!isset($data['id'])) {
                $data['id'] = Str::uuid()->toString();
            }

            if (!isset($data['date'])) {
                $data['date'] = now();
            }

            Wallet::create($data);

            return response()->json([
                "success" => true,
                "message" => "Wallet transaction saved successfully"
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => $e->getMessage()
            ], 500);
        }
    }

    public function updateUserWallet(Request $request)
    {
        $request->validate([
            'userId' => 'required|string',
            'amount' => 'required|numeric',
        ]);

        try {
            $user = User::where('firebase_id', $request->userId)->first(); // user_uid = Firebase ID field

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // Add amount to current wallet
            $user->wallet_amount = ($user->wallet_amount ?? 0) + floatval($request->amount);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Wallet updated successfully',
                'wallet_amount' => $user->wallet_amount,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function withdrawWalletAmount(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'vendorID'       => 'required|string',  // firebase vendor id
            'amount'         => 'required|numeric|min:1',
            'note'           => 'nullable|string',
            'withdrawMethod' => 'required|string',
            'paymentStatus'  => 'nullable|string|in:pending,paid,rejected',
            'paidDate'       => 'nullable|string',
            'adminNote'      => 'nullable|string',
            'payoutResponse' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                "success" => false,
                "message" => $validator->errors(),
            ], 422);
        }

        try {

            // Auto Generate Unique Payout ID
            $uniqueID = 'PAYOUT_' . uniqid();

            $payout = \App\Models\payout::create([
                'id'             => $uniqueID,
                'vendorID'       => $request->vendorID,
                'amount'         => $request->amount,
                'note'           => $request->note,
                'withdrawMethod' => $request->withdrawMethod,
                'paymentStatus'  => $request->paymentStatus ?? 'pending',
                'paidDate'       => $request->paidDate,
                'adminNote'      => $request->adminNote,
                'payoutResponse' => $request->payoutResponse,
            ]);

            return response()->json([
                "success" => true,
                "message" => "Withdrawal request submitted successfully",
                "payout_id" => $payout->id
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Error: " . $e->getMessage()
            ], 500);
        }
    }


}
