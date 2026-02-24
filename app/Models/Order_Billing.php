<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order_Billing extends Model
{
    use HasFactory;

    // Table name
    protected $table = 'order_billing';

    // Primary key
    protected $primaryKey = 'id';

    // Primary key is non-incrementing string
    public $incrementing = false;

    protected $keyType = 'string';

    // Fillable fields
    protected $fillable = [
        'id',
        'createdAt',
        'orderId',
        'ToPay',
        'serge_fee',
        'total_surge_fee',
        'surge_fee',
        'admin_surge_fee',
    ];

    // Disable Laravel timestamps since you have custom createdAt
    public $timestamps = false;

    /**
     * Optional: relationship to order
     */
    public function order()
    {
        return $this->belongsTo(RestaurantOrder::class, 'orderId', 'id');
    }
}
