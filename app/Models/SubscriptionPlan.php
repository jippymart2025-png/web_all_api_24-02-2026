<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $table = 'subscription_plans';

    /**
     * Table uses string primary keys (UUID/ID strings) and no default timestamps.
     */
    public $incrementing = false;
    public $timestamps = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'image',
        'itemLimit',
        'orderLimit',
        'description',
        'plan_points',
        'type',
        'isEnable',
        'createdAt',
        'features',
        'price',
        'name',
        'expiryDay',
        'place',
        'zone'
    ];

    protected $casts = [
        'isEnable' => 'boolean',
        'plan_points' => 'array',
        'features' => 'array',
        'price' => 'float',
        'expiryDay' => 'integer',
    ];

    /**
     * Scope: only enabled plans (isEnable = 1 or true).
     * Commission plan must also be enabled to be shown.
     */
    public function scopeEnabled($query)
    {
        return $query->where('isEnable', 1);
    }
}

