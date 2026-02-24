<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class restaurants_orders extends Model
{
    protected $table = 'restaurant_orders';
    protected $primaryKey = 'id';
    public $incrementing = false; // Firestore ID as primary key
    protected $keyType = 'string';

    protected $casts = [
        'products' => 'array',
        'author' => 'array',
        'address' => 'array',
        'specialDiscount' => 'array',
        'calculatedCharges' => 'array',
        'taxSetting' => 'array',
        'rejectedByDrivers' => 'array',
        'takeAway' => 'boolean',
        'ToPay' => 'float',
        'toPayAmount' => 'float',
        'createdAt' => 'string',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendorID', 'id');
    }
}
