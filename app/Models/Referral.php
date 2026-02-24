<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Referral extends Model
{
    protected $table = 'referrals';

    protected $fillable = [
        'referral_code',
        'referrer_user_id',
        'referee_user_id',
        'status',
        'idempotency_key',
        'rewarded_at'
    ];

    protected $casts = [
        'rewarded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public $timestamps = false; // ✅ IMPORTANT

    const CREATED_AT = 'created_at'; // optional but clean

    public function referrer()
    {
        return $this->belongsTo(AppUser::class, 'referrer_user_id');
    }

    public function referee()
    {
        return $this->belongsTo(AppUser::class, 'referee_user_id');
    }
}
