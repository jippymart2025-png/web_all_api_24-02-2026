<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Wallet extends Model
{
    public $timestamps = false; // 🚀 Add this line

    protected $table = 'wallet';

    protected $fillable = [
        'id',
        'date',
        'subscription_id',
        'note',
        'transactionUser',
        'amount',
        'user_id',
        'payment_status',
        'isTopUp',
        'order_id',
        'payment_method'
    ];

    public $incrementing = false;
    protected $keyType = 'string';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid()->toString();
            }
        });
    }
}
