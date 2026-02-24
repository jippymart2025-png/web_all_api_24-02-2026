<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    protected $table = 'menu_items';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'set_order',
        'title',
        'redirect_type',
        'redirect_id',
        'position',
        'photo',
        'is_publish',
        'zoneId',
        'zoneTitle',
    ];
}


