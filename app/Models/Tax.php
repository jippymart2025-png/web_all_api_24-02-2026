<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tax extends Model
{
    protected $table = 'tax'; // Table name is 'tax' (singular)

    // Disable timestamps - table doesn't have created_at/updated_at
    public $timestamps = false;
    
    // Use string for primary key since id is VARCHAR
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'country',
        'tax',
        'title',
        'type',
        'enable',
    ];

    protected $casts = [
        'enable' => 'boolean',
        // tax is stored as string in database, so keep it as string for now
    ];

    /**
     * Scope for filtering by enabled status
     */
    public function scopeEnabled($query)
    {
        return $query->where('enable', true);
    }

    /**
     * Scope for filtering by type
     */
    public function scopeByType($query, $type)
    {
        if (!empty($type)) {
            return $query->where('type', $type);
        }
        return $query;
    }

    /**
     * Scope for searching
     */
    public function scopeSearch($query, $searchValue)
    {
        if (!empty($searchValue)) {
            return $query->where(function($q) use ($searchValue) {
                $q->where('title', 'like', '%' . $searchValue . '%')
                  ->orWhere('country', 'like', '%' . $searchValue . '%')
                  ->orWhere('type', 'like', '%' . $searchValue . '%');
            });
        }
        return $query;
    }
}

