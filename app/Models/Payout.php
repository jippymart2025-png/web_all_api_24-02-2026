<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payout extends Model
{
    use HasFactory;

    protected $table = 'payouts';
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
        'vendorID',
        'adminNote',
        'paymentStatus',
        'payoutResponse'
    ];

    protected $casts = [
        'amount' => 'integer'
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendorID', 'id');
    }
}

