<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatRestaurantThread extends Model
{
    protected $table = 'chat_restaurant_thread';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'chat_id',
        'createdAt',
        'videoThumbnail',
        'orderId',
        'receiverId',
        'messageType',
        'message',
        'senderId',
        'url'
    ];

    public function chat()
    {
        return $this->belongsTo(ChatRestaurant::class, 'chat_id', 'id');
    }
}

