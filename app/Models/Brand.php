<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    protected $table = 'brands';
    
    public $incrementing = false;
    
    protected $keyType = 'string';
    
    protected $fillable = [
        'id',
        'name',
        'slug',
        'description',
        'logo_url',
        'status',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'status' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get mart items that use this brand
     */
    public function martItems()
    {
        return $this->hasMany(\App\Models\MartItem::class, 'brand_id', 'id');
    }

    /**
     * Scope to get only active brands
     */
    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    /**
     * Scope to get only inactive brands
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 0);
    }
}

