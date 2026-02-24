<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewAttribute extends Model
{
    protected $table = 'review_attributes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'title',
    ];
}


