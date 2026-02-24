<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorProduct extends Model
{
    protected $table = 'vendor_products';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'addOnsPrice',
        'addOnsTitle',
        'calories',
        'categoryID',
        'createdAt',
        'description',
        'fats',
        'grams',
        'isAvailable',
        'item_attribute',
        'name',
        'nonveg',
        'photo',
        'photos',
        'product_specification',
        'proteins',
        'publish',
        'quantity',
        'takeawayOption',
        'veg',
        'vendorID',
        'migratedBy',
        'disPrice',
        'price',
        'merchant_price',
        'vType',
        'reviewsCount',
        'reviewAttributes',
        'reviewsSum',
        'updatedAt',
        'sizeTitle',
        'sizePrice',
        'attributes',
        'variants',
        'updated_at',
        'categoryTitle',
        'vendorTitle',
    ];

    protected $casts = [
        'addOnsPrice' => 'array',
        'addOnsTitle' => 'array',
        'photos' => 'array',
        'item_attribute' => 'array',
        'product_specification' => 'array',
        'sizeTitle' => 'array',
        'sizePrice' => 'array',
        'attributes' => 'array',
        'variants' => 'array',
        'nonveg' => 'boolean',
        'veg' => 'boolean',
        'publish' => 'boolean',
        'takeawayOption' => 'boolean',
        'isAvailable' => 'boolean',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendorID', 'id');
    }
}


