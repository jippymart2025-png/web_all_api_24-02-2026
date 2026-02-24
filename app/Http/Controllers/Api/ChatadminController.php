<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatAdmin;
use App\Models\ChatAdminThread;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChatadminController extends Controller
{
    /**
     * Add or update a restaurant chat inbox
     */
    public function addInbox(Request $request)
    {
        // Validate incoming request
        $validated = $request->validate([
            'id' => 'nullable|string', // optional now
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

        // Auto-generate ID if not provided
        $chatId = $validated['id'] ?? (string) Str::uuid();

        // Add or update chat inbox
        $chat = ChatAdmin::updateOrCreate(
            ['id' => $chatId],
            array_merge($validated, ['id' => $chatId, 'createdAt' => $validated['createdAt'] ?? now()])
        );

        return response()->json([
            'success' => true,
            'data' => $chat
        ]);
    }


    /**
     * Add a chat message (thread)
     */
    public function addThread(Request $request)
    {
        // Validate incoming request
        $validated = $request->validate([
            'chat_id' => 'required|string',        // Parent chat ID
            'orderId' => 'required|string',
            'senderId' => 'nullable|string',
            'receiverId' => 'nullable|string',
            'message' => 'nullable|string',
            'messageType' => 'nullable|string',   // text, image, video
            'videoThumbnail' => 'nullable|string',
            'url' => 'nullable|string',           // JSON string
            'createdAt' => 'nullable|date',
        ]);

        // Generate a unique ID for the thread
        $threadId = (string) Str::uuid();

        // Create the chat thread
        $thread = ChatAdminThread::create([
            'id' => $threadId,
            'chat_id' => $validated['chat_id'],
            'orderId' => $validated['orderId'],
            'senderId' => $validated['senderId'] ?? null,
            'receiverId' => $validated['receiverId'] ?? null,
            'message' => $validated['message'] ?? null,
            'messageType' => $validated['messageType'] ?? 'text',
            'videoThumbnail' => $validated['videoThumbnail'] ?? null,
            'url' => $validated['url'] ?? null,
            'createdAt' => $validated['createdAt'] ?? now(),
        ]);

        // Update last message in parent chat (ChatAdmin)
        ChatAdmin::where('id', $validated['chat_id'])
            ->update([
                'lastMessage' => $validated['message'] ?? '',
                'lastSenderId' => $validated['senderId'] ?? '',
                'createdAt' => now(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $thread
        ]);
    }

    /**
     * Get inbox by ID with all threads
     */
    public function getInbox($id)
    {
        $chat = ChatAdmin::with('threads')->find($id);

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
        $threads = ChatAdminThread::where('chat_id', $chatId)
            ->orderBy('createdAt', 'asc')
            ->get();

        return response()->json(['success' => true, 'data' => $threads]);
    }


}
