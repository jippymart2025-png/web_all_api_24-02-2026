<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Otp extends Model
{
    use HasFactory;

    protected $table = 'otps';
    
    protected $fillable = [
        'phone',
        'otp',
        'expires_at',
        'verified',
        'attempts'
    ];

    protected $casts = [
        'verified' => 'boolean',
        'expires_at' => 'datetime',
        'attempts' => 'integer'
    ];

    /**
     * Mark this OTP as verified
     */
    public function markAsVerified()
    {
        $this->verified = true;
        $this->save();
    }
}

