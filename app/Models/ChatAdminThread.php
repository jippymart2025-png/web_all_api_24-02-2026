<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatAdminThread extends Model
{
    protected $table = 'chat_admin_thread';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'chat_id',
        'orderId',
        'message',
        'videoThumbnail',
        'receiverId',
        'senderId',
        'createdAt',
        'url',
        'messageType'
    ];

    public function chat()
    {
        return $this->belongsTo(ChatAdmin::class, 'chat_id', 'id');
    }
}

