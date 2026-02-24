<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatDriverThread extends Model
{
    protected $table = 'chat_driver_thread';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'chat_id',
        'messageType',
        'url',
        'createdAt',
        'receiverId',
        'videoThumbnail',
        'message',
        'senderId',
        'orderId'
    ];

    public function chat()
    {
        return $this->belongsTo(ChatDriver::class, 'chat_id', 'id');
    }
}

