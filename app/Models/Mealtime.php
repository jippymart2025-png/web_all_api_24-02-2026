<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mealtime extends Model
{
    protected $table = 'mealtimes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'label',
        'from',
        'to',
        'publish',
    ];
}


