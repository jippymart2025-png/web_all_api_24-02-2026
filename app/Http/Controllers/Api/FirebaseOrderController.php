<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\restaurant_orders as RestaurantOrder;

class FirebaseOrderController extends Controller
{

    public function index(Request $request)
    {
        $statusFilter = $request->query('status');
        $vendorID = $request->query('vendorID');
        $limit = (int) $request->query('limit', 10);
        $page = (int) $request->query('page', 1);

        // Calculate offset for page-based pagination
        $offset = ($page - 1) * $limit;

        $cacheKey = "firebase_orders_v5_status_" . ($statusFilter ?: 'any') . "_vendor_" . ($vendorID ?: 'any') . "_page_{$page}_limit_{$limit}";

        $data = (function () use ($statusFilter, $vendorID, $limit, $offset) {
            $base = RestaurantOrder::query()->orderByDesc('created_at');
            if (!empty($statusFilter)) {
                $base->where('status', $statusFilter);
            }
            if (!empty($vendorID)) {
                $base->where('vendorID', $vendorID)->orWhere('vendor_id', $vendorID);
            }

            $total = $base->count();
            $rows = $base->skip($offset)->take($limit)->get();
            $orders = $rows->map(function ($row) {
                $payload = [
                    'id' => $row->id,
                    'products' => [],
                    'deliveryCharge' => $row->delivery_charge ?? 0,
                    'discount' => $row->discount ?? 0,
                    'tip_amount' => $row->tip_amount ?? 0,
                    'specialDiscount' => ['special_discount' => $row->special_discount ?? 0],
                    'vendor' => [
                        'title' => $row->restaurant_name ?? ($row->vendor_name ?? ''),
                        'photo' => $row->restaurant_photo ?? null,
                    ],
                    'vendorID' => $row->vendorID ?? $row->vendor_id ?? null,
                    'author' => [
                        'firstName' => $row->customer_first_name ?? '',
                        'lastName' => $row->customer_last_name ?? '',
                        'countryCode' => $row->customer_country_code ?? '',
                        'phoneNumber' => $row->customer_phone ?? '',
                        'email' => $row->customer_email ?? '',
                    ],
                    'authorID' => $row->customer_id ?? null,
                    'driverID' => $row->driver_id ?? null,
                    'takeAway' => (bool) ($row->takeaway ?? 0),
                    'createdAt' => $row->created_at,
                    'status' => $row->status ?? 'Unknown',
                    'payment_method' => $row->payment_method ?? '',
                    'address' => ['locality' => $row->address_locality ?? ''],
                ];
                return $this->transformOrderData($payload, (string) $row->id);
            })->all();

            return [
                'orders' => $orders,
                'has_more' => ($offset + $limit) < $total,
                'next_created_at' => null,
                'next_doc_id' => null,
            ];
        })();

        // Get total count and status breakdown (cached)
        $totalCount = null;
        $countersCacheKey = "firebase_orders_counts_v4_status_" . ($statusFilter ?: 'any') . "_vendor_" . ($vendorID ?: 'any');

        // Always try to get counters from cache first
        $counters = null;
        if ($page === 1 || $request->query('with_total') === '1') {
            $base = RestaurantOrder::query();
            if (!empty($statusFilter)) {
                $base->where('status', $statusFilter);
            }
            if (!empty($vendorID)) {
                $base->where('vendorID', $vendorID)->orWhere('vendor_id', $vendorID);
            }

            $total = (int) $base->count();
            $statusCounts = RestaurantOrder::selectRaw('LOWER(TRIM(status)) as s, COUNT(*) as c')
                ->when(!empty($vendorID), function ($q) use ($vendorID) {
                    $q->where(function ($qq) use ($vendorID) { $qq->where('vendorID', $vendorID)->orWhere('vendor_id', $vendorID); });
                })
                ->groupBy('s')
                ->pluck('c', 's');

            $activeOrders = 0;
            foreach (['order placed','order accepted','order shipped','in transit','driver pending'] as $k) {
                $activeOrders += (int) ($statusCounts[$k] ?? 0);
            }
            $completed = (int) ($statusCounts['order completed'] ?? 0);
            $pending = 0;
            foreach (['order placed','driver pending','in transit'] as $k) {
                $pending += (int) ($statusCounts[$k] ?? 0);
            }
            $cancelled = 0;
            foreach (['order rejected','driver rejected','order cancelled','cancelled'] as $k) {
                $cancelled += (int) ($statusCounts[$k] ?? 0);
            }

            $counters = [
                'total' => $total,
                'active_orders' => $activeOrders,
                'completed' => $completed,
                'pending' => $pending,
                'cancelled' => $cancelled,
            ];
        }

        // Set total count if counters are available
        if ($counters !== null) {
            $totalCount = $counters['total'] ?? 0;
        }

        return response()->json([
            'status' => true,
            'message' => 'Orders fetched successfully',
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'count' => count($data['orders']),
                'total' => $totalCount,
                'has_more' => $data['has_more'],
                'next_created_at' => $data['next_created_at'],
                'next_doc_id' => $data['next_doc_id'],
                'status_filter' => $statusFilter,
                'vendor_id' => $vendorID,
            ],
            'counters' => $counters,
            'data' => $data['orders'],
        ]);
    }

    /**
     * Transform order data to return only necessary fields
     * Fields: restaurantorders ID, Restaurant, Drivers, Client, Date, Amount, restaurantorders Type, restaurantorders Status
     */
    private function transformOrderData(array $data, string $docId): array
    {
        // Calculate total amount
        $productTotal = 0;
        if (isset($data['products']) && is_array($data['products'])) {
            foreach ($data['products'] as $product) {
                $price = $this->sanitizeNumber($product['price'] ?? 0);
                $discountPrice = $this->sanitizeNumber($product['discountPrice'] ?? 0);
                $quantity = (int) ($product['quantity'] ?? 1);
                $extrasPrice = $this->sanitizeNumber($product['extras_price'] ?? 0);

                $itemPrice = $discountPrice > 0 ? $discountPrice : $price;
                $productTotal += ($itemPrice + $extrasPrice) * $quantity;
            }
        }

        $deliveryCharge = $this->sanitizeNumber($data['deliveryCharge'] ?? 0);
        $discount = $this->sanitizeNumber($data['discount'] ?? 0);
        $tipAmount = $this->sanitizeNumber($data['tip_amount'] ?? 0);
        $specialDiscount = $this->sanitizeNumber($data['specialDiscount']['special_discount'] ?? 0);

        $totalAmount = $productTotal + $deliveryCharge + $tipAmount - $discount - $specialDiscount;

        // Extract restaurant info
        $restaurantName = $data['vendor']['title'] ?? 'N/A';
        $restaurantId = $data['vendorID'] ?? '';
        $restaurantPhoto = $data['vendor']['photo'] ?? null;

        // Extract client info
        $clientName = trim(($data['author']['firstName'] ?? '') . ' ' . ($data['author']['lastName'] ?? ''));
        $clientId = $data['authorID'] ?? '';
        $clientPhone = ($data['author']['countryCode'] ?? '') . ($data['author']['phoneNumber'] ?? '');
        $clientEmail = $data['author']['email'] ?? '';

        // Extract driver info
        $driverId = $data['driverID'] ?? null;
        $driverName = null;
        $driverPhone = null;

        // Note: Driver details might need to be fetched separately if not in order doc
        // For now, we just return the driverID

        // restaurantorders type (takeaway or delivery)
        $orderType = isset($data['takeAway']) && $data['takeAway'] === true ? 'Takeaway' : 'Delivery';

        // Format date
        $createdAt = $this->formatTimestamp($data['createdAt'] ?? null);

        return [
            // restaurantorders ID
            'order_id' => $data['id'] ?? $docId,

            // Restaurant details
            'restaurant' => [
                'id' => $restaurantId,
                'name' => $restaurantName,
                'photo' => $restaurantPhoto,
            ],

            // Driver details
            'driver' => [
                'id' => $driverId,
                'name' => $driverName, // May need separate query to populate
                'phone' => $driverPhone,
            ],

            // Client details
            'client' => [
                'id' => $clientId,
                'name' => $clientName,
                'phone' => $clientPhone,
                'email' => $clientEmail,
            ],

            // Date
            'date' => $createdAt,
            'created_at_raw' => $data['createdAt'] ?? null,

            // Amount
            'amount' => number_format($totalAmount, 2, '.', ''),
            'amount_breakdown' => [
                'subtotal' => number_format($productTotal, 2, '.', ''),
                'delivery_charge' => number_format($deliveryCharge, 2, '.', ''),
                'tip' => number_format($tipAmount, 2, '.', ''),
                'discount' => number_format($discount + $specialDiscount, 2, '.', ''),
            ],

            // restaurantorders Type
            'order_type' => $orderType,

            // restaurantorders Status
            'status' => $data['status'] ?? 'Unknown',

            // Payment method
            'payment_method' => $data['payment_method'] ?? '',

            // Additional useful fields
            'products_count' => isset($data['products']) ? count($data['products']) : 0,
            'address' => $data['address']['locality'] ?? '',
        ];
    }

    /**
     * Sanitize numeric values to prevent NaN/Infinity
     */
    private function sanitizeNumber($value): float
    {
        if (is_numeric($value)) {
            $float = (float) $value;
            if (is_nan($float) || is_infinite($float)) {
                return 0.0;
            }
            return $float;
        }
        return 0.0;
    }

    /**
     * Format Firestore timestamp to readable format
     */
    private function formatTimestamp($timestamp): ?string
    {
        if (empty($timestamp)) {
            return null;
        }

        // Handle Firestore Timestamp object
        if (is_object($timestamp) && method_exists($timestamp, 'toDateTime')) {
            return $timestamp->toDateTime()->format('Y-m-d H:i:s');
        }

        // Handle Unix timestamp
        if (is_numeric($timestamp)) {
            return date('Y-m-d H:i:s', $timestamp);
        }

        // Handle string timestamp
        if (is_string($timestamp)) {
            return $timestamp;
        }

        return null;
    }
}
