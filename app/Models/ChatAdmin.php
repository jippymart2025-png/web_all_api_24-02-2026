<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatAdmin extends Model
{
    protected $table = 'chat_admin';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'restaurantProfileImage',
        'lastSenderId',
        'lastMessage',
        'customerProfileImage',
        'restaurantId',
        'createdAt',
        'customerId',
        'restaurantName',
        'orderId',
        'customerName',
        'chatType'
    ];

    public function threads()
    {
        return $this->hasMany(ChatAdminThread::class, 'chat_id', 'id');
    }
}

