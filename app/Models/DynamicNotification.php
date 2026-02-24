<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DynamicNotification extends Model
{
    protected $table = 'dynamic_notification';

    public $timestamps = false; // Using custom createdAt field

    // Since ID is not auto-increment
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'createdAt',
        'subject',
        'message',
        'type',
    ];

    // No casts needed for this model as all fields are strings
}

