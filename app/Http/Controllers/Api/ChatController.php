<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Image;
use App\Models\ChatAdmin;
use App\Models\ChatAdminThread;
use App\Models\ChatDriver;
use App\Models\ChatDriverThread;
use App\Models\ChatRestaurant;
use App\Models\ChatRestaurantThread;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ChatController extends Controller
{
    /**
     * Get the appropriate models based on chat type
     */
    private function getTables($chatType)
    {
        switch ($chatType) {
            case 'admin':
                return [
                    'main' => new ChatAdmin(),
                    'thread' => new ChatAdminThread()
                ];
            case 'driver':
                return [
                    'main' => new ChatDriver(),
                    'thread' => new ChatDriverThread()
                ];
            case 'restaurant':
                return [
                    'main' => new ChatRestaurant(),
                    'thread' => new ChatRestaurantThread()
                ];
            default:
                return null;
        }
    }

    /**
     * Generate unique ID for chat records
     */
    private function generateId()
    {
        return uniqid() . '_' . time();
    }

    /**
     * Format date for storage
     */
    private function formatDate($date = null)
    {
        if ($date) {
            return is_string($date) ? $date : $date->toIso8601String();
        }
        return now()->toIso8601String();
    }

    // --------------------------------------
    // GET CHAT INBOX (List of all chats)
    // --------------------------------------
    public function getInbox(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'chat_type' => 'required|in:admin,driver,restaurant',
            'user_id' => 'sometimes|string', // Optional: filter by user
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|max:50'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $tables = $this->getTables($request->chat_type);
        if (!$tables) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid chat type'
            ], 400);
        }

        $main = $tables['main'];
        $perPage = $request->per_page ?? 20;

        $query = $main->query();

        // Filter by user if provided
        if ($request->user_id) {
            if ($request->chat_type == 'admin') {
                $query->where(function($q) use ($request) {
                    $q->where('customerId', $request->user_id)
                      ->orWhere('restaurantId', $request->user_id);
                });
            } elseif ($request->chat_type == 'driver') {
                $query->where('customerId', $request->user_id);
            } elseif ($request->chat_type == 'restaurant') {
                $query->where(function($q) use ($request) {
                    $q->where('customerId', $request->user_id)
                      ->orWhere('restaurantId', $request->user_id);
                });
            }
        }

        $chats = $query->orderBy('createdAt', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => true,
            'data' => $chats
        ]);
    }

    // --------------------------------------
    // GET CHAT MESSAGES (Thread messages)
    // --------------------------------------
    public function getMessages($orderId, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'chat_type' => 'required|in:admin,driver,restaurant',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|max:50'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $tables = $this->getTables($request->chat_type);
        if (!$tables) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid chat type'
            ], 400);
        }

        $thread = $tables['thread'];
        $perPage = $request->per_page ?? 20;

        $messages = $thread->where('orderId', $orderId)
            ->orderBy('createdAt', 'asc')
            ->paginate($perPage);

        return response()->json([
            'status' => true,
            'data' => $messages
        ]);
    }

    // --------------------------------------
    // GET SINGLE CHAT BY ORDER ID
    // --------------------------------------
    public function getChat($orderId, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'chat_type' => 'required|in:admin,driver,restaurant'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $tables = $this->getTables($request->chat_type);
        if (!$tables) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid chat type'
            ], 400);
        }

        $main = $tables['main'];
        $thread = $tables['thread'];

        $chat = $main->where('orderId', $orderId)->first();

        if (!$chat) {
            return response()->json([
                'status' => false,
                'message' => 'Chat not found'
            ], 404);
        }

        $messages = $thread->where('orderId', $orderId)
            ->orderBy('createdAt', 'asc')
            ->get();

        return response()->json([
            'status' => true,
            'data' => [
                'chat' => $chat,
                'messages' => $messages
            ]
        ]);
    }

    // --------------------------------------
    // SEND MESSAGE
    // --------------------------------------
    public function sendMessage($orderId, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sender_id' => 'required|string',
            'receiver_id' => 'required|string',
            'message_type' => 'required|in:text,image,video',
            'message' => 'required_if:message_type,text|nullable|string',
            'file_url' => 'required_if:message_type,image,video|nullable|string',
            'thumbnail_url' => 'required_if:message_type,video|nullable|string',
            'chat_type' => 'required|in:admin,driver,restaurant',
            // Additional fields for admin chat
            'restaurant_id' => 'required_if:chat_type,admin|nullable|string',
            'customer_id' => 'sometimes|string',
            'restaurant_name' => 'sometimes|string',
            'customer_name' => 'sometimes|string',
            'restaurant_profile_image' => 'sometimes|string',
            'customer_profile_image' => 'sometimes|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $tables = $this->getTables($request->chat_type);
        if (!$tables) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid chat type'
            ], 400);
        }

        $main = $tables['main'];
        $thread = $tables['thread'];

        // Find or create main chat record
        $chat = $main->where('orderId', $orderId)->first();

        if (!$chat) {
            $chatData = [
                'id' => $this->generateId(),
                'orderId' => $orderId,
                'lastSenderId' => $request->sender_id,
                'lastMessage' => $request->message ?? ($request->message_type == 'image' ? 'sent a message' : ($request->message_type == 'video' ? 'sent a message' : '')),
                'createdAt' => $this->formatDate(),
                'chatType' => $request->chat_type
            ];

            // Add type-specific fields
            if ($request->chat_type == 'admin') {
                $chatData['restaurantId'] = $request->restaurant_id;
                $chatData['customerId'] = $request->customer_id ?? 'admin';
                $chatData['restaurantName'] = $request->restaurant_name ?? '';
                $chatData['customerName'] = $request->customer_name ?? 'Admin';
                $chatData['restaurantProfileImage'] = $request->restaurant_profile_image ?? '';
                $chatData['customerProfileImage'] = $request->customer_profile_image ?? '';
            } elseif ($request->chat_type == 'driver') {
                $chatData['restaurantId'] = $request->restaurant_id ?? '';
                $chatData['customerId'] = $request->customer_id ?? '';
                $chatData['restaurantName'] = $request->restaurant_name ?? '';
                $chatData['customerName'] = $request->customer_name ?? '';
                $chatData['restaurantProfileImage'] = $request->restaurant_profile_image ?? '';
                $chatData['customerProfileImage'] = $request->customer_profile_image ?? '';
            } elseif ($request->chat_type == 'restaurant') {
                $chatData['restaurantId'] = $request->restaurant_id ?? '';
                $chatData['customerId'] = $request->customer_id ?? '';
                $chatData['restaurantName'] = $request->restaurant_name ?? '';
                $chatData['customerName'] = $request->customer_name ?? '';
                $chatData['restaurantProfileImage'] = $request->restaurant_profile_image ?? '';
                $chatData['customerProfileImage'] = $request->customer_profile_image ?? '';
            }

            $chat = $main->create($chatData);
        }

        // Prepare message data
        $messageData = [
            'id' => $this->generateId(),
            'chat_id' => $chat->id,
            'orderId' => $orderId,
            'messageType' => $request->message_type,
            'senderId' => $request->sender_id,
            'receiverId' => $request->receiver_id,
            'createdAt' => $this->formatDate()
        ];

        if ($request->message_type == 'text') {
            $messageData['message'] = $request->message;
            $messageData['url'] = 'null';
            $messageData['videoThumbnail'] = '';
        } elseif ($request->message_type == 'image') {
            $messageData['message'] = 'sent a message';
            $messageData['url'] = $request->file_url;
            $messageData['videoThumbnail'] = '';
        } elseif ($request->message_type == 'video') {
            $messageData['message'] = 'sent a message';
            $messageData['url'] = $request->file_url;
            $messageData['videoThumbnail'] = $request->thumbnail_url ?? '';
        }

        // Insert thread message
        $msg = $thread->create($messageData);

        // Update main chat
        $updateData = [
            'lastMessage' => $request->message ?? ($request->message_type == 'image' || $request->message_type == 'video' ? 'sent a message' : ''),
            'lastSenderId' => $request->sender_id,
            'createdAt' => $this->formatDate()
        ];

        $chat->update($updateData);

        return response()->json([
            'status' => true,
            'message' => 'Message sent successfully',
            'data' => $msg
        ]);
    }

    // --------------------------------------
    // UPLOAD IMAGE
    // --------------------------------------
    public function uploadImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|max:5120', // 5MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $image = $request->file('image');
            $fileName = 'chat_images/' . uniqid() . '_' . time() . '.' . $image->getClientOriginalExtension();

            // Resize image while maintaining aspect ratio
            $img = Image::make($image)->resize(900, 900, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });

            Storage::disk('public')->put($fileName, $img->stream());

            return response()->json([
                'status' => true,
                'data' => [
                    'url' => Storage::url($fileName),
                    'mime' => $image->getMimeType()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to upload image: ' . $e->getMessage()
            ], 500);
        }
    }

    // --------------------------------------
    // UPLOAD VIDEO
    // --------------------------------------
    public function uploadVideo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'video' => 'required|mimetypes:video/mp4,video/quicktime,video/x-msvideo|max:20480' // 20MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $video = $request->file('video');
            $fileName = 'chat_videos/' . uniqid() . '_' . time() . '.' . $video->getClientOriginalExtension();

            Storage::disk('public')->put($fileName, file_get_contents($video));

            // Generate thumbnail if possible (requires ffmpeg or similar)
            $thumbnailUrl = null;
            // You can add thumbnail generation logic here if needed

            return response()->json([
                'status' => true,
                'data' => [
                    'url' => Storage::url($fileName),
                    'video_url' => Storage::url($fileName),
                    'thumbnail_url' => $thumbnailUrl,
                    'mime' => $video->getMimeType()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to upload video: ' . $e->getMessage()
            ], 500);
        }
    }

    // --------------------------------------
    // DELETE MESSAGE
    // --------------------------------------
    public function deleteMessage($messageId, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'chat_type' => 'required|in:admin,driver,restaurant'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $tables = $this->getTables($request->chat_type);
        if (!$tables) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid chat type'
            ], 400);
        }

        $thread = $tables['thread'];
        $message = $thread->find($messageId);

        if (!$message) {
            return response()->json([
                'status' => false,
                'message' => 'Message not found'
            ], 404);
        }

        $message->delete();

        return response()->json([
            'status' => true,
            'message' => 'Message deleted successfully'
        ]);
    }

    // --------------------------------------
    // DELETE CHAT
    // --------------------------------------
    public function deleteChat($orderId, Request $request)
    {
        $validator = Validator::make($request->all(), [
            'chat_type' => 'required|in:admin,driver,restaurant'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $tables = $this->getTables($request->chat_type);
        if (!$tables) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid chat type'
            ], 400);
        }

        $main = $tables['main'];
        $thread = $tables['thread'];

        $chat = $main->where('orderId', $orderId)->first();

        if (!$chat) {
            return response()->json([
                'status' => false,
                'message' => 'Chat not found'
            ], 404);
        }

        // Delete all messages
        $thread->where('orderId', $orderId)->delete();

        // Delete chat
        $chat->delete();

        return response()->json([
            'status' => true,
            'message' => 'Chat deleted successfully'
        ]);
    }


    //restaurant api

    public function getAdminChats(Request $request)
    {
        $request->validate([
            'restaurantId' => 'required|string'
        ]);

        $messages = ChatAdmin::where('chatType', 'admin')
            ->where('restaurantId', $request->restaurantId)
            ->orderBy('createdAt', 'DESC')
            ->get();

        return response()->json([
            "success" => true,
            "data" => $messages
        ]);
    }


    public function getRestaurantChats(Request $request)
    {
        $request->validate([
            'restaurantId' => 'required|string'
        ]);

        $messages = ChatRestaurant::where('chatType', 'restaurant')
            ->where('restaurantId', $request->restaurantId)
            ->orderBy('createdAt', 'DESC')
            ->get();

        return response()->json([
            "success" => true,
            "data" => $messages
        ]);
    }





}

