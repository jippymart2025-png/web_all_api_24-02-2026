<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use App\Models\CoinLedger;
use App\Models\CustomerWallet;
use App\Models\MoneyWalletLedger;
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


    public function getWallet(Request $request)
    {
        // ✅ Validate query param
        $request->validate([
            'firebase_id' => 'required|string'
        ]);

        $firebaseId = $request->query('firebase_id');

        // ✅ Find user
        $user = AppUser::where('firebase_id', $firebaseId)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // ✅ Generate referral code if not exists
        if (!$user->referral_code) {

            do {
                $code = strtoupper(substr(md5(uniqid()), 0, 8));
            } while (AppUser::where('referral_code', $code)->exists());

            $user->referral_code = $code;
            $user->save();
        }

        // ✅ Create wallet if not exists
        $wallet = CustomerWallet::firstOrCreate(
            ['user_id' => $user->id],
            ['coin_balance' => 0, 'money_balance_paise' => 0]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'coin_wallet' => [
                    'userId' => $user->firebase_id,
                    'coinBalance' => (int) $wallet->coin_balance,
                    'updatedAt' => optional($wallet->updated_at)->toISOString()
                ],
                'money_balance_paise' => (int) $wallet->money_balance_paise,
                'referral_code' => $user->referral_code
            ]
        ]);
    }

    /* ----------------------------------
       GET /wallet/coins/ledger
    -----------------------------------*/
    public function coinLedger(Request $request)
    {
        // 1️⃣ Validate firebase_id
        $request->validate([
            'firebase_id' => 'required|string'
        ]);

        // 2️⃣ Resolve user
        $user = AppUser::where('firebase_id', $request->firebase_id)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // 3️⃣ Pagination
        $limit = $request->limit ?? 20;
        $page = $request->page ?? 1;

        $ledgerQuery = CoinLedger::where('user_id', $user->id)
            ->orderByDesc('created_at');

        $ledger = $ledgerQuery->paginate($limit, ['*'], 'page', $page);

        // 4️⃣ Format response
        $data = $ledger->map(function ($entry) use ($user) {
            return [
                'id' => (string) $entry->id,
                'userId' => $user->firebase_id,
                'type' => $entry->type,
                'coins' => (int) $entry->coins,
                'referenceId' => $entry->reference_id,
                'createdAt' => $entry->created_at
                    ? $entry->created_at->toISOString()
                    : null,
                'metadata' => $entry->metadata ?? null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data->values()
        ]);
    }

    /* ----------------------------------
       POST /wallet/coins/redeem
    -----------------------------------*/
    public function redeemCoins(Request $request)
    {
        $request->validate([
            'firebase_id' => 'required|string',
            'coins' => 'required|integer|min:1000',
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

        $coins = (int) $request->coins;

        return DB::transaction(function () use ($user, $coins, $request) {

            // 2️⃣ Lock wallet row
            $wallet = CustomerWallet::where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet not found'
                ], 404);
            }

            // 3️⃣ Idempotency check
            if ($request->idempotency_key) {
                $existing = CoinLedger::where('user_id', $user->id)
                    ->where('idempotency_key', $request->idempotency_key)
                    ->first();

                if ($existing) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Already processed'
                    ]);
                }
            }

            // 4️⃣ Balance check
            if ($wallet->coin_balance < $coins) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient coin balance'
                ], 400);
            }

            // 5️⃣ Convert coins → paise
            $amountPaise = ($coins / 1000) * 10000;

            // 6️⃣ Update balances
            $wallet->coin_balance -= $coins;
            $wallet->money_balance_paise += $amountPaise;
            $wallet->save();

            // 7️⃣ Insert coin ledger
            CoinLedger::create([
                'user_id' => $user->id,
                'type' => 'REDEEM_DEBIT',
                'coins' => -$coins,
                'reference_id' => null,
                'idempotency_key' => $request->idempotency_key
            ]);

            // 8️⃣ Insert money ledger
            MoneyWalletLedger::create([
                'user_id' => $user->id,
                'type' => 'COIN_REDEEM_CREDIT',
                'amount_paise' => $amountPaise,
                'reference_id' => null,
                'idempotency_key' => $request->idempotency_key
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Coins redeemed successfully',
                'data' => [
                    'coins_redeemed' => $coins,
                    'amount_credited_paise' => $amountPaise,
                    'new_coin_balance' => $wallet->coin_balance,
                    'new_money_balance_paise' => $wallet->money_balance_paise
                ]
            ]);
        });
    }

}
