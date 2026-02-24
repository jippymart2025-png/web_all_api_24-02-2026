<?php

namespace App\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Model;

class Referral extends Model
{
    protected $table = 'referral';
    public $incrementing = false; // because id = varchar

    protected $fillable = [
        'id', 'referralCode', 'referralBy'
    ];
}
