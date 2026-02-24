<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

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
}
