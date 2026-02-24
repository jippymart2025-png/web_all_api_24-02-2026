<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatDriver;
use App\Models\ChatDriverThread;
use Illuminate\Http\Request;
use App\Models\ChatRestaurantThread;

class ChatDriverContoller extends Controller
{
    /**
     * Add or update a restaurant chat inbox
     */
    public function addInbox(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|string',
            'restaurantName' => 'nullable|string',
            'customerName' => 'nullable|string',
            'orderId' => 'required|string',
            'lastSenderId' => 'nullable|string',
            'customerProfileImage' => 'nullable|string',
            'customerId' => 'nullable|string',
            'restaurantProfileImage' => 'nullable|string',
            'lastMessage' => 'nullable|string',
            'createdAt' => 'nullable|date',
            'restaurantId' => 'nullable|string',
            'chatType' => 'nullable|string',
        ]);

        $chat = ChatDriver::updateOrCreate(
            ['id' => $validated['id']],
            $validated
        );

        return response()->json(['success' => true, 'data' => $chat]);
    }

    /**
     * Add a chat message (thread)
     */
    public function addThread(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|string',
            'chat_id' => 'required|string',
            'orderId' => 'required|string',
            'senderId' => 'nullable|string',
            'receiverId' => 'nullable|string',
            'message' => 'nullable|string',
            'messageType' => 'nullable|string',
            'videoThumbnail' => 'nullable|string',
            'url' => 'nullable|string', // store JSON string
            'createdAt' => 'nullable|date',
        ]);

        $thread = ChatDriverThread::create($validated);

        // Update last message in inbox
        ChatDriver::where('id', $validated['chat_id'])
            ->update([
                'lastMessage' => $validated['message'],
                'lastSenderId' => $validated['senderId'],
                'createdAt' => now()
            ]);

        return response()->json(['success' => true, 'data' => $thread]);
    }

    /**
     * Get inbox by ID with all threads
     */
    public function getInbox($id)
    {
        $chat = ChatDriver::with('threads')->find($id);

        if (!$chat) {
            return response()->json(['success' => false, 'message' => 'Chat not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $chat]);
    }

    /**
     * Get chat threads by chat_id
     */
    public function getThreads($chatId)
    {
        $threads = ChatDriverThread::where('chat_id', $chatId)
            ->orderBy('createdAt', 'asc')
            ->get();

        return response()->json(['success' => true, 'data' => $threads]);
    }
}
