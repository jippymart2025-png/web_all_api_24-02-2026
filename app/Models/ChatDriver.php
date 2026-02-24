<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatDriver extends Model
{
    protected $table = 'chat_driver';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'lastSenderId',
        'restaurantId',
        'customerProfileImage',
        'customerId',
        'createdAt',
        'lastMessage',
        'orderId',
        'chatType',
        'restaurantName',
        'customerName',
        'restaurantProfileImage'
    ];

    public function threads()
    {
        return $this->hasMany(ChatDriverThread::class, 'chat_id', 'id');
    }
}

