<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class zone_bonus_settings extends Model
{
    protected $table = "zone_bonus_settings"; // your SQL table

    protected $fillable = [
        'firebase_id',
        'bonusAmount',
        'isActive',
        'requiredOrdersForBonus',
        'zoneId',
        'zoneName',
        'createdAt',
        'updatedAt'
    ];

    public $timestamps = false; // Because you're using createdAt & updatedAt manually

    protected $casts = [
        'isActive' => 'boolean',
        'bonusAmount' => 'integer',
        'requiredOrdersForBonus' => 'integer',
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
    ];
}
