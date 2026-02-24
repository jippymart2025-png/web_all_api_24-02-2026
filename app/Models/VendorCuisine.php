<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorCuisine extends Model
{
    protected $table = 'vendor_cuisines';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'title',
        'description',
        'photo',
        'review_attributes',
        'publish',
        'show_in_homepage',
        'image',
    ];
}


