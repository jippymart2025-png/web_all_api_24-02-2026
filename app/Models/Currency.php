<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasFactory;

    protected $table = 'currencies';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'symbol',
        'code',
        'symbolAtRight',
        'name',
        'decimal_degits',
        'isActive'
    ];

    protected $casts = [
        'symbolAtRight' => 'boolean',
        'isActive' => 'boolean',
        'decimal_degits' => 'integer'
    ];
}

