<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MartBanner extends Model
{
    protected $table = 'mart_banners';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'position',
        'created_at',
        'storeId',
        'title',
        'is_publish',
        'photo',
        'screen',
        'zoneId',
        'zoneTitle',
        'external_link',
        'ads_link',
        'productId',
        'martCategoryId',
        'redirect_type',
        'set_order',
        'updated_at',
        'description',
        'text',
    ];
}


