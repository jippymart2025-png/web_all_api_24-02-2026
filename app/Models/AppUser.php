<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppUser extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'isActive' => 'boolean',
        'active' => 'integer',
        'isDocumentVerify' => 'string',
        'wallet_amount' => 'integer',
        'rotation' => 'float',
        'orderCompleted' => 'integer',
    ];

    /* ======================================================
       Relationships
    ====================================================== */

    // 1️⃣ Wallet (1:1)
    public function wallet()
    {
        return $this->hasOne(CustomerWallet::class, 'user_id');
    }

    // 2️⃣ Coin Ledger (1:many)
    public function coinLedgers()
    {
        return $this->hasMany(CoinLedger::class, 'user_id');
    }

    // 3️⃣ Money Ledger (1:many)
    public function moneyLedgers()
    {
        return $this->hasMany(MoneyWalletLedger::class, 'user_id');
    }

    // 4️⃣ Referrals I gave (I am referrer)
    public function referralsGiven()
    {
        return $this->hasMany(Referral::class, 'referrer_user_id');
    }

    // 5️⃣ Referral I used (I am referee)
    public function referralUsed()
    {
        return $this->hasOne(Referral::class, 'referee_user_id');
    }

    // 6️⃣ Daily check-ins
    public function checkins()
    {
        return $this->hasMany(DailyCheckin::class, 'user_id');
    }
}
