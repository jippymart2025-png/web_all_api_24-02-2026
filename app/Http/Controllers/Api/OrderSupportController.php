<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MartItem;
use App\Models\VendorProduct;
use App\Models\restaurant_orders as RestaurantOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Throwable;

class OrderSupportController extends Controller
{
    /**
     * Place a simplified order coming from the mobile client.
     */
    public function placeOrder(Request $request): JsonResponse
    {
        $this->hydrateJsonFields($request, ['products', 'address']);

        $validator = Validator::make($request->all(), [
            'user_id' => ['required', 'string'],
            'vendor_id' => ['required', 'string'],
            'products' => ['required', 'array', 'min:1'],
            'products.*.id' => ['required', 'string'],
            'products.*.quantity' => ['required', 'numeric', 'min:1'],
            'payment_method' => ['required', 'string'],
            'delivery_charge' => ['required', 'numeric'],
            'promotion' => ['required', 'numeric'],
            'discount' => ['nullable', 'numeric'],
            'coupon_id' => ['nullable', 'string'],
            'coupon_code' => ['nullable', 'string'],
            'to_pay' => ['required', 'numeric'],
            'schedule_time' => ['nullable', 'date'],
            'address' => ['required', 'array'],
            'surge_fee' => ['nullable', 'numeric', 'min:0'],
            'admin_surge_fee' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors()->toArray());
        }

        $orderId = $this->generateOrderId();
        $products = array_map(fn ($item) => $this->normalizeArray($item), $request->input('products', []));
        $address = $this->normalizeArray($request->input('address'));
        $scheduleTimeIso = $request->filled('schedule_time')
            ? $this->toIsoString(Carbon::parse($request->input('schedule_time')))
            : null;

        try {
            DB::transaction(function () use ($request, $orderId, $products, $address, $scheduleTimeIso) {
                RestaurantOrder::query()->create([
                    'id' => $orderId,
                    'authorID' => $request->input('user_id'),
                    'vendorID' => $request->input('vendor_id'),
                    'products' => json_encode($products, JSON_UNESCAPED_UNICODE),
                    'payment_method' => $request->input('payment_method'),
                    'deliveryCharge' => (string) $request->input('delivery_charge'),
                    'discount' => (string) ($request->input('discount') ?? 0),
                    'couponId' => $request->input('coupon_id') ?? '',
                    'promotion' => (int) $request->input('promotion', 0),
                    'couponCode' => $request->input('coupon_code') ?? '',
                    'ToPay' => (string) $request->input('to_pay'),
                    'toPayAmount' => (float) $request->input('to_pay'),
                    'scheduleTime' => $scheduleTimeIso,
                    'address' => json_encode($address, JSON_UNESCAPED_UNICODE),
                    'status' => 'Order Placed',
                    'createdAt' => $this->toIsoString(Carbon::now()),
                    'takeAway' => 'false',
                ]);

                DB::table('order_billing')->updateOrInsert(
                    ['orderId' => $orderId],
                    [
                        'id' => (string) Str::uuid(),
                        'createdAt' => $this->toIsoString(Carbon::now()),
                        'orderId' => $orderId,
                        'ToPay' => (string) $request->input('to_pay'),
                        'surge_fee' => (float) ($request->input('surge_fee') ?? 0),
                        'admin_surge_fee' => (float) ($request->input('admin_surge_fee') ?? 0),
                        'total_surge_fee' => (float) (($request->input('surge_fee') ?? 0) + ($request->input('admin_surge_fee') ?? 0)),
                    ]
                );

                if ($request->filled('coupon_id')) {
                    $this->storeCouponUsage($request->input('user_id'), (string) $request->input('coupon_id'));
                }

                foreach ($products as $product) {
                    $productId = Arr::get($product, 'id');
                    $quantity = (int) Arr::get($product, 'quantity', 0);

                    if (!$productId || $quantity <= 0) {
                        continue;
                    }

                    if ($this->isMartProduct($product)) {
                        MartItem::query()->where('id', $productId)->decrement('quantity', $quantity);
                    } else {
                        VendorProduct::query()->where('id', $productId)->decrement('quantity', $quantity);
                    }
                }
            });
        } catch (Throwable $e) {
            Log::error('Order placement failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return $this->rollbackFailedOrder(new Request([
                'order_id' => $orderId,
                'products' => $products,
            ]));
        }

        return $this->success([
            'order_id' => $orderId,
        ], 'Order placed successfully');
    }

    /**
     * Fetch the ToPay amount for a billing record.
     */
    public function fetchOrderToPay(string $orderId): JsonResponse
    {
        $row = DB::table('order_billing')->where('orderId', $orderId)->first();

        $toPay = $row ? ($row->ToPay ?? $row->toPayAmount ?? null) : null;

        return $this->success([
            'order_id' => $orderId,
            'to_pay' => $toPay !== null ? (float) $toPay : null,
            'found' => (bool) $row,
        ], $row ? 'Billing record found' : 'Billing record not found');
    }

    /**
     * Fetch surge fee information for an order billing record.
     */
    public function fetchOrderSurgeFee(string $orderId): JsonResponse
    {
        $row = DB::table('order_billing')->where('orderId', $orderId)->first();

        return $this->success([
            'order_id' => $orderId,
            'surge_fee' => $row ? (float) ($row->surge_fee ?? 0) : null,
            'admin_surge_fee' => $row ? (float) ($row->admin_surge_fee ?? 0) : null,
            'total_surge_fee' => $row ? (float) ($row->total_surge_fee ?? 0) : null,
            'found' => (bool) $row,
        ], $row ? 'Billing record found' : 'Billing record not found');
    }

    /**
     * Roll back a failed order and restock associated products.
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
            'products' => ['required', 'array', 'min:1'],
            'products.*.id' => ['required', 'string'],
            'products.*.quantity' => ['required', 'numeric', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors()->toArray());
        }

        $orderId = $request->input('order_id');
        $products = array_map(fn ($item) => $this->normalizeArray($item), $request->input('products', []));

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
     * Create or update order billing record.
     */
    public function createOrderBilling(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => ['required', 'string'],
            'to_pay' => ['required', 'string'],
            'surge_fee' => ['required', 'numeric', 'min:0'],
            'admin_surge_fee' => ['required', 'string'],
            'created_at' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors()->toArray());
        }

        try {
            $orderId = $request->input('order_id');
            $toPay = (string) $request->input('to_pay');
            $surgeFee = (float) $request->input('surge_fee');
            $adminSurgeFee = (string) $request->input('admin_surge_fee');

            // Calculate total surge fee
            $adminSurgeFeeNumeric = (float) $adminSurgeFee;
            $totalSurgeFee = (string) ($surgeFee + $adminSurgeFeeNumeric);

            // Use provided created_at or current time
            $createdAt = $request->input('created_at');
            if (empty($createdAt)) {
                $createdAt = $this->toIsoString(Carbon::now());
            }

            // Check if billing record already exists
            $existing = DB::table('order_billing')->where('orderId', $orderId)->first();

            $billingData = [
                'createdAt' => $createdAt,
                'orderId' => $orderId,
                'ToPay' => $toPay,
                'surge_fee' => $surgeFee,
                'admin_surge_fee' => $adminSurgeFee,
                'total_surge_fee' => $totalSurgeFee,
            ];

            if ($existing) {
                // Update existing record
                DB::table('order_billing')
                    ->where('orderId', $orderId)
                    ->update($billingData);

                return $this->success([
                    'order_id' => $orderId,
                    'billing' => $billingData,
                ], 'Order billing updated successfully', 200);
            } else {
                // Create new record
                $billingData['id'] = (string) Str::uuid();
                DB::table('order_billing')->insert($billingData);

                return $this->success([
                    'order_id' => $orderId,
                    'billing' => $billingData,
                ], 'Order billing created successfully', 201);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to create order billing', [
                'order_id' => $request->input('order_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('Failed to create order billing: ' . $e->getMessage(), 500);
        }
    }

    protected function success($data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
        ], $status);
    }

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

    protected function isMartProduct(array $product): bool
    {
        $vendorId = (string) Arr::get($product, 'vendorID', '');
        if (Str::startsWith($vendorId, 'mart_')) {
            return true;
        }

        return (bool) Arr::get($product, 'isMartItem', false);
    }

    protected function hydrateJsonFields(Request $request, array $fields): void
    {
        foreach ($fields as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $request->merge([$field => $decoded]);
                }
            }
        }
    }

    protected function toIsoString(?Carbon $carbon): ?string
    {
        return $carbon ? $carbon->toIso8601String() : null;
    }

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
}


