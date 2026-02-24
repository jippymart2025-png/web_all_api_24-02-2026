<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MoneyWalletLedger extends Model
{
    use HasFactory;

    protected $table = 'money_wallet_ledger';

    protected $fillable = [
        'user_id',
        'type',
        'amount_paise',
        'reference_id',
        'metadata',
        'idempotency_key'
    ];

    protected $casts = [
        'amount_paise' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;

    const CREATED_AT = 'created_at';

    /* -----------------------------
       Relationships
    ------------------------------*/

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
