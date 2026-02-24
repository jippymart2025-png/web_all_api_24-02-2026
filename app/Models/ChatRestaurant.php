<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatRestaurant extends Model
{
    protected $table = 'chat_restaurant';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'restaurantName',
        'customerName',
        'orderId',
        'lastSenderId',
        'customerProfileImage',
        'customerId',
        'restaurantProfileImage',
        'lastMessage',
        'createdAt',
        'restaurantId',
        'chatType'
    ];

    public function threads()
    {
        return $this->hasMany(ChatRestaurantThread::class, 'chat_id', 'id');
    }
}

