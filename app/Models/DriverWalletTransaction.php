<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DriverWalletTransaction extends Model
{
    use HasFactory;

    // Table name
    protected $table = 'delivery_wallet_record'; // update if your table name differs

    // Primary key
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    // Fillable fields for mass assignment
    protected $fillable = [
        'firebase_id',
        'bonus',
        'bonusAmount',
        'date',
        'driverId',
        'totalEarnings',
        'type',
        'zoneId',
    ];

    // Casts
    protected $casts = [
        'bonus' => 'boolean',
        'bonusAmount' => 'float',
        'totalEarnings' => 'float',
        'date' => 'datetime',
    ];

    // Optional: Accessor for formatted date
    public function getFormattedDateAttribute()
    {
        return $this->date ? $this->date->format('Y-m-d H:i:s') : null;
    }
}
