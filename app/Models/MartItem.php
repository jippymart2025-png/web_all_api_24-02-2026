<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MartItem extends Model
{
    protected $table = 'mart_items';
    
    public $timestamps = false; // Using custom created_at/updated_at fields
    
    // Since ID is not auto-increment
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'price',
        'disPrice',
        'vendorID',
        'vendorTitle',
        'categoryID',
        'categoryTitle',
        'subcategoryID',
        'subcategoryTitle',
        'photo',
        'description',
        'publish',
        'isAvailable',
        'nonveg',
        'veg',
        'takeawayOption',
        'isStealOfMoment',
        'isTrending',
        'isSeasonal',
        'isBestSeller',
        'isNew',
        'isSpotlight',
        'isFeature',
        'quantity',
        'calories',
        'grams',
        'proteins',
        'fats',
        'addOnsTitle',
        'addOnsPrice',
        'addOnesPrice',
        'product_specification',
        'item_attribute',
        'created_at',
        'updated_at',
        'reviews',
        'rating',
        'reviewSum',
        'reviewCount',
        'price_range',
        'section',
        'max_price',
        'min_price',
        'default_option_id',
        'options_count',
        'has_options',
        'options_toggle',
        'options_enabled',
        'best_value_option',
        'savings_percentage',
        'brandID',
        'brandTitle',
        'options'
    ];

    protected $casts = [
        'publish' => 'boolean',
        'isAvailable' => 'boolean',
        'nonveg' => 'boolean',
        'veg' => 'boolean',
        'takeawayOption' => 'boolean',
        'isStealOfMoment' => 'boolean',
        'isTrending' => 'boolean',
        'isSeasonal' => 'boolean',
        'isBestSeller' => 'boolean',
        'isNew' => 'boolean',
        'isSpotlight' => 'boolean',
        'isFeature' => 'boolean',
        'has_options' => 'boolean',
        'options_toggle' => 'boolean',
        'options_enabled' => 'boolean',
        'price' => 'integer',
        'disPrice' => 'integer',
        'quantity' => 'integer',
        'calories' => 'integer',
        'grams' => 'integer',
        'proteins' => 'integer',
        'fats' => 'integer',
        'reviews' => 'integer',
        'rating' => 'float',
        'savings_percentage' => 'float',
        'max_price' => 'integer',
        'min_price' => 'integer',
        'options_count' => 'integer',
    ];

    // Note: Relationships not defined as we're using denormalized data
    // (vendorTitle, categoryTitle, brandTitle stored directly in table)
}

