<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorAttribute extends Model
{
    protected $table = 'vendor_attributes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'title',
    ];
}


