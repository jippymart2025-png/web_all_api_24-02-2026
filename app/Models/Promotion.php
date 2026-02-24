<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    use HasFactory;

    protected $table = 'promotions';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'restaurant_id',
        'restaurant_title',
        'product_id',
        'product_title',
        'vType',
        'zoneId',
        'special_price',
        'item_limit',
        'extra_km_charge',
        'free_delivery_km',
        'start_time',
        'end_time',
        'payment_mode',
        'isAvailable',
        'promo'
    ];

    protected $casts = [
        'special_price' => 'float',
        'item_limit' => 'integer',
        'extra_km_charge' => 'float',
        'free_delivery_km' => 'float',
        'isAvailable' => 'boolean',
        'promo' => 'boolean'
    ];

    // Relationship to vendor
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'restaurant_id', 'id');
    }

    // Relationship to zone
    public function zone()
    {
        return $this->belongsTo(Zone::class, 'zoneId', 'id');
    }
}


