<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    protected $fillable = [
        'admin_id',
        'admin_name',
        'action',
        'resource_type',
        'resource_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'additional_data',
        'user_id',
        'user_name',
        'user_type',
        'role',
        'module',
        'description',
        'timestamp'
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'additional_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Allow mass assignment for all tracking fields
    protected $guarded = [];

    // Scope for filtering by module
    public function scopeByModule($query, $module)
    {
        if (!empty($module)) {
            return $query->where('module', $module);
        }
        return $query;
    }

    // Scope for searching
    public function scopeSearch($query, $searchValue)
    {
        if (!empty($searchValue)) {
            return $query->where(function($q) use ($searchValue) {
                $q->where('user_id', 'like', '%' . $searchValue . '%')
                  ->orWhere('user_name', 'like', '%' . $searchValue . '%')
                  ->orWhere('user_type', 'like', '%' . $searchValue . '%')
                  ->orWhere('role', 'like', '%' . $searchValue . '%')
                  ->orWhere('module', 'like', '%' . $searchValue . '%')
                  ->orWhere('action', 'like', '%' . $searchValue . '%')
                  ->orWhere('description', 'like', '%' . $searchValue . '%')
                  ->orWhere('ip_address', 'like', '%' . $searchValue . '%');
            });
        }
        return $query;
    }
}


