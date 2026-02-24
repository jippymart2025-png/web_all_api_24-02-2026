<?php

namespace App\Models;

use App\Models;
use Illuminate\Database\Eloquent\Model;


class documents_verify extends Model
{
    protected $table = 'documents_verify';

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'type', 'documents'
    ];

    protected $casts = [
        'documents' => 'array'
    ];
}
