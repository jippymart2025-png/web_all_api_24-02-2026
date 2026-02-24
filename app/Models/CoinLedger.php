<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CoinLedger extends Model
{
    use HasFactory;

    protected $table = 'coin_ledger';

    protected $fillable = [
        'user_id',
        'type',
        'coins',
        'reference_id',
        'metadata',
        'idempotency_key'
    ];

    protected $casts = [
        'coins' => 'integer',
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
