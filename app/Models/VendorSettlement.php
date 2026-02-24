<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorSettlement extends Model
{
    use HasFactory;

    protected $table = 'vendor_settlements';

    protected $fillable = [
        'settlement_week_id',
        'vendor_id',
        'vendor_name',
        'subscription_plan_id',
        'subscription_plan_name',
        'jippy_percentage',
        'total_orders',
        'total_merchant_price',
        'total_customer_paid',
        'total_jippy_commission',
        'settlement_amount',
        'transaction_id',
        'payment_date',
        'payment_status',
        'payment_comments',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'settlement_week_id' => 'integer',
        'jippy_percentage' => 'decimal:2',
        'total_orders' => 'integer',
        'total_merchant_price' => 'decimal:2',
        'total_customer_paid' => 'decimal:2',
        'total_jippy_commission' => 'decimal:2',
        'settlement_amount' => 'decimal:2',
        'payment_date' => 'date',
        'verified_at' => 'datetime',
    ];
}
