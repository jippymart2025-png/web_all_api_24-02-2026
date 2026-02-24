<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SettlementWeek extends Model
{
    use HasFactory;

    protected $table = 'settlement_weeks';

    /**
     * Mass assignable fields
     */
    protected $fillable = [
        'week_code',
        'settlement_type',        // vendor | driver
        'week_start_date',
        'week_end_date',
        'settlement_date',
        'status',

        // Vendor related totals
        'total_restaurants',
        'total_orders',
        'total_merchant_price',
        'total_customer_paid',
        'total_settlement_amount',
        'total_jippy_profit',

        // Driver related totals
        'total_drivers',
        'total_driver_earnings',
        'total_driver_tips',

        // Approval workflow
        'reviewed_by',
        'reviewed_at',
        'approved_by',
        'approved_at',
    ];

    /**
     * Attribute casting
     */
    protected $casts = [
        'week_start_date'           => 'date',
        'week_end_date'             => 'date',
        'settlement_date'           => 'date',

        'reviewed_at'               => 'datetime',
        'approved_at'               => 'datetime',

        'total_merchant_price'      => 'decimal:2',
        'total_customer_paid'       => 'decimal:2',
        'total_settlement_amount'   => 'decimal:2',
        'total_driver_earnings'     => 'decimal:2',
        'total_driver_tips'         => 'decimal:2',
        'total_jippy_profit'        => 'decimal:2',
    ];

    /* ============================================================
     | SCOPES
     |============================================================ */

    public function scopeVendor($query)
    {
        return $query->where('settlement_type', 'vendor');
    }

    public function scopeDriver($query)
    {
        return $query->where('settlement_type', 'driver');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /* ============================================================
     | RELATIONSHIPS
     |============================================================ */

    public function vendorSettlements()
    {
        return $this->hasMany(VendorSettlement::class, 'settlement_week_id');
    }

    public function driverSettlements()
    {
        return $this->hasMany(DriverSettlement::class, 'settlement_week_id');
    }
}
