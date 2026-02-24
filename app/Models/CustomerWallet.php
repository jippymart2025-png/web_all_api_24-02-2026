<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CustomerWallet extends Model
{
    use HasFactory;

    protected $table = 'customer_wallet';

    protected $fillable = [
        'user_id',
        'coin_balance',
        'money_balance_paise'
    ];

    protected $casts = [
        'coin_balance' => 'integer',
        'money_balance_paise' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* -----------------------------
       Relationships
    ------------------------------*/

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function coinLedgers()
    {
        return $this->hasMany(CoinLedger::class, 'user_id', 'user_id');
    }

    public function moneyLedgers()
    {
        return $this->hasMany(MoneyWalletLedger::class, 'user_id', 'user_id');
    }
}
