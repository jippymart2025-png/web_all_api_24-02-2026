<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\MartItem;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorProduct;
use App\Models\restaurant_orders as RestaurantOrder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class MobileSqlBridgeController extends Controller
{
    /**
     * Fetch brand details along with mart item counts.
     */
    public function fetchBrand(string $brandId): JsonResponse
    {
        $brand = DB::table('brands')->where('id', $brandId)->first();

        if (!$brand) {
            return $this->error('Brand not found', 404);
        }

        $itemsCount = MartItem::query()->where('brandID', $brandId)->count();

        return $this->success([
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'description' => $brand->description,
                'logo_url' => $brand->logo_url,
                'status' => (bool) $brand->status,
                'created_at' => $this->sanitizeIsoString($brand->created_at),
                'updated_at' => $this->sanitizeIsoString($brand->updated_at),
            ],
            'items_count' => $itemsCount,
        ], 'Brand fetched');
    }

    /**
     * Get surge rules plus extended configuration.
     */
    public function getSurgeRules(): JsonResponse
    {
        $rulesRow = DB::table('surge_rules')
            ->where('firestore_id', 'surge_settings')
            ->first();

        $config = $this->resolveSettingDocument('surge_rules_config');

        if (!$rulesRow && empty($config)) {
            return $this->error('Surge rules not configured', 404);
        }

        $rules = $rulesRow ? [
            'id' => $rulesRow->id,
            'firestore_id' => $rulesRow->firestore_id,
            'bad_weather' => (float) ($rulesRow->bad_weather ?? 0),
            'rain' => (float) ($rulesRow->rain ?? 0),
            'summer' => (float) ($rulesRow->summer ?? 0),
            'created_at' => $rulesRow->created_at,
            'updated_at' => $rulesRow->updated_at,
        ] : null;

        return $this->success([
            'rules' => $rules,
            'config' => $config,
        ], 'Surge rules fetched');
    }

    /**
     * Return only the admin surge fee as string (to keep client parity).
     */
    public function getAdminSurgeFee(): JsonResponse
    {
        return $this->success([
            'admin_surge_fee' => (string) $this->resolveAdminSurgeFeeValue(),
        ]);
    }

    /**
     * Return mart delivery charge settings.
     */
    public function getMartDeliveryChargeSettings(): JsonResponse
    {
        $settings = $this->resolveSettingDocument('martDeliveryCharge');

        if (empty($settings)) {
            return $this->error('Mart delivery charge settings not found', 404);
        }

        return $this->success($settings);
    }

    /**
     * List used coupons for the authenticated / provided user.
     */
       public function getUsedCoupons(Request $request): JsonResponse
    {
        $userId = $request->query('userId');

        if (!$userId) {
            return $this->error('User ID is required', 422);
        }

        $records = DB::table('used_coupons')
            ->where('userId', $userId)
            ->orderByDesc('usedAt')
            ->get()
            ->map(function ($row) {
                return [
                    'couponId' => $row->couponId,
                    'usedAt'   => $row->usedAt,
                ];
            })
            ->values();

        return $this->success([
            'userId'  => $userId,
            'coupons' => $records,
        ]);
    }

    /**
     * Mark a coupon as used for the authenticated / provided user.
     */
    public function markCouponAsUsed(Request $request, string $couponId): JsonResponse
    {
        $userId = $this->resolveUserId($request);

        if (!$userId) {
            return $this->error('User ID is required', 422);
        }

        if (empty($couponId)) {
            return $this->error('Coupon ID is required', 422);
        }

        $this->storeCouponUsage($userId, $couponId);

        return $this->success([
            'couponId' => $couponId,
            'userId' => $userId,
        ], 'Coupon marked as used');
    }

    /**
     * Rollback failed orders and restock products.
     */
    public function rollbackFailedOrder(Request $request): JsonResponse
    {
        $productsPayload = $request->input('products');
        $products = $this->normalizeInputArray($productsPayload);
        if ($products !== null) {
            $request->merge(['products' => $products]);
        }

        $validator = Validator::make($request->all(), [
            'order_id' => ['required', 'string'],
            'products' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors()->toArray());
        }

        $orderId = $request->input('order_id');
        $products = array_map(fn ($item) => $this->normalizeArray($item), $products);

        try {
            DB::transaction(function () use ($orderId, $products) {
                DB::table('restaurant_orders')->where('id', $orderId)->delete();
                DB::table('order_billing')->where('orderId', $orderId)->delete();

                foreach ($products as $product) {
                    $productId = Arr::get($product, 'id');
                    $quantity = (int) Arr::get($product, 'quantity', 0);

                    if (!$productId || $quantity <= 0) {
                        continue;
                    }

                    if ($this->isMartProduct($product)) {
                        $item = MartItem::query()->find($productId);
                        if ($item) {
                            $item->increment('quantity', $quantity);
                        }
                    } else {
                        $vendorProduct = VendorProduct::query()->find($productId);
                        if ($vendorProduct) {
                            $vendorProduct->increment('quantity', $quantity);
                        }
                    }
                }
            });
        } catch (Throwable $e) {
            Log::error('Failed to rollback order', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Unable to rollback order at the moment.', 500);
        }

        return $this->success([
            'order_id' => $orderId,
            'restocked_items' => count($products),
        ], 'Order rollback completed');
    }

    /**
     * Create a SQL backed order record that mirrors the old Firestore payload.
     */
//    public function createOrder(Request $request): JsonResponse
//    {
//        $validator = Validator::make($request->all(), [
//            'author_id' => ['nullable', 'string'],
//            'cart_items' => ['required', 'array', 'min:1'],
//            'cart_items.*.id' => ['nullable', 'string'],
//            'selected_address' => ['required', 'array'],
//            'payment_method' => ['required', 'string'],
//            'total_amount' => ['required', 'numeric', 'min:0'],
//            'delivery_charges' => ['nullable', 'numeric', 'min:0'],
//            'tip_amount' => ['nullable', 'numeric', 'min:0'],
//            'coupon_id' => ['nullable', 'string'],
//            'coupon_code' => ['nullable', 'string'],
//            'discount' => ['nullable', 'numeric', 'min:0'],
//            'promotion' => ['nullable', 'numeric'],
//            'notes' => ['nullable', 'string'],
//            'schedule_time' => ['nullable', 'date'],
//            'surge_percent' => ['nullable', 'numeric', 'min:0'],
//            'admin_surge_fee' => ['nullable', 'numeric', 'min:0'],
//            'special_discount' => ['nullable', 'array'],
//            'calculated_charges' => ['nullable', 'array'],
//            'tax_setting' => ['nullable', 'array'],
//            'takeaway' => ['nullable', 'boolean'],
//            'vendor_id' => ['nullable', 'string'],
//        ]);
//
//        if ($validator->fails()) {
//            return $this->error('Validation failed', 422, (array) $validator->errors());
//        }
//
//        $authorId = $request->input('author_id') ?? $this->resolveUserId($request);
//        if (!$authorId) {
//            return $this->error('Author ID is required', 422);
//        }
//
//        $user = $this->resolveUser($authorId);
//        $authorPayload = $this->mapUserPayload($user);
//
//        $cartItems = $request->input('cart_items');
//        $selectedAddress = $request->input('selected_address');
//
//        $specialDiscount = array_merge([
//            'special_discount' => 0,
//            'special_discount_label' => null,
//            'specialType' => null,
//        ], $request->input('special_discount', []));
//
//        $deliveryCharges = (float) $request->input('delivery_charges', 0);
//        $tipAmount = (float) $request->input('tip_amount', 0);
//        $discount = (float) $request->input('discount', 0);
//        $totalAmount = (float) $request->input('total_amount', 0);
//        $surgePercent = (int) $request->input('surge_percent', 0);
//
//        // -------- Surge fee handling -------
//        $adminFee = $request->filled('admin_surge_fee')
//            ? (int) $request->admin_surge_fee
//            : ($surgePercent > 0 ? $this->resolveAdminSurgeFeeValue() : 0);
//
//        $totalSurgeFee = $surgePercent + $adminFee;
//
//        // -------- Schedule Date ----------
//        $scheduleTime = $request->filled('schedule_time')
//            ? Carbon::parse($request->schedule_time)->toISOString()
//            : null;
//
//        // Vendor info resolve
//        $vendorContext = $this->resolveVendorContext($request->vendor_id);
//        $adminCommission = $vendorContext['commission'];
//
//        $orderId = $this->generateOrderId();
//        $now = Carbon::now()->toISOString();
//
//        /**
//         * ✅ PROMOTION AUTO-DETECT FROM CART ITEMS
//         * If any product has promo_id → promotion = 1
//         */
//        $promotion = 0;
//        foreach ($cartItems as $item) {
//            if (!empty($item['promo_id'])) {
//                $promotion = 1;
//                break;
//            }
//        }
//
//        $orderData = [
//            'id'                    => $orderId,
//            'vendorID'              => (string) $request->vendor_id,
//            'authorID'              => $authorId,
//            'status'                => 'Order Placed',
//            'createdAt'             => $now,
//            'scheduleTime'          => $scheduleTime,
//            'deliveryCharge'        => $deliveryCharges,
//            'discount'              => $discount,
//            'tip_amount'            => $tipAmount,
//            'takeAway'              => $request->boolean('takeaway'),
//            'payment_method'        => $request->payment_method,
//            'couponId'              => $request->coupon_id ?? '',
//            'couponCode'            => $request->coupon_code ?? '',
//            'promotion'             => $promotion,
//            'ToPay'                 => $totalAmount,
//            'toPayAmount'           => $totalAmount,
//            'surge_fee'             => $totalSurgeFee,
//            'adminCommission'       => (string) $adminCommission['amount'],
//            'adminCommissionType'   => $adminCommission['commissionType'],
//            'specialDiscount'       => json_encode($specialDiscount),
//            'calculatedCharges'     => $request->calculated_charges ? json_encode($request->calculated_charges) : null,
//            'taxSetting'            => $request->tax_setting ? json_encode($request->tax_setting) : null,
//            'products'              => json_encode($cartItems),
//            'address'               => json_encode($selectedAddress),
//            'author'                => json_encode($authorPayload),
//            'notes'                 => $request->notes,
//        ];
//
//        try {
//            DB::transaction(function () use (
//                $orderData,
//                $orderId,
//                $totalAmount,
//                $surgePercent,
//                $adminFee,
//                $request,
//                $authorId
//            ) {
//                RestaurantOrder::create($orderData);
//
//                if ($request->filled('coupon_id')) {
//                    $this->storeCouponUsage($authorId, $request->coupon_id);
//                }
//
//                DB::table('order_billing')->updateOrInsert(
//                    ['orderId' => $orderId],
//                    [
//                        'id'               => (string) Str::uuid(),
//                        'createdAt'        => now()->toISOString(),
//                        'orderId'          => $orderId,
//                        'ToPay'            => $totalAmount,
//                        'surge_fee'        => $surgePercent,
//                        'admin_surge_fee'  => $adminFee,
//                        'total_surge_fee'  => $adminFee + $surgePercent,
//                    ]
//                );
//            });
//        } catch (\Throwable $e) {
//            Log::error('Order Failed', [
//                'orderId' => $orderId,
//                'error' => $e->getMessage(),
//            ]);
//
//            return $this->error('Failed to place order, try again later.', 500);
//        }
//
//        return $this->success([
//            'order_id'        => $orderId,
//            'surge_fee'       => $surgePercent,
//            'admin_surge_fee' => $adminFee,
//            'total_surge_fee' => $totalSurgeFee,
//        ], 'Order placed successfully');
//    }



    public function createOrder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'author_id' => ['nullable', 'string'],
            'cart_items' => ['required', 'array', 'min:1'],
            'cart_items.*.id' => ['nullable', 'string'],
            'selected_address' => ['required', 'array'],
            'payment_method' => ['required', 'string'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'delivery_charges' => ['nullable', 'numeric', 'min:0'],
            'tip_amount' => ['nullable', 'numeric', 'min:0'],
            'coupon_id' => ['nullable', 'string'],
            'coupon_code' => ['nullable', 'string'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'promotion' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string'],
            'schedule_time' => ['nullable', 'date'],
            'surge_percent' => ['nullable', 'numeric', 'min:0'],
            'admin_surge_fee' => ['nullable', 'numeric', 'min:0'],
            'special_discount' => ['nullable', 'array'],
            'calculated_charges' => ['nullable', 'array'],
            'tax_setting' => ['nullable', 'array'],
            'takeaway' => ['nullable', 'boolean'],
            'vendor_id' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, (array) $validator->errors());
        }

        $authorId = $request->input('author_id') ?? $this->resolveUserId($request);
        if (!$authorId) {
            return $this->error('Author ID is required', 422);
        }

        $user = $this->resolveUser($authorId);
        $authorPayload = $this->mapUserPayload($user);

        $cartItems = $request->input('cart_items');
        $selectedAddress = $request->input('selected_address');

        /**
         * ✅ FETCH merchant_price FROM products TABLE
         */
        $productIds = collect($cartItems)->pluck('id')->filter()->toArray();

        $merchantPrices = DB::table('vendor_products')
            ->whereIn('id', $productIds)
            ->pluck('merchant_price', 'id'); // returns [id => merchant_price]

        $updatedCartItems = collect($cartItems)->map(function ($item) use ($merchantPrices) {
            $item['merchant_price'] = (float) ($merchantPrices[$item['id']] ?? 0);
            return $item;
        })->toArray();

        $specialDiscount = array_merge([
            'special_discount' => 0,
            'special_discount_label' => null,
            'specialType' => null,
        ], $request->input('special_discount', []));

        $deliveryCharges = (float) $request->input('delivery_charges', 0);
        $tipAmount = (float) $request->input('tip_amount', 0);
        $discount = (float) $request->input('discount', 0);
        $totalAmount = (float) $request->input('total_amount', 0);
        $surgePercent = (int) $request->input('surge_percent', 0);

        $adminFee = $request->filled('admin_surge_fee')
            ? (int) $request->admin_surge_fee
            : ($surgePercent > 0 ? $this->resolveAdminSurgeFeeValue() : 0);

        $totalSurgeFee = $surgePercent + $adminFee;

        $scheduleTime = $request->filled('schedule_time')
            ? Carbon::parse($request->schedule_time)->toISOString()
            : null;

        $vendorContext = $this->resolveVendorContext($request->vendor_id);
        $adminCommission = $vendorContext['commission'];

        $orderId = $this->generateOrderId();
        $now = Carbon::now()->toISOString();

        $promotion = 0;
        foreach ($updatedCartItems as $item) {
            if (!empty($item['promo_id'])) {
                $promotion = 1;
                break;
            }
        }

        $orderData = [
            'id'                    => $orderId,
            'vendorID'              => (string) $request->vendor_id,
            'authorID'              => $authorId,
            'status'                => 'Order Placed',
            'createdAt'             => $now,
            'scheduleTime'          => $scheduleTime,
            'deliveryCharge'        => $deliveryCharges,
            'discount'              => $discount,
            'tip_amount'            => $tipAmount,
            'takeAway'              => $request->boolean('takeaway'),
            'payment_method'        => $request->payment_method,
            'couponId'              => $request->coupon_id ?? '',
            'couponCode'            => $request->coupon_code ?? '',
            'promotion'             => $promotion,
            'ToPay'                 => $totalAmount,
            'toPayAmount'           => $totalAmount,
            'surge_fee'             => $totalSurgeFee,
            'adminCommission'       => (string) $adminCommission['amount'],
            'adminCommissionType'   => $adminCommission['commissionType'],
            'specialDiscount'       => json_encode($specialDiscount),
            'calculatedCharges'     => $request->calculated_charges ? json_encode($request->calculated_charges) : null,
            'taxSetting'            => $request->tax_setting ? json_encode($request->tax_setting) : null,
            'products'              => json_encode($updatedCartItems), // ✅ updated here
            'address'               => json_encode($selectedAddress),
            'author'                => json_encode($authorPayload),
            'notes'                 => $request->notes,
        ];

        try {
            DB::transaction(function () use (
                $orderData,
                $orderId,
                $totalAmount,
                $surgePercent,
                $adminFee,
                $request,
                $authorId
            ) {
                RestaurantOrder::create($orderData);

                if ($request->filled('coupon_id')) {
                    $this->storeCouponUsage($authorId, $request->coupon_id);
                }

                DB::table('order_billing')->updateOrInsert(
                    ['orderId' => $orderId],
                    [
                        'id'               => (string) Str::uuid(),
                        'createdAt'        => now()->toISOString(),
                        'orderId'          => $orderId,
                        'ToPay'            => $totalAmount,
                        'surge_fee'        => $surgePercent,
                        'admin_surge_fee'  => $adminFee,
                        'total_surge_fee'  => $adminFee + $surgePercent,
                    ]
                );
            });
        } catch (\Throwable $e) {
            Log::error('Order Failed', [
                'orderId' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to place order, try again later.', 500);
        }

        return $this->success([
            'order_id'        => $orderId,
            'surge_fee'       => $surgePercent,
            'admin_surge_fee' => $adminFee,
            'total_surge_fee' => $totalSurgeFee,
        ], 'Order placed successfully');
    }

    /**
     * Return surge fee information for an order billing record.
     */
    public function getOrderSurgeFee(string $orderId): JsonResponse
    {
        $row = DB::table('order_billing')->where('orderId', $orderId)->first();

        if (!$row) {
            return $this->success([
                'order_id' => $orderId,
                'surge_fee' => 0.0,
                'admin_surge_fee' => 0.0,
                'total_surge_fee' => 0.0,
            ], 'Billing record not found');
        }

        return $this->success([
            'order_id' => $orderId,
            'surge_fee' => (float) ($row->surge_fee ?? 0),
            'admin_surge_fee' => (float) ($row->admin_surge_fee ?? 0),
            'total_surge_fee' => (float) ($row->total_surge_fee ?? 0),
        ]);
    }

    /**
     * Fetch application version info.
     */
    public function getLatestVersionInfo(): JsonResponse
    {
        $setting = AppSetting::getVersionInfo();

        if (!$setting) {
            return $this->error('Version info not configured', 404);
        }

        return $this->success([
            'id' => $setting->id,
            'android_version' => $setting->android_version,
            'ios_version' => $setting->ios_version,
            'android_build' => $setting->android_build,
            'ios_build' => $setting->ios_build,
            'min_required_version' => $setting->min_required_version,
            'force_update' => (bool) $setting->force_update,
            'update_message' => $setting->update_message,
            'update_url' => $setting->update_url,
            'android_update_url' => $setting->android_update_url,
            'ios_update_url' => $setting->ios_update_url,
            'last_updated' => $setting->last_updated,
        ]);
    }



    public function getLatestrestVersionInfo(): JsonResponse
    {
        $setting = AppSetting::getrestaurantVersionInfo();

        if (!$setting) {
            return $this->error('Version info not configured', 404);
        }

        return $this->success([
            'id' => $setting->id,
            'android_version' => $setting->android_version,
            'ios_version' => $setting->ios_version,
            'android_build' => $setting->android_build,
            'ios_build' => $setting->ios_build,
            'latest_version' => $setting->latest_version,
            'min_required_version' => $setting->min_required_version,
            'package_name' => $setting->package_name,
            'force_update' => (bool) $setting->force_update,
            'update_message' => $setting->update_message,
            'update_url' => $setting->update_url,
            'android_update_url' => $setting->android_update_url,
            'ios_update_url' => $setting->ios_update_url,
            'last_updated' => $setting->last_updated,
        ]);
    }


    /**
     * Add restaurant chat message (SQL backed).
     */
    public function addRestaurantChat(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
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

        $messageId = $request->input('id') ?: (string) Str::uuid();

        DB::table('chat_restaurant_thread')->updateOrInsert(
            ['id' => $messageId],
            [
                'chat_id' => $request->input('chat_id'),
                'orderId' => $request->input('order_id'),
                'senderId' => $request->input('sender_id'),
                'receiverId' => $request->input('receiver_id'),
                'messageType' => $request->input('message_type'),
                'message' => $request->input('message'),
                'url' => $request->input('url'),
                'videoThumbnail' => $request->input('video_thumbnail'),
                'createdAt' => $request->input('created_at') ?? $this->toIsoString(Carbon::now()),
            ]
        );

        return $this->success([
            'id' => $messageId,
        ], 'Restaurant chat stored');
    }

    /**
     * Create or update a driver chat inbox entry.
     */
    public function addDriverInbox(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors()->toArray());
        }

        $orderId = $request->input('order_id');

        DB::table('chat_driver')->updateOrInsert(
            ['id' => $orderId],
            [
                'orderId' => $orderId,
                'restaurantId' => $request->input('restaurant_id'),
                'restaurantName' => $request->input('restaurant_name'),
                'restaurantProfileImage' => $request->input('restaurant_profile_image'),
                'customerId' => $request->input('customer_id'),
                'customerName' => $request->input('customer_name'),
                'customerProfileImage' => $request->input('customer_profile_image'),
                'lastSenderId' => $request->input('last_sender_id'),
                'lastMessage' => $request->input('last_message'),
                'createdAt' => $request->input('created_at') ?? $this->toIsoString(Carbon::now()),
                'chatType' => $request->input('chat_type', 'Driver'),
            ]
        );

        return $this->success([
            'order_id' => $orderId,
        ], 'Driver inbox stored');
    }

    /**
     * Upsert restaurant chat inbox entry.
     */
    public function addRestaurantInbox(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => ['required', 'string'],
            'restaurant_id' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors()->toArray());
        }

        $orderId = $request->input('order_id');

        DB::table('chat_restaurant')->updateOrInsert(
            ['id' => $orderId],
            [
                'orderId' => $orderId,
                'restaurantId' => $request->input('restaurant_id'),
                'restaurantName' => $request->input('restaurant_name'),
                'restaurantProfileImage' => $request->input('restaurant_profile_image'),
                'customerId' => $request->input('customer_id'),
                'customerName' => $request->input('customer_name'),
                'customerProfileImage' => $request->input('customer_profile_image'),
                'lastSenderId' => $request->input('last_sender_id'),
                'lastMessage' => $request->input('last_message'),
                'createdAt' => $request->input('created_at') ?? $this->toIsoString(Carbon::now()),
                'chatType' => $request->input('chat_type', 'restaurant'),
            ]
        );

        return $this->success([
            'order_id' => $orderId,
        ], 'Restaurant inbox stored');
    }

    /**
     * Add driver chat message.
     */
    public function addDriverChat(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
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

        $messageId = $request->input('id') ?: (string) Str::uuid();

        DB::table('chat_driver_thread')->updateOrInsert(
            ['id' => $messageId],
            [
                'chat_id' => $request->input('chat_id'),
                'orderId' => $request->input('order_id'),
                'senderId' => $request->input('sender_id'),
                'receiverId' => $request->input('receiver_id'),
                'messageType' => $request->input('message_type'),
                'message' => $request->input('message'),
                'url' => $request->input('url'),
                'videoThumbnail' => $request->input('video_thumbnail'),
                'createdAt' => $request->input('created_at') ?? $this->toIsoString(Carbon::now()),
            ]
        );

        return $this->success([
            'id' => $messageId,
        ], 'Driver chat stored');
    }

    /**
     * Success response helper.
     */
    protected function success($data = null, string $message = 'OK', int $status = 200): JsonResponse
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
    protected function error(string $message, int $status = 400, array $errors = []): JsonResponse
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

    /**
     * Resolve an authenticated or provided user id.
     */
    protected function resolveUserId(Request $request): ?string
    {
        if ($request->filled('user_id')) {
            return (string) $request->input('user_id');
        }

        if ($request->filled('author_id')) {
            return (string) $request->input('author_id');
        }

        if ($request->filled('firebase_id')) {
            return (string) $request->input('firebase_id');
        }

        $user = $request->user();

        if ($user) {
            return $user->firebase_id ?? (string) $user->id;
        }

        return null;
    }

    /**
     * Fetch a user row by firebase id or numeric id.
     */
    protected function resolveUser(string $identifier): ?User
    {
        return User::query()
            ->where('firebase_id', $identifier)
            ->orWhere('id', $identifier)
            ->first();
    }

    /**
     * Convert user row into payload that mirrors Firestore.
     */
    protected function mapUserPayload(?User $user, array $fallback = []): array
    {
        if (!$user) {
            return $fallback;
        }

        $shippingAddress = $this->decodeJson($user->shippingAddress, []);

        return [
            'id' => $user->firebase_id ?? $user->id,
            'firstName' => $user->firstName,
            'lastName' => $user->lastName,
            'phoneNumber' => $user->phoneNumber,
            'email' => $user->email,
            'profilePictureURL' => $user->profilePictureURL ?? null,
            'role' => $user->role,
            'active' => (bool) $user->active,
            'fcmToken' => $user->fcmToken,
            'isDocumentVerify' => (bool) ($user->isDocumentVerify ?? false),
            'isActive' => (bool) ($user->isActive ?? false),
            'appIdentifier' => $user->appIdentifier,
            'createdAt' => $user->createdAt,
            'shippingAddress' => $shippingAddress,
            'wallet_amount' => $user->wallet_amount,
        ];
    }

    /**
     * Determine vendor context (restaurant vs mart fallback).
     */
    protected function resolveVendorContext(?string $vendorId): array
    {
        if ($vendorId) {
            $vendor = Vendor::query()->find($vendorId);
            if (!$vendor) {
                throw new \RuntimeException('Vendor not found');
            }

            return [
                'id' => $vendor->id,
                'model' => $vendor,
                'payload' => $this->mapVendorPayload($vendor),
                'commission' => $this->resolveAdminCommission($vendor),
            ];
        }

        $vendor = Vendor::query()
            ->where('vType', 'mart')
            ->orWhereRaw('LOWER(title) LIKE ?', ['%mart%'])
            ->orderByDesc(DB::raw('IFNULL(isOpen, 0)'))
            ->first();

        if ($vendor) {
            return [
                'id' => $vendor->id,
                'model' => $vendor,
                'payload' => $this->mapVendorPayload($vendor),
                'commission' => $this->resolveAdminCommission($vendor),
            ];
        }

        $globalCommission = $this->resolveAdminCommission(null);

        return [
            'id' => 'mart_default',
            'model' => null,
            'payload' => [
                'id' => 'mart_default',
                'title' => 'Jippy Mart',
                'location' => 'Default Location',
                'phonenumber' => '0000000000',
                'latitude' => 15.48649,
                'longitude' => 80.04967,
                'isOpen' => true,
                'vType' => 'mart',
                'author' => 'default',
                'authorName' => 'Jippy Mart',
                'authorProfilePic' => null,
                'adminCommission' => $globalCommission,
                'workingHours' => [],
            ],
            'commission' => $globalCommission,
        ];
    }

    /**
     * Convert vendor model into Firestore-compatible payload.
     */
    protected function mapVendorPayload(Vendor $vendor): array
    {
        return [
            'id' => $vendor->id,
            'title' => $vendor->title,
            'location' => $vendor->location,
            'phonenumber' => $vendor->phonenumber,
            'latitude' => $vendor->latitude,
            'longitude' => $vendor->longitude,
            'isOpen' => (bool) $vendor->isOpen,
            'vType' => $vendor->vType,
            'author' => $vendor->author,
            'authorName' => $vendor->authorName,
            'authorProfilePic' => $vendor->authorProfilePic,
            'adminCommission' => $this->decodeJson($vendor->adminCommission, []),
            'workingHours' => $this->decodeJson($vendor->workingHours, []),
            'specialDiscount' => $this->decodeJson($vendor->specialDiscount, []),
            'DeliveryCharge' => $this->decodeJson($vendor->DeliveryCharge, $vendor->DeliveryCharge),
        ];
    }

    /**
     * Ensure vendor subscription capacity is available when required.
     */
