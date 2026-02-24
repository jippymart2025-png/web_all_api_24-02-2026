<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $table = 'coupons';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'code',
        'description',
        'discount',
        'expiresAt',
        'discountType',
        'image',
        'resturant_id',
        'cType',
        'item_value',
        'usageLimit',
        'usedCount',
        'usedBy',
        'isPublic',
        'isEnabled',
    ];
}


