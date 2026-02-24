<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\restaurant_orders;
use App\Models\VendorProduct;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FirestoreBridgeController extends Controller
{
    /**
     * Acceptable truthy string representations.
     */
    protected array $truthyStrings = ['1', 'true', 'yes', 'y', 'on', 'available', 'published', 'enabled'];

    /**
     * Retrieve Razorpay settings.
     */
    public function getRazorpaySettings(): \Illuminate\Http\JsonResponse
    {
        return $this->getSettingsDocument('razorpaySettings');
    }

    /**
     * Retrieve COD settings.
     */
    public function getCodSettings(): \Illuminate\Http\JsonResponse
    {
        return $this->getSettingsDocument('CODSettings');
    }

    /**
     * Upsert a vendor product.
     */
    public function setProduct(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'id' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors()->toArray());
        }

        $productId = $data['id'];

        /** @var VendorProduct $product */
        $product = VendorProduct::query()->find($productId) ?? new VendorProduct();
        $product->id = $productId;

        $payload = $this->prepareProductPayload($data);

        $product->fill($payload);
        $product->save();

        $freshProduct = VendorProduct::query()->findOrFail($productId);

        return $this->success($this->mapBasicProduct($freshProduct), 'Product saved');
    }

    /**
     * Fetch all orders for a vendor author.
     */
    public function getAllOrders(Request $request): \Illuminate\Http\JsonResponse
    {
        $authorId = $request->query('author_id');
        if (!$authorId) {
            return $this->error('author_id query parameter is required', 422);
        }

        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min($limit, 200));

        // Fetch orders
        $orders = DB::table('restaurant_orders')
            ->where('authorID', $authorId)
            ->orderByDesc(DB::raw("COALESCE(createdAt, '1970-01-01T00:00:00Z')"))
            ->limit($limit)
            ->get()
            ->map(function ($row) {

                $order = (array) $row;

                // Decode JSON fields in order if any
                $orderJsonFields = ['specialDiscount', 'calculatedCharges', 'products', 'address', 'taxSetting'];
                foreach ($orderJsonFields as $field) {
                    if (isset($order[$field]) && is_string($order[$field])) {
                        $order[$field] = json_decode($order[$field], true);
                    }
                }

                // Fetch vendor data
                $vendor = DB::table('vendors')
                    ->where('id', $order['vendorID'])
                    ->first();

                if ($vendor) {
                    $vendor = (array) $vendor;

                    // Decode all relevant vendor JSON fields
                    $vendorJsonFields = [
                        'photos', 'categoryID', 'workingHours', 'restaurantMenuPhotos',
                        'categoryTitle', 'filters', 'specialDiscount', 'adminCommission',
                        'g', 'coordinates', 'lastAutoScheduleUpdate'
                    ];

                    foreach ($vendorJsonFields as $field) {
                        if (isset($vendor[$field]) && is_string($vendor[$field])) {
                            $vendor[$field] = json_decode($vendor[$field], true);
                        }
                    }

                    $order['vendor'] = $vendor;
                } else {
                    $order['vendor'] = null;
                }

                // Map the order row if you have other mapping logic
                return $this->mapOrderRow($order);
            });

        return $this->success([
            'orders' => $orders,
            'count' => $orders->count(),
        ]);
    }

    /**
     * Retrieve an email template by type.
     */
    public function getEmailTemplates(string $type): \Illuminate\Http\JsonResponse
    {
        $template = DB::table('email_templates')
            ->where('type', $type)
            ->orderByDesc('createdAt')
            ->first();

        if (!$template) {
            return $this->error('Email template not found', 404);
        }

        return $this->success([
            'id' => $template->id,
            'createdAt' => $template->createdAt,
            'isSendToAdmin' => $this->coerceBoolean($template->isSendToAdmin),
            'subject' => $template->subject,
            'type' => $template->type,
            'message' => $template->message,
        ]);
    }

    /**
     * Retrieve notification content by type.
     */
    public function getNotificationContent(string $type): \Illuminate\Http\JsonResponse
    {
        $notification = DB::table('dynamic_notification')
            ->where('type', $type)
            ->orderByDesc('createdAt')
            ->first();

        if (!$notification) {
            return $this->success([
                'id' => null,
                'type' => $type,
                'subject' => 'Notification setup is pending',
                'message' => 'Notification setup is pending',
                'createdAt' => null,
            ], 'Notification fallback');
        }

        return $this->success([
            'id' => $notification->id,
            'createdAt' => $notification->createdAt,
            'subject' => $notification->subject,
            'message' => $notification->message,
            'type' => $notification->type,
        ]);
    }

    /**
     * Create or update a driver chat inbox entry.
     */
    public function addDriverInbox(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'order_id' => ['required', 'string'],
            'chat_type' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors()->toArray());
        }

        $id = $data['order_id'];

        DB::table('chat_driver')->updateOrInsert(
            ['id' => $id],
            [
                'orderId' => $id,
                'restaurantId' => $data['restaurant_id'] ?? null,
                'restaurantName' => $data['restaurant_name'] ?? null,
                'restaurantProfileImage' => $data['restaurant_profile_image'] ?? null,
                'customerId' => $data['customer_id'] ?? null,
                'customerName' => $data['customer_name'] ?? null,
                'customerProfileImage' => $data['customer_profile_image'] ?? null,
                'lastSenderId' => $data['last_sender_id'] ?? null,
                'lastMessage' => $data['last_message'] ?? null,
                'createdAt' => $data['created_at'] ?? Carbon::now()->toIso8601String(),
                'chatType' => $data['chat_type'] ?? 'Driver',
            ]
        );

        return $this->success(['id' => $id], 'Driver inbox stored');
    }

    /**
     * Add a driver chat message.
     */
    public function addDriverChat(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'chat_id' => ['required', 'string'],
            'order_id' => ['required', 'string'],
            'sender_id' => ['required', 'string'],
            'receiver_id' => ['nullable', 'string'],
            'message_type' => ['required', 'string'],
            'message' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors()->toArray());
        }

        $messageId = $data['id'] ?? (string) Str::uuid();

        DB::table('chat_driver_thread')->updateOrInsert(
            ['id' => $messageId],
            [
                'chat_id' => $data['chat_id'],
                'orderId' => $data['order_id'],
                'senderId' => $data['sender_id'],
                'receiverId' => $data['receiver_id'] ?? null,
                'messageType' => $data['message_type'],
                'message' => $data['message'] ?? null,
                'url' => $data['url'] ?? null,
                'videoThumbnail' => $data['video_thumbnail'] ?? null,
                'createdAt' => $data['created_at'] ?? Carbon::now()->toIso8601String(),
            ]
        );

        return $this->success(['id' => $messageId], 'Driver chat message stored');
    }

    /**
     * Create or update a restaurant chat inbox entry.
     */
    public function addRestaurantInbox(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'order_id' => ['required', 'string'],
            'restaurant_id' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors()->toArray());
        }

        $id = $data['order_id'];

        DB::table('chat_restaurant')->updateOrInsert(
            ['id' => $id],
            [
                'orderId' => $id,
                'restaurantId' => $data['restaurant_id'],
                'restaurantName' => $data['restaurant_name'] ?? null,
                'restaurantProfileImage' => $data['restaurant_profile_image'] ?? null,
                'customerId' => $data['customer_id'] ?? null,
                'customerName' => $data['customer_name'] ?? null,
                'customerProfileImage' => $data['customer_profile_image'] ?? null,
                'lastSenderId' => $data['last_sender_id'] ?? null,
                'lastMessage' => $data['last_message'] ?? null,
                'createdAt' => $data['created_at'] ?? Carbon::now()->toIso8601String(),
                'chatType' => $data['chat_type'] ?? 'restaurant',
            ]
        );

        return $this->success(['id' => $id], 'Restaurant inbox stored');
    }

    /**
     * Add a restaurant chat message.
     */
    public function addRestaurantChat(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->all();

        $validator = Validator::make($data, [
            'chat_id' => ['required', 'string'],
            'order_id' => ['required', 'string'],
            'sender_id' => ['required', 'string'],
            'receiver_id' => ['nullable', 'string'],
            'message_type' => ['required', 'string'],
            'message' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors()->toArray());
        }

        $messageId = $data['id'] ?? (string) Str::uuid();

        DB::table('chat_restaurant_thread')->updateOrInsert(
            ['id' => $messageId],
            [
                'chat_id' => $data['chat_id'],
                'orderId' => $data['order_id'],
                'senderId' => $data['sender_id'],
                'receiverId' => $data['receiver_id'] ?? null,
                'messageType' => $data['message_type'],
                'message' => $data['message'] ?? null,
                'url' => $data['url'] ?? null,
                'videoThumbnail' => $data['video_thumbnail'] ?? null,
                'createdAt' => $data['created_at'] ?? Carbon::now()->toIso8601String(),
            ]
        );

        return $this->success(['id' => $messageId], 'Restaurant chat message stored');
    }

    /**
     * Upload chat image and return storage URL.
     */
    public function uploadChatImageToStorage(Request $request): \Illuminate\Http\JsonResponse
    {
        if (!$request->hasFile('image')) {
            return $this->error('image file is required', 422);
        }

        $file = $request->file('image');

        $path = $file->store('chat/images', 'public');
        $url = Storage::disk('public')->url($path);

        return $this->success([
            'url' => asset($url),
            'mime' => $file->getClientMimeType(),
        ], 'Image uploaded');
    }

    /**
     * Upload chat video (and optional thumbnail) to storage.
     */
    public function uploadChatVideoToStorage(Request $request): \Illuminate\Http\JsonResponse
    {
        if (!$request->hasFile('video')) {
            return $this->error('video file is required', 422);
        }

        $video = $request->file('video');
        $videoPath = $video->store('chat/videos', 'public');
        $videoUrl = Storage::disk('public')->url($videoPath);

        $thumbUrl = null;
        if ($request->hasFile('thumbnail')) {
            $thumb = $request->file('thumbnail');
            $thumbPath = $thumb->store('chat/thumbnails', 'public');
            $thumbUrl = Storage::disk('public')->url($thumbPath);
        }

        return $this->success([
            'videoUrl' => [
                'url' => asset($videoUrl),
                'mime' => $video->getClientMimeType(),
                'videoThumbnail' => $thumbUrl ? asset($thumbUrl) : null,
            ],
            'thumbnailUrl' => $thumbUrl ? asset($thumbUrl) : null,
        ], 'Video uploaded');
    }

    /**
     * Retrieve vendor category by id.
     */
    public function getVendorCategoryByCategoryId(string $categoryId): \Illuminate\Http\JsonResponse
    {
        $category = DB::table('vendor_categories')->where('id', $categoryId)->first();

        if (!$category) {
            return $this->error('Vendor category not found', 404);
        }

        return $this->success($this->convertRowToArray($category));
    }

    /**
     * Upsert rating model.
     */
    public function setRatingModel(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->all();

        // 🧩 Validate request
        $validator = Validator::make($data, [
            'id' => ['required', 'string'],
            'productId' => ['required', 'string'],
            'rating' => ['required', 'numeric'],
            'CustomerId' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors()->toArray());
        }

        // 🧩 Safely encode array fields
        $data['reviewAttributes'] = isset($data['reviewAttributes']) && is_array($data['reviewAttributes'])
            ? json_encode($data['reviewAttributes'])
            : ($data['reviewAttributes'] ?? null);

        $data['photos'] = isset($data['photos']) && is_array($data['photos'])
            ? json_encode($data['photos'])
            : ($data['photos'] ?? null);

        $payload = $data;
        $payload['createdAt'] = $payload['createdAt'] ?? Carbon::now()->toIso8601String();

        try {
            DB::beginTransaction();

            // 🧩 Perform insert or update
            $result = DB::table('foods_review')->updateOrInsert(
                ['id' => $payload['id']],
                [
                    'productId' => $payload['productId'],
                    'uname' => $payload['uname'] ?? null,
                    'orderid' => $payload['orderid'] ?? null,
                    'VendorId' => $payload['VendorId'] ?? null,
                    'profile' => $payload['profile'] ?? null,
                    'rating' => $payload['rating'],
                    'CustomerId' => $payload['CustomerId'] ?? null,
                    'reviewAttributes' => $payload['reviewAttributes'] ?? null,
                    'photos' => $payload['photos'] ?? null,
                    'createdAt' => $payload['createdAt'],
                    'driverId' => $payload['driverId'] ?? null,
                    'comment' => $payload['comment'] ?? null,
                ]
            );

            // 🧩 Commit transaction
            DB::commit();

            // 🧩 Verify record actually exists
            $inserted = DB::table('foods_review')->where('id', $payload['id'])->first();

            if (!$inserted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to insert record',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'data' => $inserted,
                'message' => 'Rating stored successfully',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Database error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update vendor record.
     */
    public function updateVendor(Request $request, string $vendorId): \Illuminate\Http\JsonResponse
    {
        $vendor = DB::table('vendors')->where('id', $vendorId)->first();
        if (!$vendor) {
            return $this->error('Vendor not found', 404);
        }

        $data = $this->encodeArrayColumns($request->all(), [
            'photos',
            'workingHours',
            'restaurantMenuPhotos',
            'filters',
            'subscription_plan',
            'location',
            'specialDiscount',
            'dine_in_active',
        ]);

        if (empty($data)) {
            return $this->error('No data provided', 422);
        }

        DB::table('vendors')->where('id', $vendorId)->update($data);

        $freshVendor = DB::table('vendors')->where('id', $vendorId)->first();

        return $this->success($this->mapVendorRow((array) $freshVendor), 'Vendor updated');
    }

    /**
     * Retrieve active advertisements.
     */
    public function getAllAdvertisement(): \Illuminate\Http\JsonResponse
    {
        $now = Carbon::now();

        $ads = DB::table('advertisements')
            ->where('status', 'approved')
            ->where('paymentStatus', 1)
            ->get()
            ->filter(function ($ad) use ($now) {
                $start = $this->nullableCarbon($ad->startDate);
                $end = $this->nullableCarbon($ad->endDate);
                $isPaused = $this->coerceBoolean($ad->isPaused);

                if ($isPaused) {
                    return false;
                }

                if ($start && $now->lt($start)) {
                    return false;
                }

                if ($end && $now->gt($end)) {
                    return false;
                }

                return true;
            })
            ->values()
            ->map(fn ($ad) => $this->convertRowToArray($ad));

        return $this->success([
            'advertisements' => $ads,
            'count' => $ads->count(),
        ]);
    }

    /**
     * Fetch active promotions with optional restaurant filter.
     */
    public function fetchActivePromotions(Request $request): \Illuminate\Http\JsonResponse
    {
        $Zoneid = $request->query('zoneId');

//        if (!$restaurantId) {
//            return $this->error('restaurant_id is required', 422);
//        }

        $promotions = DB::table('promotions')
            ->where('zoneId', $Zoneid)
            ->where('isAvailable', 1)
            ->get();

        return $this->success([
            'promotions' => $promotions,
            'count' => $promotions->count(),
        ]);
    }

    /**
     * Get active promotion for a specific product in a restaurant.
     */
    public function getActivePromotionForProduct(Request $request): \Illuminate\Http\JsonResponse
    {
        $productId = $request->query('product_id');
        $restaurantId = $request->query('restaurant_id');

        if (!$productId || !$restaurantId) {
            return $this->error('product_id and restaurant_id are required', 422);
        }

        $promotion = DB::table('promotions')
            ->where('restaurant_id', $restaurantId)
            ->where('product_id', $productId)
            ->where('isAvailable', 1)
            ->first();

        if (!$promotion) {
            return $this->error('Active promotion not found for product', 404);
        }

        return $this->success($promotion);
    }

    /**
     * Retrieve products for search/indexing with optional zone filter.
     */
    public function getAllProductsInZone(Request $request): \Illuminate\Http\JsonResponse
    {
        $zoneId = $request->query('zone_id');
        $limit = (int) $request->query('limit', 800);
        $limit = max(1, min($limit, 1000));

        $query = DB::table('vendor_products as vp')
            ->select('vp.*')
            ->where(function ($q) {
                $q->whereNull('vp.publish')
                    ->orWhereIn('vp.publish', [1, '1', true, 'true']);
            })
            ->where(function ($q) {
                $q->whereNull('vp.isAvailable')
                    ->orWhereIn('vp.isAvailable', [1, '1', true, 'true']);
            });

        if ($zoneId) {
            $query->join('vendors as v', 'v.id', '=', 'vp.vendorID')
                ->where('v.zoneId', $zoneId);
        }

        $products = $query->limit($limit)->get()
            ->map(fn ($row) => $this->mapBasicProduct((array) $row));

        return $this->success([
            'products' => $products,
            'count' => $products->count(),
        ]);
    }

    /**
     * Retrieve vendors for search/indexing with optional zone filter.
     */
    public function getAllVendors(Request $request): \Illuminate\Http\JsonResponse
    {
        $zoneId = $request->query('zone_id');
        $limit = (int) $request->query('limit', 500);
        $limit = max(1, min($limit, 1000));

        $query = DB::table('vendors')
            ->where(function ($q) {
                $q->whereNull('publish')
                    ->orWhereIn('publish', [1, '1', true, 'true']);
            })
            ->where(function ($q) {
                $q->whereNull('vType')
                    ->orWhereNotIn(DB::raw('LOWER(vType)'), ['mart']);
            });

        if ($zoneId) {
            $query->where('zoneId', $zoneId);
        }

        $vendors = $query->limit($limit)->get()
            ->map(fn ($row) => $this->mapVendorRow((array) $row));

        return $this->success([
            'vendors' => $vendors,
            'count' => $vendors->count(),
        ]);
    }

    /**
     * Shared helper to fetch published products, optionally paginated.
     */
    protected function fetchPublishedProducts(?callable $scopedQuery = null, ?Request $request = null)
    {
        $query = VendorProduct::query()
            ->where(function ($q) {
                $q->whereNull('publish')
                    ->orWhereIn('publish', [1, '1', true, 'true']);
            })
            ->where(function ($q) {
                $q->whereNull('isAvailable')
                    ->orWhereIn('isAvailable', [1, '1', true, 'true']);
            })
            ->orderBy('name');

        if ($scopedQuery) {
            $scopedQuery($query);
        }

        $transform = fn ($item) => $this->mapBasicProduct($item);

        if ($request) {
            $perPage = (int) $request->query('per_page', 50);
            $perPage = max(1, min($perPage, 200));

            /** @var LengthAwarePaginator $paginator */
            $paginator = $query->paginate($perPage)->withQueryString();
            $paginator->getCollection()->transform($transform);

            return $paginator;
        }

        return $query->get()->map($transform);
    }

    /**
     * Prepare payload for vendor product upsert.
     */
    protected function prepareProductPayload(array $data): array
    {
        $payload = [];
        $fillable = (new VendorProduct())->getFillable();

        foreach ($fillable as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                if (is_array($value) || is_object($value)) {
                    $payload[$field] = json_encode($value);
                } else {
                    $payload[$field] = $value;
                }
            }
        }

        return $payload;
    }

    /**
     * Map vendor product to API structure.
     *
     * @param  VendorProduct|array  $item
     */
    protected function mapBasicProduct($item): array
    {
        if ($item instanceof VendorProduct) {
            $attributes = $item->getAttributes();
        } else {
            $attributes = (array) $item;
        }

        return [
            'id' => $attributes['id'] ?? null,
            'name' => $attributes['name'] ?? null,
            'description' => $attributes['description'] ?? null,
            'vendor_id' => $attributes['vendorID'] ?? null,
            'vendor_title' => $attributes['vendorTitle'] ?? null,
            'category_id' => $attributes['categoryID'] ?? null,
            'category_title' => $attributes['categoryTitle'] ?? null,
            'is_available' => $this->coerceBoolean($attributes['isAvailable'] ?? null),
            'publish' => $this->coerceBoolean($attributes['publish'] ?? null),
            'veg' => $this->coerceBoolean($attributes['veg'] ?? null),
            'nonveg' => $this->coerceBoolean($attributes['nonveg'] ?? null),
            'quantity' => $attributes['quantity'] ?? null,
            'price' => $attributes['price'] ?? null,
            'discount_price' => $attributes['disPrice'] ?? null,
            'takeaway_option' => $this->coerceBoolean($attributes['takeawayOption'] ?? null),
            'migrated_by' => $attributes['migratedBy'] ?? null,
            'photo' => $attributes['photo'] ?? null,
            'photos' => $this->safeDecode($attributes['photos'] ?? null),
            'created_at' => $this->safeDecode($attributes['createdAt'] ?? null) ?? $attributes['createdAt'] ?? null,
        ];
    }

    /**
     * Map raw restaurant order row to array.
     */
    protected function mapOrderRow(array $row): array
    {
        $jsonFields = [
            'triggerDelivery',
            'scheduleTime',
            'estimatedTimeToPrepare',
            'notes',
            'discount',
            'deliveryCharge',
            'couponId',
            'driver',
            'rejectedByDrivers',
            'specialDiscount',
            'tip_amount',
            'takeAway',
            'couponCode',
            'calculatedCharges',
            'products',
            'vendor',
            'address',
            'author',
            'taxSetting',
            'orderAutoCancelAt',
        ];

        $mapped = [];
        foreach ($row as $key => $value) {
            if (in_array($key, $jsonFields, true)) {
                $mapped[$key] = $this->safeDecode($value);
            } elseif ($key === 'status') {
                $mapped[$key] = $value;
            } elseif ($key === 'toPayAmount' || $key === 'ToPay') {
                $mapped['toPay'] = is_numeric($value) ? (float) $value : $value;
            } else {
                $mapped[$key] = $value;
            }
        }

        $mapped['createdAt'] = $row['createdAt'] ?? null;

        return $mapped;
    }

    /**
     * Map vendor row to API response.
     */
    protected function mapVendorRow(array $row): array
    {
        $decodeFields = [
            'photos',
            'restaurantMenuPhotos',
            'filters',
            'subscription_plan',
            'workingHours',
            'location',
            'specialDiscount',
            'coordinates',
        ];

        foreach ($decodeFields as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = $this->safeDecode($row[$field] ?? null);
            }
        }

        $row['publish'] = $this->coerceBoolean($row['publish'] ?? null);
        $row['isOpen'] = $this->coerceBoolean($row['isOpen'] ?? null);
        $row['enabledDelivery'] = $this->coerceBoolean($row['enabledDelivery'] ?? null);
        $row['isSelfDelivery'] = $this->coerceBoolean($row['isSelfDelivery'] ?? null);

        return $row;
    }

    /**
     * Convert DB row (stdClass) to plain array.
     */
    protected function convertRowToArray($row): array
    {
        return array_map(function ($value) {
            return is_string($value) ? $this->maybeDecode($value) : $value;
        }, (array) $row);
    }

    /**
     * Attempt to decode JSON strings gracefully.
     */
    protected function maybeDecode(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '' || $trimmed === 'null') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $value;
    }

    /**
     * Safe JSON decode.
     */
    protected function safeDecode($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || strtolower($trimmed) === 'null') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /**
     * Encode array columns as JSON.
     */
    protected function encodeArrayColumns(array $data, array $columns): array
    {
        foreach ($columns as $column) {
            if (array_key_exists($column, $data)) {
                $value = $data[$column];
                if (is_array($value) || is_object($value)) {
                    $data[$column] = json_encode($value);
                }
            }
        }

        return $data;
    }

    /**
     * Coerce value to boolean or null.
     */
    protected function coerceBoolean($value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value > 0;
        }

        $lower = strtolower((string) $value);

        if (in_array($lower, $this->truthyStrings, true)) {
            return true;
        }

        if (in_array($lower, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Parse ISO date string (with optional quotes) to Carbon.
     */
    protected function nullableCarbon($value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        $clean = trim($value, "\"' ");
        if ($clean === '') {
            return null;
        }

        try {
            return Carbon::parse($clean);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Shared settings loader.
     */
    protected function getSettingsDocument(string $documentName): \Illuminate\Http\JsonResponse
    {
        $setting = DB::table('settings')
            ->where('document_name', $documentName)
            ->first();

        if (!$setting) {
            return $this->error('Setting not found', 404);
        }

        $fields = $this->safeDecode($setting->fields);

        return $this->success([
            'id' => $setting->id,
            'document_name' => $setting->document_name,
            'fields' => $fields,
        ]);
    }

    /**
     * Resolve active promotions for optional restaurant.
     */
    protected function resolveActivePromotions(?string $restaurantId = null): array
    {
        $now = Carbon::now();

        $query = DB::table('promotions')->where('isAvailable', 1);

        if ($restaurantId) {
            $query->where('restaurant_id', $restaurantId);
        }

        return $query->get()
            ->filter(function ($promo) use ($now) {
                $start = $this->nullableCarbon($promo->start_time);
                $end = $this->nullableCarbon($promo->end_time);

                if ($start && $now->lt($start)) {
                    return false;
                }

                if ($end && $now->gt($end)) {
                    return false;
                }

                return true;
            })
            ->map(function ($promo) {
                return [
                    'id' => $promo->id,
                    'payment_mode' => $promo->payment_mode,
                    'product_title' => $promo->product_title,
                    'extra_km_charge' => $promo->extra_km_charge,
                    'product_id' => $promo->product_id,
                    'restaurant_id' => $promo->restaurant_id,
                    'restaurant_title' => $promo->restaurant_title,
                    'start_time' => $promo->start_time,
                    'end_time' => $promo->end_time,
                    'item_limit' => $promo->item_limit,
                    'special_price' => $promo->special_price,
                    'vType' => $promo->vType,
                    'zoneId' => $promo->zoneId,
                    'free_delivery_km' => $promo->free_delivery_km,
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Success response helper.
     */
    protected function success($data = null, string $message = 'OK', int $status = 200): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
        ], $status);
    }

    /**
     * Error response helper.
     */
    protected function error(string $message, int $status = 400, array $errors = []): \Illuminate\Http\JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    public function getLatestOrderInRange()
    {
        $order = restaurant_orders::where('id', '>=', 'Jippy3000000')
            ->where('id', '<', 'Jippy4')
            ->first();

        if ($order) {
            return response()->json([
                'success' => true,
                'order' => $order
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No order found in the specified range'
        ], 404);
    }

}


