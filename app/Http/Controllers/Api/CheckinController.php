<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\DailyCheckin;
use App\Models\CustomerWallet;
use App\Models\CoinLedger;


class CheckinController extends Controller
{
    /* -------------------------------------------------
       POST /checkin
    --------------------------------------------------*/
    public function checkin(Request $request)
    {
        $request->validate([
            'firebase_id' => 'required|string',
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

        $today = Carbon::now('Asia/Kolkata')->toDateString();

        // 2️⃣ Check already checked in
        $existing = DailyCheckin::where('user_id', $user->id)
            ->where('checkin_date', $today)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'message' => 'Already checked in today',
                'data' => [
                    'userId' => $user->firebase_id,
                    'date' => $today,
                    'streakDayNumber' => $existing->streak_day_number,
                    'coinsAwarded' => $existing->coins_awarded,
                    'createdAt' => $existing->created_at->toISOString()
                ]
            ]);
        }

        // 3️⃣ Calculate streak
        $last = DailyCheckin::where('user_id', $user->id)
            ->orderByDesc('checkin_date')
            ->first();

        $streak = 1;

        if ($last &&
            Carbon::parse($last->checkin_date)
                ->addDay()
                ->toDateString() == $today
        ) {
            $streak = $last->streak_day_number + 1;
        }

        // Optional: cap streak at 30
        if ($streak > 30) {
            $streak = 30;
        }

        // 4️⃣ Calculate coins
        $coins = 25;

        if ($streak == 10) $coins += 100;
        if ($streak == 20) $coins += 250;
        if ($streak == 30) $coins += 500;

        // 5️⃣ Transaction
        DB::transaction(function () use ($user, $today, $streak, $coins, $request) {

            DailyCheckin::create([
                'user_id' => $user->id,
                'checkin_date' => $today,
                'streak_day_number' => $streak,
                'coins_awarded' => $coins,
                'idempotency_key' => $request->idempotency_key
            ]);

            $wallet = CustomerWallet::where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                $wallet = CustomerWallet::create([
                    'user_id' => $user->id,
                    'coin_balance' => 0,
                    'money_balance_paise' => 0
                ]);
            }

            $wallet->increment('coin_balance', $coins);

            CoinLedger::create([
                'user_id' => $user->id,
                'type' => 'CHECKIN',
                'coins' => $coins,
                'reference_id' => 'checkin_' . $today,
                'idempotency_key' => $request->idempotency_key
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Check-in successful',
            'data' => [
                'userId' => $user->firebase_id,
                'date' => $today,
                'streakDayNumber' => $streak,
                'coinsAwarded' => $coins,
                'createdAt' => Carbon::now('Asia/Kolkata')->toISOString()
            ]
        ]);
    }

    /* -------------------------------------------------
       GET /checkin/status
    --------------------------------------------------*/
    public function status(Request $request)
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

        $today = Carbon::now('Asia/Kolkata')->toDateString();

        $checkin = DailyCheckin::where('user_id', $user->id)
            ->where('checkin_date', $today)
            ->first();

        if (!$checkin) {
            return response()->json([
                'success' => true,
                'data' => [
                    'userId' => $user->firebase_id,
                    'date' => null,
                    'streakDayNumber' => 0,
                    'coinsAwarded' => 0
                ]
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'userId' => $user->firebase_id,
                'date' => $today,
                'streakDayNumber' => $checkin->streak_day_number,
                'coinsAwarded' => $checkin->coins_awarded,
                'createdAt' => $checkin->created_at->toISOString()
            ]
        ]);
    }
}
