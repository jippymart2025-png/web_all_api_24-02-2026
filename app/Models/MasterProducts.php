<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterProducts extends Model
{
    use HasFactory;

    protected $table = 'master_products';

    protected $fillable = [
    'categoryID',
    'categoryTitle',
    'subcategoryID',
    'subcategoryTitle',
    'name',
    'description',
    'short_description',
    'photo',
    'photos',
    'thumbnail',
    'veg',
    'nonveg',
    'food_type',
    'cuisine_type',
    'calories',
    'proteins',
    'fats',
    'carbs',
    'grams',
    'suggested_price',
    'dis_price',
    'tags',
    'display_order',
    'is_recommended',
    'isAvailable',
    'publish',

    // ✅ OPTIONS (ADD)
    'has_options',
    'options_enabled',
    'options',
    'min_price',
    'max_price',
];

    protected $casts = [
        'veg' => 'boolean',
        'nonveg' => 'boolean',
        'is_recommended' => 'boolean',
        'isAvailable' => 'boolean',
        'publish' => 'boolean',
        'calories' => 'integer',
        'proteins' => 'integer',
        'fats' => 'integer',
        'carbs' => 'integer',
        'grams' => 'integer',
        'display_order' => 'integer',
        'suggested_price' => 'decimal:2',
        'dis_price' => 'decimal:2',
        'photos' => 'array',
        'options' => 'array',
        'has_options' => 'boolean',
        'options_enabled' => 'boolean',
        'min_price' => 'integer',
        'max_price' => 'integer',
    ];
}
