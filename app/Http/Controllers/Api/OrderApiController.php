<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\restaurant_orders;
use App\Models\restaurant_orders as RestaurantOrder;
use App\Models\User;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderApiController extends Controller
{
    /**
     * List orders for the authenticated customer.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        // Determine the authorID to filter by (supports both authorId and author_id)
        $authorId = $request->query('authorId')
            ?? $request->query('author_id')
            ?? $user->firebase_id
            ?? $user->id;

        if (empty($authorId)) {
            return response()->json([
                'success' => false,
                'message' => 'Author ID is required',
            ], 400);
        }

        // Pagination settings
        $perPage = (int) $request->query('limit', 50);
        $perPage = max(1, min($perPage, 100));
        $page = max(1, (int) $request->query('page', 1));
        $offset = ($page - 1) * $perPage;

        // Base query
        $query = RestaurantOrder::where('authorID', $authorId);

        $statusGroups = $this->statusGroups();
        $filterRaw = (string) $request->query('filter', '');
        $filterKey = $this->resolveStatusGroupKey($filterRaw);
        $statusParam = $request->query('status');
        $statusKey = $statusParam !== null ? $this->resolveStatusGroupKey((string) $statusParam) : null;
        $normalizedStatusParam = $this->normalizeFilterKey((string) $statusParam);
        $normalizedFilterParam = $this->normalizeFilterKey($filterRaw);

        if ($filterKey !== null) {
            $this->applyStatusListFilter($query, $statusGroups[$filterKey]);
        } elseif ($normalizedFilterParam === 'active') {
            $activeStatuses = array_merge(
                $statusGroups['new'] ?? [],
                $statusGroups['in_progress'] ?? []
            );
            $this->applyStatusListFilter($query, $activeStatuses);
        } elseif ($statusKey !== null) {
            $this->applyStatusListFilter($query, $statusGroups[$statusKey]);
        } elseif (!empty($statusParam) && strtolower($statusParam) !== 'all') {
            if ($normalizedStatusParam === 'active') {
                $activeStatuses = array_merge(
                    $statusGroups['new'] ?? [],
                    $statusGroups['in_progress'] ?? []
                );
                $this->applyStatusListFilter($query, $activeStatuses);
            } else {
                $this->applyStatusListFilter($query, [(string) $statusParam]);
            }
        }

        // Vendor / Driver filters
        $vendorId = $request->query('vendorId') ?? $request->query('vendor_id');
        if (!empty($vendorId)) {
            $query->where('vendorID', $vendorId);
        }

        $driverId = $request->query('driverId') ?? $request->query('driver_id');
        if (!empty($driverId)) {
            $query->where('driverID', $driverId);
        }

        // Payment method filter
        $paymentMethod = $request->query('paymentMethod') ?? $request->query('payment_method');
        if (!empty($paymentMethod)) {
            $query->where('payment_method', $paymentMethod);
        }

        // Delivery vs takeaway filter
        $orderType = strtolower((string) $request->query('order_type', ''));
        if ($orderType === 'takeaway') {
            $query->where(function ($q) {
                $q->where('takeAway', '1')
                  ->orWhereRaw('LOWER(takeAway) = ?', ['true']);
            });
        } elseif ($orderType === 'delivery') {
            $query->where(function ($q) {
                $q->whereNull('takeAway')
                  ->orWhere('takeAway', '0')
                  ->orWhereRaw('LOWER(takeAway) = ?', ['false']);
            });
        }

        // Date range filter
        $dateFrom = $request->query('date_from') ?? $request->query('from');
        $dateTo = $request->query('date_to') ?? $request->query('to');
        if (!empty($dateFrom) && !empty($dateTo)) {
            try {
                $from = Carbon::parse($dateFrom)->startOfDay();
                $to = Carbon::parse($dateTo)->endOfDay();
                $query->whereBetween('createdAt', [
                    $from->toIso8601ZuluString(),
                    $to->toIso8601ZuluString(),
                ]);
            } catch (\Throwable $e) {
                // ignore invalid date formats
            }
        }

        // Search filter (optional)
        if ($request->filled('search')) {
            $search = strtolower($request->query('search'));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(id) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(status) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(vendorID) LIKE ?', ["%{$search}%"]);
            });
        }

        // Total count before pagination
        $total = (clone $query)->count();

        // Fetch paginated results
        $orders = $query
            ->orderByDesc('createdAt')
            ->orderByDesc('id')
            ->skip($offset)
            ->take($perPage)
            ->get();

        // Transform order data to match legacy payload expectations
        $data = $orders->map(function (RestaurantOrder $order) {
            return $this->transformOrder($order);
        })->values();

        // Optional: return status counts
        $statusCounts = null;
        $statusGroupCounts = null;
        if ($request->boolean('with_status_counts')) {
            $rawCounts = RestaurantOrder::where('authorID', $authorId)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->mapWithKeys(function ($value, $key) {
                    return [(string) $key => (int) $value];
                })
                ->toArray();

            $normalizedCounts = [];
            foreach ($rawCounts as $label => $value) {
                $normalizedCounts[strtolower($label)] = (int) $value;
            }

            $statusGroupCounts = [];
            foreach ($statusGroups as $groupKey => $statuses) {
                $statusGroupCounts[$groupKey] = 0;
                foreach ($statuses as $statusValue) {
                    $statusGroupCounts[$groupKey] += $normalizedCounts[strtolower($statusValue)] ?? 0;
                }
            }
            $statusGroupCounts['active'] = ($statusGroupCounts['new'] ?? 0) + ($statusGroupCounts['in_progress'] ?? 0);

            $statusCounts = $rawCounts;
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'count' => $data->count(),
                'total' => $total,
                'has_more' => ($offset + $perPage) < $total,
                'status_filter' => $statusParam,
                'status_counts' => $statusCounts,
                'status_groups' => $statusGroupCounts,
                'applied_filter' => $filterKey ?? ($normalizedFilterParam === 'active' ? 'active' : ($statusKey ?? ($normalizedStatusParam === 'active' ? 'active' : null))),
            ],
        ]);
    }

    /**
     * Fetch a single order that belongs to the authenticated customer.
     */
    public function show(Request $request, string $orderId)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        $authorId = (string) $request->query('author_id', '');
        if ($authorId === '') {
            $authorId = (string) ($user->firebase_id ?? $user->id ?? '');
        }

        $order = RestaurantOrder::query()
            ->where('id', $orderId)
            ->when(!empty($authorId), function ($query) use ($authorId) {
                $query->where('authorID', $authorId);
            })
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->transformOrder($order),
        ]);
    }

    /**
     * Return billing summary (ToPay + surge fee) for a given order.
     */
    public function billing(Request $request, string $orderId)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        $authorId = (string) $request->query('author_id', '');
        if ($authorId === '') {
            $authorId = (string) ($user->firebase_id ?? $user->id ?? '');
        }

        $order = RestaurantOrder::query()
            ->where('id', $orderId)
            ->when(!empty($authorId), function ($query) use ($authorId) {
                $query->where('authorID', $authorId);
            })
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }

        $toPayAmount = $order->toPayAmount !== null
            ? (float) $order->toPayAmount
            : $this->toFloat($order->ToPay);

        $products = $this->decodeJson($order->products, []);
        $taxSetting = $this->decodeJson($order->taxSetting, []);
        $specialDiscount = $this->decodeJson($order->specialDiscount, null);
        if ($specialDiscount === null && $order->specialDiscount !== null && $order->specialDiscount !== '') {
            $specialDiscount = [
                'special_discount' => $this->toFloat($order->specialDiscount),
            ];
        }

        $calculatedCharges = $this->resolveCalculatedCharges($order, $products, $taxSetting, $specialDiscount);

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => (string) $order->id,
                'toPay' => $toPayAmount,
                'toPay_raw' => $order->ToPay,
                'surge_fee' => $this->toFloat($order->surge_fee),
                'calculated_charges' => $calculatedCharges,
            ],
        ]);
    }

    /**
     * Transform DB row to API response that mirrors the legacy Firestore payload.
     */
    private function transformOrder(RestaurantOrder $order): array
    {
        $products = $this->decodeJson($order->products, []);
        $author = $this->decodeJson($order->author, []);
        $driver = $this->decodeJson($order->driver, []);
        $vendor = $this->decodeJson($order->vendor, []);
        $address = $this->decodeJson($order->address, []);
        $rejectedByDrivers = $this->decodeJson($order->rejectedByDrivers, []);
        $taxSetting = $this->decodeJson($order->taxSetting, []);

        $specialDiscount = $this->decodeJson($order->specialDiscount, null);
        if ($specialDiscount === null && $order->specialDiscount !== null && $order->specialDiscount !== '') {
            $specialDiscount = [
                'special_discount' => $this->toFloat($order->specialDiscount),
            ];
        }

        $calculatedCharges = $this->resolveCalculatedCharges($order, $products, $taxSetting, $specialDiscount);
        $amountSummary = $this->resolveAmounts($order, $products, $specialDiscount);

        $resolvedId = $this->resolveOrderIdentifier($order, $author, $driver, $calculatedCharges);

        return [
            'id' => $resolvedId,
            'triggerDelivery' => $this->normalizeTimestamp($order->triggerDelivery),
            'scheduleTime' => $this->normalizeTimestamp($order->scheduleTime),
            'estimatedTimeToPrepare' => $order->estimatedTimeToPrepare,
            'notes' => $order->notes,
            'vendorID' => $order->vendorID,
            'discount' => $this->toFloat($order->discount),
            'deliveryCharge' => $this->toFloat($order->deliveryCharge),
            'couponId' => $order->couponId,
            'authorID' => $order->authorID,
            'author' => $author,
            'adminCommission' => $this->toFloat($order->adminCommission),
            'adminCommissionType' => $order->adminCommissionType,
            'createdAt' => $this->normalizeTimestamp($order->createdAt),
            'driverID' => $order->driverID,
            'driver' => $driver,
            'rejectedByDrivers' => $rejectedByDrivers,
            'tip_amount' => $this->toFloat($order->tip_amount),
            'takeAway' => $this->toBool($order->takeAway),
            'couponCode' => $order->couponCode,
            'payment_method' => $order->payment_method,
            'ToPay' => $order->ToPay,
            'toPayAmount' => $order->toPayAmount !== null ? (float) $order->toPayAmount : null,
            'status' => $order->status,
            'calculatedCharges' => $calculatedCharges,
            'products' => is_array($products) ? $products : [],
            'vendor' => is_array($vendor) ? $vendor : [],
            'specialDiscount' => $specialDiscount,
            'address' => $address,
            'taxSetting' => is_array($taxSetting) ? $taxSetting : [],
            'orderAutoCancelAt' => $this->normalizeTimestamp($order->orderAutoCancelAt),
            'surge_fee' => $this->toFloat($order->surge_fee),
            'schedule_time' => $this->normalizeTimestamp($order->scheduleTime),
            'estimated_time_to_prepare' => $order->estimatedTimeToPrepare,
            'total' => $amountSummary['total'],
        ];
    }

    /**
     * Decode JSON safely and fall back to default when needed.
     *
     * @param  mixed  $value
     * @param  mixed  $default
     * @return mixed
     */
    private function decodeJson($value, $default)
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return $default;
        }

        $value = trim($value);
        if ($value === '' || strtolower($value) === 'null') {
            return $default;
        }

        try {
            $decoded = json_decode($value, true);
        } catch (\Throwable $e) {
            Log::warning('Failed to decode JSON value for order payload', [
                'value' => substr($value, 0, 200),
                'error' => $e->getMessage(),
            ]);
            return $default;
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $default;
        }

        if (is_string($decoded)) {
            $decodedSecondPass = json_decode($decoded, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decodedSecondPass;
            }
        }

        return $decoded;
    }

    /**
     * Convert numeric-like values to float.
     */
    private function toFloat($value): float
    {
        if (is_null($value)) {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $clean = preg_replace('/[^0-9\.\-]+/', '', $value);
            if ($clean === '' || !is_numeric($clean)) {
                return 0.0;
            }

            return (float) $clean;
        }

        return 0.0;
    }

    /**
     * Convert mixed takeAway values into boolean.
     */
    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower((string) $value);

        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    /**
     * Build calculated charges payload when the database column is empty.
     */
    private function buildCalculatedCharges(RestaurantOrder $order, $products, $taxSetting, $specialDiscount): array
    {
        $amounts = $this->resolveAmounts($order, $products, $specialDiscount);

        return [
            'sub_total' => $amounts['sub_total'],
            'discount' => $amounts['discount'],
            'delivery_charge' => $amounts['delivery'],
            'tip' => $amounts['tip'],
            'tax' => $amounts['tax'],
            'total' => $amounts['total'],
        ];
    }

    /**
     * Resolve calculated charges by honoring stored payloads first.
     */
    private function resolveCalculatedCharges(RestaurantOrder $order, $products, $taxSetting, $specialDiscount)
    {
        $calculated = $this->decodeJson($order->calculatedCharges, []);
        if (is_array($calculated) && !empty($calculated)) {
            return $calculated;
        }

        return $this->buildCalculatedCharges($order, $products, $taxSetting, $specialDiscount);
    }

    /**
     * Resolve subtotal/discount/tax/tip totals for an order.
     */
    private function resolveAmounts(RestaurantOrder $order, $products, $specialDiscount = null): array
    {
        $subTotal = 0.0;

        if (is_array($products)) {
            foreach ($products as $product) {
                $price = $this->toFloat($product['discountPrice'] ?? 0);
                if ($price <= 0) {
                    $price = $this->toFloat($product['price'] ?? 0);
                }
                $quantity = $this->toFloat($product['quantity'] ?? 1);
                $extrasPrice = $this->toFloat($product['extrasPrice'] ?? ($product['extras_price'] ?? 0));

                $subTotal += ($price * $quantity) + ($extrasPrice * $quantity);
            }
        }

        $discount = $this->toFloat($order->discount);
        $specialDiscountAmount = 0.0;
        if (is_array($specialDiscount) && isset($specialDiscount['special_discount'])) {
            $specialDiscountAmount = $this->toFloat($specialDiscount['special_discount']);
        }

        $delivery = $this->toFloat($order->deliveryCharge);
        $tip = $this->toFloat($order->tip_amount);

        // Tax is not easily derivable without the original breakdown; approximate with 0
        $tax = 0.0;
        if (is_array($specialDiscount) && isset($specialDiscount['tax'])) {
            $tax = $this->toFloat($specialDiscount['tax']);
        }

        $total = $subTotal + $delivery + $tip + $tax - $discount - $specialDiscountAmount;

        if ($order->toPayAmount !== null) {
            $total = (float) $order->toPayAmount;
        } elseif (!empty($order->ToPay)) {
            $raw = $this->toFloat($order->ToPay);
            if ($raw > 0) {
                $total = $raw;
            }
        }

        if ($total < 0) {
            $total = 0.0;
        }

        return [
            'sub_total' => $subTotal,
            'discount' => $discount + $specialDiscountAmount,
            'delivery' => $delivery,
            'tip' => $tip,
            'tax' => $tax,
            'total' => $total,
        ];
    }

    /**
     * Normalize order identifiers.
     */
    private function normalizeId($value): string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        if (is_numeric($value)) {
            $intVal = (int) $value;
            if ($intVal !== 0) {
                return (string) $intVal;
            }
        }

        return (string) $value;
    }

    /**
     * Normalize timestamp-like values stored as strings or ints.
     */
    private function normalizeTimestamp($value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTime::ATOM);
        }

        if (is_numeric($value)) {
            return date(\DateTime::ATOM, (int) $value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            $len = strlen($trimmed);
            if ($len >= 2) {
                $first = $trimmed[0];
                $last = $trimmed[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $trimmed = substr($trimmed, 1, -1);
                }
            }
            return $trimmed;
        }

        return (string) $value;
    }

    /**
     * Define status groupings for filters and counters.
     */
    private function statusGroups(): array
    {
        return [
            'new' => [
                'order placed',
                'restaurantorders placed',
                'pending',
                'order pending',
            ],
            'in_progress' => [
                'order accepted',
                'driver pending',
                'driver accepted',
                'order shipped',
                'order in transit',
                'preparing',
            ],
            'delivered' => [
                'order delivered',
                'order completed',
                'completed',
            ],
            'cancelled' => [
                'order cancelled',
                'order canceled',
                'cancelled',
            ],
            'rejected' => [
                'order rejected',
                'driver rejected',
                'rejected',
            ],
        ];
    }

    /**
     * Resolve a status filter key to one of the known groups.
     */
    private function resolveStatusGroupKey(string $key): ?string
    {
        $normalized = $this->normalizeFilterKey($key);
        if ($normalized === '' || $normalized === 'all' || $normalized === 'active') {
            return null;
        }

        $groups = $this->statusGroups();
        if (isset($groups[$normalized])) {
            return $normalized;
        }

        $aliases = [
            'new_orders' => 'new',
            'new_order' => 'new',
            'pending_orders' => 'new',
            'processing' => 'in_progress',
            'inprogress' => 'in_progress',
            'in-progress' => 'in_progress',
            'ongoing' => 'in_progress',
            'progress' => 'in_progress',
            'delivered_orders' => 'delivered',
            'completed' => 'delivered',
            'completed_orders' => 'delivered',
            'finished' => 'delivered',
            'cancelled_orders' => 'cancelled',
            'canceled_orders' => 'cancelled',
            'rejected_orders' => 'rejected',
        ];

        return $aliases[$normalized] ?? null;
    }

    /**
     * Apply a list of statuses to the query (case-insensitive).
     */
    private function applyStatusListFilter($query, array $statuses): void
    {
        $normalized = array_unique(array_filter(array_map(function ($status) {
            return strtolower(trim((string) $status));
        }, $statuses)));

        if (empty($normalized)) {
            return;
        }

        $query->where(function ($q) use ($normalized) {
            foreach ($normalized as $statusValue) {
                $q->orWhereRaw('LOWER(status) = ?', [$statusValue]);
            }
        });
    }

    /**
     * Normalize filter keys by lowercasing and converting separators to underscores.
     */
    private function normalizeFilterKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace(['-', ' '], '_', $normalized);
        return preg_replace('/_+/', '_', $normalized);
    }

    /**
     * Resolve a reliable identifier for the order.
     */
    private function resolveOrderIdentifier(RestaurantOrder $order, array $author, array $driver, $calculatedCharges): string
    {
        $primary = $this->normalizeId($order->id);
        if ($primary !== '' && $primary !== '0') {
            return $primary;
        }

        $fallbackColumns = [
            'orderID', 'orderId', 'order_id',
            'orderNumber', 'order_number',
            'doc_id', 'documentId', 'document_id',
            'legacy_id',
        ];

        foreach ($fallbackColumns as $column) {
            $value = $order->{$column} ?? null;
            if (!empty($value) && $value !== '0') {
                return (string) $value;
            }
        }

        $candidates = [
            data_get($author, 'inProgressOrderID.0'),
            data_get($driver, 'inProgressOrderID.0'),
            data_get($driver, 'orderRequestData.0'),
            data_get($calculatedCharges, 'orderId'),
            data_get($calculatedCharges, 'order_id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && $candidate !== '0') {
                return $candidate;
            }
        }

        return $primary;
    }

    public function track($orderId)
    {
        $order = RestaurantOrder::query()->find($orderId);

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $orderPayload = $this->transformOrder($order);

        $driverPayload = $this->resolveDriverPayload($order, $orderPayload['driver'] ?? []);
        $vendorPayload = $this->resolveVendorPayload($order, $orderPayload['vendor'] ?? []);
        $addressPayload = is_array($orderPayload['address'] ?? null)
            ? $orderPayload['address']
            : $this->decodeJson($orderPayload['address'] ?? null, []);

        $orderPayload['driver'] = $driverPayload;
        $orderPayload['vendor'] = $vendorPayload;
        $orderPayload['address'] = $addressPayload;

        $polyline = $this->buildPolylinePayload(
            $order->status,
            $driverPayload,
            $vendorPayload,
            $addressPayload
        );

        return response()->json([
            'order' => $orderPayload,
            'driver' => $driverPayload,
            'polyline' => $polyline,
        ]);
    }

    private function resolveDriverPayload(RestaurantOrder $order, $driverPayload): array
    {
        $payload = is_array($driverPayload) ? $driverPayload : [];

        $driverId = $order->driverID ?? ($payload['id'] ?? null);
        if (empty($driverId)) {
            return $payload;
        }

        $user = User::query()
            ->where('id', $driverId)
            ->orWhere('firebase_id', $driverId)
            ->first();

        if (!$user) {
            return $payload;
        }

        $location = $this->decodeJson($user->location ?? null, []);
        if (empty($payload['location']) && !empty($location)) {
            $payload['location'] = $location;
        }

        $userData = array_filter([
            'id' => $user->firebase_id ?? $user->id,
            'firebase_id' => $user->firebase_id,
            'firstName' => $user->firstName,
            'lastName' => $user->lastName,
            'phoneNumber' => $user->phoneNumber,
            'role' => $user->role,
        ], function ($value) {
            return !is_null($value);
        });

        return array_merge($userData, $payload);
    }

    private function resolveVendorPayload(RestaurantOrder $order, $vendorPayload): array
    {
        $payload = is_array($vendorPayload) ? $vendorPayload : [];

        $vendorId = $order->vendorID ?? ($payload['id'] ?? null);
        if (empty($vendorId)) {
            return $payload;
        }

        $vendor = Vendor::query()
            ->select(['id', 'title', 'latitude', 'longitude', 'location'])
            ->find($vendorId);

        if (!$vendor) {
            return $payload;
        }

        $vendorData = array_filter([
            'id' => $vendor->id,
            'title' => $vendor->title,
            'latitude' => $vendor->latitude,
            'longitude' => $vendor->longitude,
            'lat' => $vendor->latitude,
            'lng' => $vendor->longitude,
        ], function ($value) {
            return !is_null($value);
        });

        $location = $this->decodeJson($vendor->location ?? null, []);
        if (!empty($location)) {
            $vendorData['location'] = $location;
        }

        return array_merge($vendorData, $payload);
    }

    private function buildPolylinePayload(?string $status, array $driver, array $vendor, array $address): ?array
    {
        $driverPoint = $this->extractLatLng($driver);
        $vendorPoint = $this->extractLatLng($vendor);
        $addressPoint = $this->extractLatLng($address);

        if (!$driverPoint && !$vendorPoint && !$addressPoint) {
            return null;
        }

        $statusKey = strtolower((string) $status);
        $source = null;
        $destination = null;

        if ($statusKey === 'shipped' && $driverPoint && $vendorPoint) {
            $source = $driverPoint;
            $destination = $vendorPoint;
        } elseif (in_array($statusKey, ['in_transit', 'out_for_delivery', 'driver_assigned'], true) && $driverPoint && $addressPoint) {
            $source = $driverPoint;
            $destination = $addressPoint;
        } elseif ($vendorPoint && $addressPoint) {
            $source = $vendorPoint;
            $destination = $addressPoint;
        } elseif ($driverPoint && $vendorPoint) {
            $source = $driverPoint;
            $destination = $vendorPoint;
        } elseif ($driverPoint && $addressPoint) {
            $source = $driverPoint;
            $destination = $addressPoint;
        } else {
            $source = $vendorPoint ?? $driverPoint ?? $addressPoint;
            $destination = $addressPoint ?? $vendorPoint ?? $driverPoint;
        }

        return [
            'source' => $source,
            'destination' => $destination,
        ];
    }

    private function extractLatLng($payload): ?array
    {
        if (!is_array($payload)) {
            return null;
        }

        $lat = $this->resolveCoordinate($payload, [
            'lat',
            'latitude',
            'location.lat',
            'location.latitude',
            'coordinates.lat',
            'coordinates.latitude',
        ]);
        $lng = $this->resolveCoordinate($payload, [
            'lng',
            'lon',
            'long',
            'longitude',
            'location.lng',
            'location.longitude',
            'coordinates.lng',
            'coordinates.longitude',
        ]);

        if ($lat === null || $lng === null) {
            return null;
        }

        return [
            'lat' => $lat,
            'lng' => $lng,
        ];
    }

    private function resolveCoordinate(array $payload, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

}