//    protected function assertVendorCapacity(?Vendor $vendor): void
//    {
//        if (!$vendor) {
//            return;
//        }
//
//        $subscriptionEnabled = $this->isSubscriptionModelEnabled();
//        $commissionEnabled = $this->isAdminCommissionEnabled();
//
//        if (($subscriptionEnabled || $commissionEnabled) && $vendor->subscriptionPlanId) {
//            $remaining = $vendor->subscriptionTotalOrders;
//            if ($remaining === null || $remaining === '0' || (is_numeric($remaining) && (int) $remaining <= 0)) {
//                throw new \RuntimeException(
//                    'This vendor has reached their maximum order capacity. Please select a different vendor or try again later.'
//                );
//            }
//        }
//    }

    /**
     * Determine if a product belongs to mart module.
     */
    protected function isMartProduct(array $product): bool
    {
        $vendorId = (string) Arr::get($product, 'vendorID', '');
        if (Str::startsWith($vendorId, 'mart_')) {
            return true;
        }

        return (bool) Arr::get($product, 'isMartItem', false);
    }

    /**
     * Persist coupon usage record.
     */
    protected function storeCouponUsage(string $userId, string $couponId): void
    {
        $existing = DB::table('used_coupons')
            ->where('couponId', $couponId)
            ->where('userId', $userId)
            ->first();

        $payload = [
            'couponId' => $couponId,
            'userId' => $userId,
            'usedAt' => $this->toIsoString(Carbon::now()),
        ];

        if ($existing) {
            DB::table('used_coupons')->where('id', $existing->id)->update($payload);
        } else {
            $payload['id'] = (string) Str::uuid();
            DB::table('used_coupons')->insert($payload);
        }
    }

    /**
     * Generate order id using the legacy Jippy3 format.
     */
    protected function generateOrderId(): string
    {
        $latest = DB::table('restaurant_orders')
            ->where('id', 'like', 'Jippy3%')
            ->orderByDesc('id')
            ->value('id');

        $base = 3000000;

        if ($latest && preg_match('/Jippy3(\d{1,7})/', $latest, $matches)) {
            $base = max($base, (int) $matches[1]);
        }

        $next = $base + 1;

        return 'Jippy3' . str_pad((string) $next, 7, '0', STR_PAD_LEFT);
    }

    /**
     * Determine if subscription model is enabled globally.
     */
    protected function isSubscriptionModelEnabled(): bool
    {
        $settings = $this->resolveSettingDocument('restaurant');
        return (bool) ($settings['subscription_model'] ?? false);
    }

    /**
     * Determine if admin commission is enabled globally.
     */
    protected function isAdminCommissionEnabled(): bool
    {
        $settings = $this->resolveSettingDocument('AdminCommission');
        return (bool) ($settings['isEnabled'] ?? false);
    }

    /**
     * Resolve admin commission amounts.
     */
    protected function resolveAdminCommission(?Vendor $vendor): array
    {
        $global = $this->resolveSettingDocument('AdminCommission');
        $amount = (float) ($global['amount'] ?? $global['fix_commission'] ?? 0);
        $type = $global['commissionType'] ?? 'Percent';

        if ($vendor && $vendor->adminCommission) {
            $decoded = $this->decodeJson($vendor->adminCommission, []);
            if (!empty($decoded)) {
                $amount = (float) ($decoded['amount'] ?? $decoded['fix_commission'] ?? $amount);
                $type = $decoded['commissionType'] ?? $type;
            }
        }

        return [
            'amount' => $amount,
            'commissionType' => $type,
            'isEnabled' => $this->isAdminCommissionEnabled(),
        ];
    }

    /**
     * Decode JSON safely.
     */
    protected function decodeJson($value, $default = [])
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        $decoded = json_decode($value, true);

        return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : $default;
    }

    /**
     * Normalize array-ish inputs (objects / JSON strings).
     */
    protected function normalizeArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value), true) ?? [];
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Convert value to ISO8601 string.
     */
    protected function toIsoString(?Carbon $carbon): ?string
    {
        return $carbon ? $carbon->toIso8601String() : null;
    }

    /**
     * Resolve arbitrary settings document by document_name.
     */
    protected function resolveSettingDocument(string $documentName): array
    {
        static $cache = [];

        if (array_key_exists($documentName, $cache)) {
            return $cache[$documentName];
        }

        $row = DB::table('settings')
            ->where('document_name', $documentName)
            ->first();

        if (!$row || empty($row->fields)) {
            $cache[$documentName] = [];
            return [];
        }

        $decoded = json_decode($row->fields, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            $cache[$documentName] = [];
            return [];
        }

        $cache[$documentName] = $decoded;

        return $decoded;
    }

    /**
     * Resolve admin surge fee from settings.
     */
    protected function resolveAdminSurgeFeeValue(): int
    {
        $config = $this->resolveSettingDocument('surge_rules_config');
        return (int) ($config['admin_surge_fee'] ?? 0);
    }

    /**
     * Remove stray quotes around stored ISO timestamps.
     */
    protected function sanitizeIsoString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            return (string) $value;
        }

        return trim($value, "\"' ");
    }

    /**
     * Normalize loose array inputs (stringified JSON or repeated query params).
     */
    protected function normalizeInputArray($value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Allow clients to send JSON strings for complex fields.
     */
    protected function prepareOrderPayload(Request $request): void
    {
        foreach ([
            'cart_items',
            'selected_address',
            'special_discount',
            'calculated_charges',
            'tax_setting',
            'author',
        ] as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $request->merge([$field => $decoded]);
                }
            }
        }
    }

    public function getStorySettings(): JsonResponse
    {
        try {
            $setting = DB::table('settings')
                ->where('document_name', 'story')
                ->first();

            if (!$setting) {
                return $this->error('Story settings not found', 404);
            }

            $array = (array)$setting;
            unset($array['id'], $array['document_name']);
            $jsonColumnValue = reset($array);

            $settingsData = json_decode($jsonColumnValue, true);

            return $this->success([
                'videoDuration' => (double)($settingsData['videoDuration'] ?? 0),
                'isEnabled' => (bool)($settingsData['isEnabled'] ?? false),
            ]);
        } catch (\Throwable $e) {
            return $this->error('Error fetching settings: ' . $e->getMessage(), 500);
        }
    }

    public function getActiveCurrency(): JsonResponse
    {
        try {
            $currency = DB::table('currencies')
                ->where('isActive', 1)
                ->first();

            // If no active currency → return default USD
            if (!$currency) {
                return $this->success([
                    'id' => '',
                    'code' => 'USD',
                    'name' => 'US Dollar',
                    'symbol' => '$',
                    'decimalDigits' => 2,
                    'symbolAtRight' => false,
                    'enable' => true,
                ]);
            }

            return $this->success([
                'id' => $currency->id ?? '',
                'code' => $currency->code ?? 'INR',
                'name' => $currency->name ?? 'Indian Rupee',
                'symbol' => $currency->symbol ?? '₹',
                'decimalDigits' => isset($currency->decimalDigits)
                    ? (int)$currency->decimalDigits
                    : 2,
                'symbolAtRight' => isset($currency->symbolAtRight)
                    ? (bool)$currency->symbolAtRight
                    : false,
                'enable' => isset($currency->isActive)
                    ? (bool)$currency->isActive
                    : true,
            ]);

        } catch (\Throwable $e) {
            return $this->error('Error fetching active currency: ' . $e->getMessage(), 500);
        }
    }



    public function getLanguages(): JsonResponse
    {
        try {
            $setting = DB::table('settings')
                ->where('document_name', 'languages')
                ->first();

            if (!$setting) {
                return response()->json([
                    'success' => false,
                    'message' => 'Languages settings not found'
                ], 404);
            }

            $array = (array) $setting;
            unset($array['id'], $array['document_name']);
            $jsonColumnValue = reset($array);

            $settingsData = json_decode($jsonColumnValue, true);

            $languages = $settingsData['list'] ?? [];

            // Optional: Normalize types (because they are strings in DB)
            $languages = array_map(function ($lang) {
                return [
                    'slug' => $lang['slug'] ?? '',
                    'title' => $lang['title'] ?? '',
                    'image' => $lang['image'] ?? '',
                    'isActive' => filter_var($lang['isActive'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'is_rtl' => filter_var($lang['is_rtl'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
            }, $languages);

            return response()->json([
                "success" => true,
                "data" => $languages
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching languages: ' . $e->getMessage()
            ], 500);
        }
    }




    public function getRestaurantSettings(): JsonResponse
    {
        try {
            $setting = DB::table('settings')
                ->where('document_name', 'restaurant')
                ->first();

            if (!$setting) {
                return $this->error('Story settings not found', 404);
            }

            $array = (array)$setting;
            unset($array['id'], $array['document_name']);
            $jsonColumnValue = reset($array);

            $settingsData = json_decode($jsonColumnValue, true);

            return $this->success([
                'subscription_model' => (bool)($settingsData['subscription_model'] ?? false),
                'auto_approve_restaurant' => (bool)($settingsData['auto_approve_restaurant'] ?? false),
            ]);
        } catch (\Throwable $e) {
            return $this->error('Error fetching settings: ' . $e->getMessage(), 500);
        }
    }
}

