<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverPayout extends Model
{
    use HasFactory;

    protected $table = 'driver_payouts';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'note',
        'amount',
        'withdrawMethod',
        'paidDate',
        'driverID',
        'vendorID',
        'adminNote',
        'paymentStatus'
    ];

    protected $casts = [
        'amount' => 'float'
    ];

    public function driver()
    {
        return $this->belongsTo(AppUser::class, 'driverID', 'id');
    }
}

