<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverSettlement extends Model
{
    use HasFactory;

    protected $table = 'driver_settlements';

    protected $fillable = [
        'settlement_week_id',
        'driver_id',
        'driver_name',
        'driver_phone',
        'total_deliveries',
        'total_distance_km',
        'delivery_earnings',
        'tips_received',
        'incentives',
        'deductions',
        'settlement_amount',
        'transaction_id',
        'payment_date',
        'payment_status',
        'payment_comments',
    ];

    protected $casts = [
        'settlement_week_id' => 'integer',
        'total_deliveries' => 'integer',
        'settlement_amount' => 'decimal:2',
        'payment_date' => 'date',
    ];
}
