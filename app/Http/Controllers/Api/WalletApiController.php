<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WalletApiController extends Controller
{
    /**
     * Set wallet transaction (for users/restaurants)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function setWalletTransaction(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'id' => 'nullable|string',
                'user_id' => 'required|string',
                'amount' => 'required|numeric',
                'isTopUp' => 'nullable|boolean',
                'transactionUser' => 'nullable|string',
                'note' => 'nullable|string',
                'payment_method' => 'nullable|string',
                'payment_status' => 'nullable|string|in:pending,paid,failed',
                'order_id' => 'nullable|string',
                'subscription_id' => 'nullable|string',
                'date' => 'nullable|string'
            ]);

            $data = $request->all();

            // Generate ID if not provided
            if (!isset($data['id']) || empty($data['id'])) {
                $data['id'] = Str::uuid()->toString();
            }

            // Set date if not provided
            if (!isset($data['date']) || empty($data['date'])) {
                $data['date'] = now();
            }

            // Create or update wallet transaction
            Wallet::updateOrCreate(
                ['id' => $data['id']],
                $data
            );

            return response()->json([
                'success' => true,
                'message' => 'Wallet transaction saved successfully',
                'id' => $data['id']
            ], 200);

        } catch (\Exception $e) {
            Log::error('setWalletTransaction error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error saving wallet transaction: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get withdraw method for a user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getWithdrawMethod(Request $request): JsonResponse
    {
        try {
            $userId = $request->input('userId');

            // Try to get from authenticated user if available
            if (!$userId && $request->user()) {
                $userId = $request->user()->firebase_id ?? null;
            }

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User ID is required'
                ], 400);
            }

            $method = DB::table('withdraw_method')
                ->where('userId', $userId)
                ->first();

            if (!$method) {
                return response()->json([
                    'success' => false,
                    'message' => 'Withdraw method not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $method
            ], 200);

        } catch (\Exception $e) {
            Log::error('getWithdrawMethod error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching withdraw method: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Set/Update withdraw method for a user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function setWithdrawMethod(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'id' => 'nullable|string',
                'userId' => 'required|string',

                // accept JSON array or object
                'flutterwave' => 'nullable|array',
                'paypal' => 'nullable|array',
                'stripe' => 'nullable|array',
                'razorpay' => 'nullable|array',
            ]);

            $data = [
                'id'        => $request->id ?? Str::uuid()->toString(),
                'userId'    => $request->userId,
                'flutterwave' => json_encode($request->flutterwave),
                'paypal'     => json_encode($request->paypal),
                'stripe'     => json_encode($request->stripe),
                'razorpay'   => json_encode($request->razorpay),
            ];

            DB::table('withdraw_method')->updateOrInsert(
                ['id' => $data['id']],
                $data
            );

            return response()->json([
                'success' => true,
                'message' => 'Withdraw method saved successfully',
                'data' => $data
            ], 200);

        } catch (\Exception $e) {
            Log::error('setWithdrawMethod error: '.$e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error saving withdraw method: '.$e->getMessage()
            ], 500);
        }
    }

    /**
     * Set driver wallet record
     *
     * @param Request $request
     * @return JsonResponse
     */

    public function setDriverWalletRecord(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'id'            => 'nullable|integer',     // AUTO_INCREMENT
                'driverId'      => 'required|string',
                'zoneId'        => 'nullable|string',
                'totalEarnings' => 'required|numeric',
                'bonus'         => 'nullable|boolean',
                'bonusAmount'   => 'nullable|numeric',
                'type'          => 'nullable|string|in:bonus,delivery',
                'date'          => 'nullable|date'
            ]);

            $data = [
                'driverId'      => $request->driverId,
                'zoneId'        => $request->zoneId,
                'totalEarnings' => $request->totalEarnings,
                'bonus'         => $request->bonus ?? 0,
                'bonusAmount'   => $request->bonusAmount,
                'type'          => $request->type ?? ($request->bonus ? 'bonus' : 'delivery'),
                'date'          => $request->date ?? now(),
            ];

            if ($request->id) {
                // UPDATE RECORD
                DB::table('delivery_wallet_record')->where('id', $request->id)->update($data);
                $recordId = $request->id;
            } else {
                // GENERATE FIREBASE_ID AUTOMATICALLY 🚀
                $data['firebase_id'] = Str::uuid();   // or Str::random(28)

                // INSERT NEW RECORD
                $recordId = DB::table('delivery_wallet_record')->insertGetId($data);
            }

            return response()->json([
                'success' => true,
                'message' => 'Driver wallet record saved successfully',
                'id'      => $recordId,
                'firebase_id' => $data['firebase_id'] ?? null
            ], 200);

        } catch (\Exception $e) {
            Log::error('setDriverWalletRecord error: '.$e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error saving driver wallet record: '.$e->getMessage()
            ], 500);
        }
    }

}

