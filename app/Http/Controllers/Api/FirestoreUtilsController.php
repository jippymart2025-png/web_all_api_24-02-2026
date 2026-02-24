<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\documents_verify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FirestoreUtilsController extends Controller
{
    /**
     * Check if referral code is valid
     */
    public function checkReferralCodeValidOrNot(Request $request)
    {
        try {
            $referralCode = $request->input('referralCode');

            if (empty($referralCode)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Referral code is required'
                ], 400);
            }

            $exists = DB::table('referral')
                ->where('referralCode', $referralCode)
                ->exists();

            return response()->json([
                'success' => true,
                'data' => $exists
            ]);
        } catch (\Exception $e) {
            Log::error('checkReferralCodeValidOrNot error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error checking referral code'
            ], 500);
        }
    }

    /**
     * Get referral user by code
     */
    public function getReferralUserByCode(Request $request)
    {
        try {
            $referralCode = $request->input('referralCode');

            if (empty($referralCode)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Referral code is required'
                ], 400);
            }

            $referral = DB::table('referral')
                ->where('referralCode', $referralCode)
                ->first();

            if (!$referral) {
                return response()->json([
                    'success' => false,
                    'message' => 'Referral not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $referral
            ]);
        } catch (\Exception $e) {
            Log::error('getReferralUserByCode error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching referral'
            ], 500);
        }
    }

    /**
     * Add referral
     */
    public function referralAdd(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required|string',
                'referralCode' => 'required|string',
                'referralBy' => 'nullable|string'
            ]);

            DB::table('referral')->updateOrInsert(
                ['id' => $request->input('id')],
                [
                    'referralCode' => $request->input('referralCode'),
                    'referralBy' => $request->input('referralBy', '')
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Referral added successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('referralAdd error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error adding referral'
            ], 500);
        }
    }

    /**
     * Get order by order ID
     */
       public function getOrderByOrderId($orderId)
    {
        try {
            $order = DB::table('restaurant_orders')
                ->where('id', $orderId)
                ->first();

            // If no order found
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                    'data' => null
                ], 404);
            }

            $order = (array) $order;

            // Convert takeAway: 0 → false, 1 → true
            if (isset($order['takeAway'])) {
                $order['takeAway'] = $order['takeAway'] == 1 ? true : false;
            }

            // Fields expected to contain JSON
            $jsonFields = [
                'products',
                'address',
                'vendor',
                'author',
                'taxSetting',
                'calculatedCharges',
                'statusHistory',
                'driver',
                'specialDiscount'
            ];

            foreach ($jsonFields as $field) {
                if (!empty($order[$field])) {

                    if (is_string($order[$field])) {
                        $decoded = json_decode($order[$field], true);

                        // If JSON decode fails → return error
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            return response()->json([
                                'success' => false,
                                'message' => "Invalid JSON format in field: $field",
                                'data' => null
                            ], 400);
                        }

                        $order[$field] = $decoded;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => $order
            ]);

        } catch (\Exception $e) {
            Log::error('getOrderByOrderId error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching order',
                'data' => null
            ], 500);
        }
    }


    /**
     * Get all orders for vendor
     */
    public function getAllOrder(Request $request)
    {
        try {
            $vendorId = $request->input('vendorID') ?? $request->user()->vendorID ?? null;

            if (!$vendorId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor ID is required'
                ], 400);
            }

            $orders = DB::table('restaurant_orders')
                ->where('vendorID', $vendorId)
                ->orderByRaw("STR_TO_DATE(REPLACE(REPLACE(createdAt, '\"', ''), 'Z', ''), '%Y-%m-%dT%H:%i:%s.%f') DESC")
                ->get()
                ->toArray();

            if (empty($orders)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            // JSON fields to decode
            $jsonFields = [
                'products',
                'address',
                'vendor',
                'author',
                'taxSetting',
                'calculatedCharges',
                'statusHistory',
                'driver',
                'specialDiscount'
            ];

            foreach ($orders as &$order) {
                $order = (array) $order; // Convert object to array

                foreach ($jsonFields as $field) {
                    if (!empty($order[$field]) && is_string($order[$field])) {

                        $decoded = json_decode($order[$field], true);

                        if (json_last_error() === JSON_ERROR_NONE) {
                            $order[$field] = $decoded;
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => $orders
            ]);

        } catch (\Exception $e) {
            Log::error('getAllOrder error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching orders'
            ], 500);
        }
    }

    /**
     * Set/Update order
     */
    public function setOrder(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required|string'
            ]);

            $input = $request->all();

            // Fetch columns from DB
            $columns = DB::getSchemaBuilder()->getColumnListing('restaurant_orders');

            // Accept only real table columns
            $data = array_intersect_key($input, array_flip($columns));

            // List of fields that need JSON encoding
            $jsonFields = [
                'address', 'products', 'vendor', 'author', 'specialDiscount',
                'rejectedByDrivers', 'calculatedCharges', 'scheduleTime',
                'triggerDelivery', 'createdAt'
            ];

            foreach ($jsonFields as $field) {
                if (isset($data[$field]) && !is_string($data[$field])) {
                    $data[$field] = json_encode($data[$field]);
                }
            }

            DB::table('restaurant_orders')->updateOrInsert(
                ['id' => $data['id']],
                $data
            );

            return response()->json([
                'success' => true,
                'message' => 'Order saved successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('setOrder error: '.$e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update order
     */
    public function updateOrder(Request $request, $orderId)
    {
        try {
            $data = $request->only([
                'notes', 'discount', 'deliveryCharge', 'products', 'status', 'taxSetting',
                'driverID', 'specialDiscount', 'tip_amount'
            ]);

            DB::table('restaurant_orders')
                ->where('id', $orderId)
                ->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Order updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('updateOrder error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating order'
            ], 500);
        }
    }

    /**
     * Restaurant vendor wallet set (credit order amount and tax)
     */
    public function restaurantVendorWalletSet(Request $request)
    {
        try {
            $request->validate([
                'orderId' => 'required|string',
                'vendorUid' => 'required|string',
                'basePrice' => 'required|numeric',
                'taxAmount' => 'required|numeric'
            ]);

            $orderId = $request->input('orderId');
            $vendorUid = $request->input('vendorUid');
            $basePrice = floatval($request->input('basePrice'));
            $taxAmount = floatval($request->input('taxAmount'));

            DB::beginTransaction();

            // Credit base price
            $walletId1 = Str::uuid()->toString();
            DB::table('wallet')->insert([
                'id' => $walletId1,
                'date' => now(),
                'note' => 'Order Amount credited',
                'transactionUser' => 'vendor',
                'amount' => $basePrice,
                'user_id' => $vendorUid,
                'payment_status' => 'success',
                'isTopUp' => 1,
                'order_id' => $orderId,
                'payment_method' => 'Wallet'
            ]);

            // Credit tax amount
            $walletId2 = Str::uuid()->toString();
            DB::table('wallet')->insert([
                'id' => $walletId2,
                'date' => now(),
                'note' => 'Order Tax credited',
                'transactionUser' => 'vendor',
                'amount' => $taxAmount,
                'user_id' => $vendorUid,
                'payment_status' => 'success',
                'isTopUp' => 1,
                'order_id' => $orderId,
                'payment_method' => 'Tax'
            ]);

            // Vendor wallet update
            DB::table('users')
                ->where('firebase_id', $vendorUid)
                ->increment('wallet_amount', $basePrice + $taxAmount);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Wallet credited successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('restaurantVendorWalletSet error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }
    }

    /**
     * Get order reviews by order ID and product ID
     */
    public function getOrderReviewsByID(Request $request)
    {
        try {
            $orderId = $request->input('orderId');
            $productId = $request->input('productID');

            if (!$orderId || !$productId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order ID and Product ID are required'
                ], 400);
            }

            $review = DB::table('foods_review')
                ->where('orderid', $orderId)
                ->where('productId', $productId)
                ->first();

            if (!$review) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $review
            ]);
        } catch (\Exception $e) {
            Log::error('getOrderReviewsByID error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching review'
            ], 500);
        }
    }

    /**
     * Get order reviews by vendor ID
     */
    public function getOrderReviewsByVenderId($vendorId)
    {
        try {
            $reviews = DB::table('foods_review')
                ->where('VendorId', $vendorId)
                ->orderByRaw("STR_TO_DATE(REPLACE(REPLACE(createdAt, '\"', ''), 'Z', ''), '%Y-%m-%dT%H:%i:%s.%f') DESC")
                ->get();

            return response()->json([
                'success' => true,
                'data' => $reviews
            ]);
        } catch (\Exception $e) {
            Log::error('getOrderReviewsByVenderId error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching reviews'
            ], 500);
        }
    }

    /**
     * Get products by vendor ID
     */
    public function getProduct(Request $request)
    {
        try {
            $vendorId = $request->input('vendorID') ?? $request->user()->vendorID ?? null;

            if (!$vendorId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor ID is required'
                ], 400);
            }

            $products = DB::table('vendor_products')
                ->where('vendorID', $vendorId)
                ->orderBy('createdAt', 'DESC')
                ->get()
                ->map(function ($item) {

                    $item->addOnsTitle           = json_decode($item->addOnsTitle ?? "[]");
                    $item->addOnsPrice           = json_decode($item->addOnsPrice ?? "[]");
                    $item->photos                = json_decode($item->photos ?? "[]");
                    $item->product_specification = json_decode($item->product_specification ?? "{}");
                    $item->item_attribute        = json_decode($item->item_attribute ?? "{}");

                    // createdAt FIX (remove double quote + convert)
                    if (!empty($item->createdAt)) {
                        $created = str_replace('"', '', $item->createdAt);
                        if (is_numeric($created)) {
                            $item->createdAt = (int)$created;
                        } else {
                            $item->createdAt = strtotime($created) * 1000;
                        }
                    }

                    return $item;
                });

            return response()->json([
                "success" => true,
                "data"    => $products
            ]);

        } catch (\Exception $e) {
            Log::error('getProduct error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching products'
            ], 500);
        }
    }

    /**
     * Get product by ID
     */
    public function RestaurantGetProductById($productId)
    {
        try {
            $product = DB::table('vendor_products')
                ->where('id', $productId)
                ->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found'
                ], 404);
            }

            // Convert object to array for modification
            $product = (array) $product;

            // Decode JSON fields if not null
            $jsonFields = [
                'addOnsPrice', 'addOnsTitle', 'photos',
                'item_attribute', 'product_specification'
            ];

            foreach ($jsonFields as $field) {
                if (!empty($product[$field])) {
                    $product[$field] = json_decode($product[$field], true);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $product
            ]);

        } catch (\Exception $e) {
            Log::error('getProductById error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching product'
            ], 500);
        }
    }

    /**
     * Set/Update product
     */
    public function setProduct(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required|string',
                'vendorID' => 'required|string',
                'name' => 'required|string',
            ]);

            // Prepare data for storing into DB
            $data = [
                "id" => $request->id,
                "vendorID" => $request->vendorID,
                "name" => $request->name,
                "veg" => $request->veg ?? null,
                "publish" => $request->publish ?? null,
                "addOnsTitle" => json_encode($request->addOnsTitle ?? []),
                "addOnsPrice" => json_encode($request->addOnsPrice ?? []),
                "calories" => $request->calories ?? null,
                "proteins" => $request->proteins ?? null,
                "fats" => $request->fats ?? null,
                "reviewsSum" => $request->reviewsSum ?? 0,
                "reviewsCount" => $request->reviewsCount ?? 0,
                "takeawayOption" => $request->takeawayOption ?? null,
                "disPrice" => $request->disPrice ?? "0",
                "price" => $request->price ?? "0",
                "merchant_price"=> $request->merchant_price ?? "0",
                "quantity" => $request->quantity ?? 0,
                "grams" => $request->grams ?? 0,
                "categoryID" => $request->categoryID,
                "nonveg" => $request->nonveg ?? null,
                "photo" => $request->photo,
                "photos" => json_encode($request->photos ?? []),
                "description" => $request->description ?? null,
                "product_specification" => json_encode($request->product_specification ?? []),
                "item_attribute" => json_encode($request->item_attribute ?? []),
                "createdAt" => $request->createdAt ?? now()->timestamp,
                "isAvailable" => $request->isAvailable ?? 1,
            ];

            // Insert or update
            DB::table('vendor_products')->updateOrInsert(
                ['id' => $request->id],
                $data
            );

            // Clear product feed cache for this vendor
            try {
                $cleared = $this->clearProductFeedCacheForVendor($request->vendorID);
                Log::info('Cache invalidation triggered in setProduct', [
                    'vendor_id' => $request->vendorID,
                    'cache_cleared' => $cleared,
                ]);
            } catch (\Exception $cacheError) {
                Log::error('Failed to clear cache in setProduct', [
                    'vendor_id' => $request->vendorID,
                    'error' => $cacheError->getMessage(),
                ]);
            }

            // ---------- JSON DECODE FOR RESPONSE ----------
            $responseData = $data;
            $responseData["addOnsTitle"] = json_decode($data["addOnsTitle"]);
            $responseData["addOnsPrice"] = json_decode($data["addOnsPrice"]);
            $responseData["photos"] = json_decode($data["photos"]);
            $responseData["product_specification"] = json_decode($data["product_specification"]);
            $responseData["item_attribute"] = json_decode($data["item_attribute"]);

            return response()->json([
                "success" => true,
                "message" => "Product saved successfully",
                "data" => $responseData
            ]);

        } catch (\Exception $e) {
            Log::error("setProduct error => ".$e->getMessage());

            return response()->json([
                "success" => false,
                "message" => "Something went wrong",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update product
     */
    public function updateProduct(Request $request, $productId)
    {
        try {
            $data = $request->only([
                'description', 'price', 'disPrice', 'isAvailable', 'updatedAt',
                'name', 'photo', 'photos', 'categoryID'
            ]);

            DB::table('vendor_products')
                ->where('id', $productId)
                ->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('updateProduct error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating product'
            ], 500);
        }
    }

    /**
     * Delete product
     */
    public function deleteProduct($productId)
    {
        try {
            DB::table('vendor_products')
                ->where('id', $productId)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('deleteProduct error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error deleting product'
            ], 500);
        }
    }

    /**
     * Get advertisements by vendor ID
     */
    public function getAdvertisement(Request $request)
    {
        try {
            $vendorId = $request->input('vendorId') ?? $request->user()->vendorID ?? null;

            if (!$vendorId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor ID is required'
                ], 400);
            }

            $advertisements = DB::table('advertisements')
                ->where('vendorId', $vendorId)
                ->orderByRaw("STR_TO_DATE(REPLACE(REPLACE(createdAt, '\"', ''), 'Z', ''), '%Y-%m-%dT%H:%i:%s.%f') DESC")
                ->get();

            return response()->json([
                'success' => true,
                'data' => $advertisements
            ]);
        } catch (\Exception $e) {
            Log::error('getAdvertisement error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching advertisements'
            ], 500);
        }
    }

    /**
     * Get advertisement by ID
     */
    public function getAdvertisementById($advertisementId)
    {
        try {
            $advertisement = DB::table('advertisements')
                ->where('id', $advertisementId)
                ->first();

            if (!$advertisement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Advertisement not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $advertisement
            ]);
        } catch (\Exception $e) {
            Log::error('getAdvertisementById error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching advertisement'
            ], 500);
        }
    }

    /**
     * Create/Update advertisement
     */
    public function firebaseCreateAdvertisement(Request $request)
    {
        try {
            $request->validate([
                'vendorId' => 'required|string',
                'title' => 'required|string'
            ]);

            $data = $request->all();
            $Id = Str::uuid()->toString();

            DB::table('advertisements')->updateOrInsert(
                ['id' => $Id ],
                $data
            );

            return response()->json([
                'success' => true,
                'message' => 'Advertisement saved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('firebaseCreateAdvertisement error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error saving advertisement'
            ], 500);
        }
    }

    /**
     * Remove advertisement
     */
    public function removeAdvertisement($advertisementId)
    {
        try {
            DB::table('advertisements')
                ->where('id', $advertisementId)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Advertisement deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('removeAdvertisement error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error deleting advertisement'
            ], 500);
        }
    }

    /**
     * Pause/Resume advertisement
     */
    public function pauseAndResumeAdvertisement(Request $request, $advertisementId)
    {
        try {
            $isPaused = $request->input('isPaused', false);

            DB::table('advertisements')
                ->where('id', $advertisementId)
                ->update(['isPaused' => $isPaused]);

            return response()->json([
                'success' => true,
                'message' => 'Advertisement updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('pauseAndResumeAdvertisement error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating advertisement'
            ], 500);
        }
    }

    /**
     * Get wallet transactions
     */
    public function getWalletTransaction(Request $request)
    {
        try {
            $userId = $request->input('userId') ?? $request->user()->firebase_id ?? null;

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User ID is required'
                ], 400);
            }

            $transactions = DB::table('wallet')
                ->where('user_id', $userId)
                ->orderByRaw("STR_TO_DATE(REPLACE(REPLACE(date, '\"', ''), 'Z', ''), '%Y-%m-%dT%H:%i:%s.%f') DESC")
                ->get();

            return response()->json([
                'success' => true,
                'data' => $transactions
            ]);
        } catch (\Exception $e) {
            Log::error('getWalletTransaction error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching wallet transactions'
            ], 500);
        }
    }

    /**
     * Get filtered wallet transactions
     */
    public function getFilterWalletTransaction(Request $request)
    {
        try {
            $userId = $request->input('userId') ?? $request->user()->firebase_id ?? null;
            $startTime = $request->input('startTime');
            $endTime = $request->input('endTime');

            if (!$userId || !$startTime || !$endTime) {
                return response()->json([
                    'success' => false,
                    'message' => 'User ID, start time and end time are required'
                ], 400);
            }

            $transactions = DB::table('wallet')
                ->where('user_id', $userId)
                ->whereRaw("STR_TO_DATE(REPLACE(REPLACE(date, '\"', ''), 'Z', ''), '%Y-%m-%dT%H:%i:%s.%f') BETWEEN ? AND ?", [$startTime, $endTime])
                ->orderByRaw("STR_TO_DATE(REPLACE(REPLACE(date, '\"', ''), 'Z', ''), '%Y-%m-%dT%H:%i:%s.%f') DESC")
                ->get();

            return response()->json([
                'success' => true,
                'data' => $transactions
            ]);
        } catch (\Exception $e) {
            Log::error('getFilterWalletTransaction error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching wallet transactions'
            ], 500);
        }
    }

    /**
     * Get withdraw history
     */
    public function getWithdrawHistory(Request $request)
    {
        try {
            $vendorId = $request->input('vendorID') ?? $request->user()->vendorID ?? null;

            if (!$vendorId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor ID is required'
                ], 400);
            }

            $withdrawals = DB::table('payouts')
                ->where('vendorID', $vendorId)
                ->orderByRaw("STR_TO_DATE(REPLACE(REPLACE(paidDate, '\"', ''), 'Z', ''), '%Y-%m-%dT%H:%i:%s.%f') DESC")
                ->get();

            return response()->json([
                'success' => true,
                'data' => $withdrawals
            ]);
        } catch (\Exception $e) {
            Log::error('getWithdrawHistory error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching withdrawal history'
            ], 500);
        }
    }

    /**
     * Get payment settings data
     */
    public function getPaymentSettingsData()
    {
        try {
            $settings = DB::table('settings')
                ->whereIn('document_name', [
                    'payFastSettings', 'MercadoPago', 'paypalSettings', 'stripeSettings',
                    'flutterWave', 'payStack', 'PaytmSettings', 'walletSettings',
                    'razorpaySettings', 'CODSettings', 'midtrans_settings',
                    'orange_money_settings', 'xendit_settings'
                ])
                ->get()
                ->keyBy('document_name')
                ->map(function ($item) {
                    return json_decode($item->fields, true);
                });

            return response()->json([
                'success' => true,
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            Log::error('getPaymentSettingsData error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching payment settings'
            ], 500);
        }
    }

    /**
     * Get vendor by ID
     */
    public function getVendorById($vendorId)
    {
        try {
            $vendor = DB::table('vendors')
                ->where('id', $vendorId)
                ->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            // Convert stdClass to array
            $vendor = (array) $vendor;

            $jsonFields = [
                'photos', 'workingHours', 'categoryID', 'categoryTitle',
                'restaurantMenuPhotos', 'coordinates', 'g', 'filters',
                'specialDiscount', 'adminCommission'
            ];

            foreach ($jsonFields as $field) {
                if (!empty($vendor[$field]) && $this->isJson($vendor[$field])) {
                    $vendor[$field] = json_decode($vendor[$field], true);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $vendor
            ]);

        } catch (\Exception $e) {
            Log::error('getVendorById error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching vendor'
            ], 500);
        }
    }

    private function isJson($string)
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }


    /**
     * Create new vendor
     */
    public function firebaseCreateNewVendor(Request $request)
    {
        try {
            $request->validate([
                'title' => 'required|string|unique:vendors,title',
                'author' => 'required|string',   // This is the USER ID of the creator
            ]);

            $data = $request->all();

            // Auto-generate Firebase-style unique ID
            $data['id'] = $this->generateFirebaseId();

            // Check rare collision
            while (DB::table('vendors')->where('id', $data['id'])->exists()) {
                $data['id'] = $this->generateFirebaseId();
            }

            // JSON fields
            $jsonFields = [
                'photos', 'workingHours', 'categoryID', 'categoryTitle',
                'restaurantMenuPhotos', 'coordinates', 'g', 'filters',
                'specialDiscount', 'adminCommission'
            ];

            foreach ($jsonFields as $field) {
                if (isset($data[$field]) && is_array($data[$field])) {
                    $data[$field] = json_encode($data[$field]);
                }
            }

            // Default values
            $data['createdAt'] = now()->toIso8601String();
            $data['updatedAt'] = now()->toIso8601String();
            $data['reviewsCount'] = $data['reviewsCount'] ?? 0;
            $data['reviewsSum'] = $data['reviewsSum'] ?? 0;
            $data['isOpen'] = $data['isOpen'] ?? 1;
            $data['publish'] = $data['publish'] ?? 1;
            $data['reststatus'] = $data['reststatus'] ?? 1;
            $data['vType'] = $data['Restaurant'] ?? "Restaurant";


            // Insert valid columns only
            $allowedColumns = DB::getSchemaBuilder()->getColumnListing('vendors');
            $data = array_intersect_key($data, array_flip($allowedColumns));

            // INSERT VENDOR
            DB::table('vendors')->insert($data);

            // ---------------------------------------------------------
            // ✅ UPDATE USER WITH VENDOR ID
            // author = user_id (make sure this is correct)
            // ---------------------------------------------------------
            DB::table('users')
                ->where('firebase_id', $request->author)
                ->update([
                    'vendorID' => $data['id'],
                    '_updated_at' => now()
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Vendor created successfully',
                'data' => $data
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('firebaseCreateNewVendor error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error creating vendor',
                'error' => $e->getMessage()
            ], 500);
        }
    }


// Unique ID generator
    private function generateFirebaseId($length = 20)
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $max = strlen($characters) - 1;
        $id = '';

        for ($i = 0; $i < $length; $i++) {
            $id .= $characters[random_int(0, $max)];
        }

        return $id;
    }


    /**
     * Update vendor
     */
    public function updateVendor(Request $request, $vendorId)
    {
        try {
            // Check if vendor exists
            $vendor = DB::table('vendors')->where('id', $vendorId)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $data = $request->all();

            // Fields that require JSON encoding when value is an array
            $jsonFields = [
                'photos', 'workingHours', 'categoryID', 'categoryTitle',
                'restaurantMenuPhotos', 'coordinates', 'g', 'filters',
                'specialDiscount', 'adminCommission'
            ];

            foreach ($jsonFields as $field) {
                if (isset($data[$field]) && is_array($data[$field])) {
                    $data[$field] = json_encode($data[$field]);
                }
            }

            // Update timestamp
            $data['updatedAt'] = now()->toIso8601String();

            // Only include valid DB columns
            $allowedColumns = DB::getSchemaBuilder()->getColumnListing('vendors');
            $data = array_intersect_key($data, array_flip($allowedColumns));

            DB::table('vendors')->where('id', $vendorId)->update($data);

            return response()->json([
                'success' => true,
                'message' => 'Vendor updated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('updateVendor error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error updating vendor',
                'error' => $e->getMessage() // Remove this in production
            ], 500);
        }
    }

    /**
     * Get vendor categories
     */
    public function getVendorCategoryById()
    {
        try {
            $categories = DB::table('vendor_categories')
                ->where('publish', '1')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            Log::error('getVendorCategoryById error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching categories'
            ], 500);
        }
    }

    /**
     * Get vendor category by category ID
     */
    public function getVendorCategoryByCategoryId($categoryId)
    {
        try {
            $category = DB::table('vendor_categories')
                ->where('id', $categoryId)
                ->first();

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $category
            ]);
        } catch (\Exception $e) {
            Log::error('getVendorCategoryByCategoryId error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching category'
            ], 500);
        }
    }

    /**
     * Get vendor review attribute
     */
    public function getVendorReviewAttribute($attributeId)
    {
        try {
            $attribute = DB::table('review_attributes')
                ->where('id', $attributeId)
                ->first();

            if (!$attribute) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review attribute not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $attribute
            ]);
        } catch (\Exception $e) {
            Log::error('getVendorReviewAttribute error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching review attribute'
            ], 500);
        }
    }

    /**
     * Get attributes
     */
    public function getAttributes()
    {
        try {
            $attributes = DB::table('vendor_attributes')->get();

            return response()->json([
                'success' => true,
                'data' => $attributes
            ]);
        } catch (\Exception $e) {
            Log::error('getAttributes error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching attributes'
            ], 500);
        }
    }

    /**
     * Get delivery charge
     */
    public function getDeliveryCharge()
    {
        try {
            $setting = DB::table('settings')
                ->where('document_name', 'DeliveryCharge')
                ->first();

            if (!$setting) {
                return response()->json([
                    'success' => false,
                    'message' => 'Delivery charge settings not found'
                ], 404);
            }

            $data = json_decode($setting->fields, true);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('getDeliveryCharge error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching delivery charge'
            ], 500);
        }
    }

    public function GetDriverNearBy()
    {
        try {
            $setting = DB::table('settings')
                ->where('document_name', 'DriverNearBy')
                ->first();

            if (!$setting) {
                return response()->json([
                    'success' => false,
                    'message' => 'DriverNearBy settings not found'
                ], 404);
            }

            $data = json_decode($setting->fields, true);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('GetDriverNearBy error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching delivery charge'
            ], 500);
        }
    }

    /**
     * Get zone
     */
    public function getZone()
    {
        try {
            $zones = DB::table('zone')
                ->where('publish', true)
                ->get()
                ->map(function ($zone) {
                    // Decode area JSON into an array
                    if (!empty($zone->area)) {
                        $zone->area = json_decode($zone->area);
                    }
                    return $zone;
                });

            return response()->json([
                'success' => true,
                'data' => $zones
            ]);
        } catch (\Exception $e) {
            Log::error('getZone error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching zones'
            ], 500);
        }
    }

    /**
     * Get dine-in bookings
     */
    public function getDineInBooking(Request $request)
    {
        try {
            $isUpcoming = $request->input('isUpcoming', true);
            $vendorId = $request->input('vendorID') ?? $request->user()->vendorID ?? null;

            if (!$vendorId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor ID is required'
                ], 400);
            }

            $now = now()->toIso8601String();

            if ($isUpcoming) {
                $bookings = DB::table('booked_table')
                    ->where('vendorID', $vendorId)
                    ->whereRaw("STR_TO_DATE(REPLACE(REPLACE(date, '\"', ''), 'Z', ''), '%Y-%m-%dT%H:%i:%s.%f') > ?", [$now])
                    ->orderByRaw("STR_TO_DATE(REPLACE(REPLACE(date, '\"', ''), 'Z', ''), '%Y-%m-%dT%H:%i:%s.%f') DESC")
                    ->get();
            } else {
                $bookings = DB::table('booked_table')
                    ->where('vendorID', $vendorId)
                    ->whereRaw("STR_TO_DATE(REPLACE(REPLACE(date, '\"', ''), 'Z', ''), '%Y-%m-%dT%H:%i:%s.%f') < ?", [$now])
                    ->orderByRaw("STR_TO_DATE(REPLACE(REPLACE(date, '\"', ''), 'Z', ''), '%Y-%m-%dT%H:%i:%s.%f') DESC")
                    ->get();
            }

            return response()->json([
                'success' => true,
                'data' => $bookings
            ]);
        } catch (\Exception $e) {
            Log::error('getDineInBooking error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching bookings'
            ], 500);
        }
    }

    /**
     * Get all vendor coupons
     */
    public function getAllVendorCoupons($vendorId)
    {
        try {
            $query = DB::table('coupons')
                ->where('expiresAt', '>=', now()->toIso8601String())
                ->where('isEnabled', true)
                ->where('isPublic', true);

            if (!empty($vendorId)) {
                // Vendor coupons + global coupons
                $query->where(function ($q) use ($vendorId) {
                    $q->where('resturant_id', $vendorId)
                        ->orWhere('resturant_id', 'ALL');
                });
            } else {
                // Only global coupons if vendorId not provided
                $query->where('resturant_id', 'ALL  ');
            }

            $coupons = $query->get();

            return response()->json([
                'success' => true,
                'count' => $coupons->count(),
                'data' => $coupons
            ]);

        } catch (\Exception $e) {
            Log::error('getAllVendorCoupons error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching coupons'
            ], 500);
        }
    }

    /**
     * Get offers by vendor ID
     */
    public function getOffer($vendorId)
    {
        try {
            $offers = DB::table('coupons')
                ->where('resturant_id', $vendorId)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $offers
            ]);
        } catch (\Exception $e) {
            Log::error('getOffer error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching offers'
            ], 500);
        }
    }

    /**
     * Set coupon
     */

    public function setCoupon(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'id'             => 'required|string',
                'discountType'   => 'nullable|string',
                'code'           => 'nullable|string',
                'discount'       => 'nullable|string',
                'image'          => 'nullable|string',
                'expiresAt'      => 'nullable|date',
                'description'    => 'nullable|string',
                'isPublic'       => 'nullable|boolean',
                'resturant_id'   => 'nullable|string',
                'isEnabled'      => 'nullable|boolean',
            ]);

            $coupon = Coupon::updateOrCreate(
                ['id' => $validated['id']],
                $validated
            );

            return response()->json([
                'success' => true,
                'message' => 'Coupon saved successfully',
                'data' => $coupon
            ]);

        } catch (\Throwable $e) {
            \Log::error('setCoupon SQL error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete coupon
     */
    public function deleteCoupon($couponId)
    {
        try {
            DB::table('coupons')
                ->where('id', $couponId)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Coupon deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('deleteCoupon error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error deleting coupon'
            ], 500);
        }
    }

    /**
     * Get document list
     */
    public function getDocumentList()
    {
        try {
            $documents = DB::table('documents')
                ->where('type', 'restaurant')
                ->where('enable', true)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $documents
            ]);

        } catch (\Exception $e) {
            Log::error('getDocumentList error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching documents'
            ], 500);
        }
    }

    /**
     * Get document of driver
     */
    public function getDocumentOfDriver(Request $request)
    {
        try {
            $userId = $request->input('userId') ?? $request->user()->firebase_id ?? null;

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User ID is required'
                ], 400);
            }

            $document = DB::table('documents_verify')
                ->where('id', $userId)
                ->first();

            if (!$document) {
                return response()->json([
                    'success' => false,
                    'message' => 'Document not found'
                ], 404);
            }
            $document->documents = json_decode($document->documents, true);


            return response()->json([
                'success' => true,
                'data' => $document
            ]);



        } catch (\Exception $e) {
            Log::error('getDocumentOfDriver error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching document'
            ], 500);
        }
    }

    /**
     * Upload driver document
     */

    public function uploadDriverDocument(Request $request)
    {
        try {
            $request->validate([
                'user_id' => 'required|string',
                'documentId' => 'required|string',
                'front_image' => 'nullable|file|mimes:jpg,jpeg,png',
                'back_image' => 'nullable|file|mimes:jpg,jpeg,png',
                'status' => 'nullable|string',
                'type' => 'nullable|string',
            ]);

            $userId = $request->user_id;

            // Process file uploads
            $frontUrl = null;
            $backUrl = null;

            if ($request->hasFile('front_image')) {
                $frontUrl = asset('storage/' . $request->file('front_image')
                        ->store('driverDocuments/'.$userId, 'public'));
            }

            if ($request->hasFile('back_image')) {
                $backUrl = asset('storage/' . $request->file('back_image')
                        ->store('driverDocuments/'.$userId, 'public'));
            }

            // Get existing record
            $record = documents_verify::find($userId);
            $documents = $record ? $record->documents : [];

            // Check if doc already exists
            $index = collect($documents)->search(function ($doc) use ($request) {
                return $doc['documentId'] === $request->documentId;
            });

            $newDoc = [
                'documentId' => $request->documentId,
                'frontImage' => $frontUrl ?? ($documents[$index]['frontImage'] ?? ''),
                'backImage' => $backUrl ?? ($documents[$index]['backImage'] ?? ''),
                'status' => $request->status ?? ($documents[$index]['status'] ?? 'pending'),
            ];

            if ($index === false) {
                $documents[] = $newDoc;
            } else {
                $documents[$index] = $newDoc;
            }

            documents_verify::updateOrCreate(
                ['id' => $userId],
                [
                    'type' => $request->type,
                    'documents' => $documents
                ]
            );

            return response()->json([
                'success' => true,
                'message' => $index === false ?
                    'Document uploaded successfully' :
                    'Document updated successfully',
                'data' => $newDoc
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get withdraw method
     */
    public function getWithdrawMethod(Request $request)
    {
        try {
            $userId = $request->input('userId') ?? $request->user()->firebase_id ?? null;

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User ID is required'
                ], 400);
            }

            $method = DB::table('withdraw_method')
                ->where('userId', $userId)
                    ->first();

            if (!$method) {
                return response()->json([
                    'success' => false,
                    'message' => 'Withdraw method not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $method
            ]);
        } catch (\Exception $e) {
            Log::error('getWithdrawMethod error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching withdraw method'
            ], 500);
        }
    }

    /**
     * Set withdraw method
     */
    public function setWithdrawMethod(Request $request)
    {
        try {
            $request->validate([
                'userId' => 'required|string',
                'flutterwave' => 'nullable|string',
                'paypal' => 'nullable|string',
                'stripe' => 'nullable|string',
                'razorpay' => 'nullable|string'
            ]);

            $data = [
                'id' => $request->id ?? Str::uuid()->toString(),
                'userId' => $request->userId,
                'flutterwave' => $request->flutterwave,
                'paypal' => $request->paypal,
                'stripe' => $request->stripe,
                'razorpay' => $request->razorpay,
            ];

            DB::table('withdraw_method')->updateOrInsert(
                ['id' => $data['id']],
                $data
            );

            return response()->json([
                'success' => true,
                'message' => 'Withdraw method saved successfully',
                'data' => $data
            ]);

        } catch (\Exception $e) {
            Log::error('setWithdrawMethod error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() // Send real SQL error
            ], 500);
        }
    }

    /**
     * Get email templates
     */
    public function getEmailTemplates($type)
    {
        try {
            $template = DB::table('email_templates')
                ->where('type', $type)
                ->first();

            if (!$template) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email template not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $template
            ]);
        } catch (\Exception $e) {
            Log::error('getEmailTemplates error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching email template'
            ], 500);
        }
    }

    /**
     * Get notification content
     */
    public function getNotificationContent($type)
    {
        try {
            $notification = DB::table('dynamic_notification')
                ->where('type', $type)
                ->first();

            if (!$notification) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'id' => '',
                        'message' => 'Notification setup is pending',
                        'subject' => 'setup notification',
                        'type' => ''
                    ]
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $notification
            ]);
        } catch (\Exception $e) {
            Log::error('getNotificationContent error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching notification'
            ], 500);
        }
    }

    /**
     * Set booked order
     */
    public function setBookedOrder(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required|string'
            ]);

            // Sanitized mapping only allowed columns
            $data = [
                'id'             => $request->id,
                'date'           => $request->date,
                'occasion'       => $request->occasion,
                'guestLastName'  => $request->guestLastName,
                'discount'       => $request->discount,
                'vendorID'       => $request->vendorID,
                'authorID'       => $request->authorID,
                'guestEmail'     => $request->guestEmail,
                'totalGuest'     => $request->totalGuest,
                'guestFirstName' => $request->guestFirstName,
                'guestPhone'     => $request->guestPhone,
                'specialRequest' => $request->specialRequest,
                'discountType'   => $request->discountType,
                'status'         => $request->status,
                'firstVisit'     => $request->boolean('firstVisit') ? 1 : 0,
                'createdAt'      => now()->toDateTimeString(),
            ];

            // JSON fields must be string-encoded properly!
            if ($request->filled('vendor')) {
                $data['vendor'] = json_encode($request->vendor, JSON_UNESCAPED_UNICODE);
            }

            if ($request->filled('author')) {
                $data['author'] = json_encode($request->author, JSON_UNESCAPED_UNICODE);
            }

            DB::table('booked_table')->updateOrInsert(
                ['id' => $request->id],
                $data
            );

            return response()->json([
                'success' => true,
                'message' => 'Booking saved successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('setBookedOrder error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error saving booking',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Get story
     */
    public function getStory($vendorId)
    {
        try {
            $story = DB::table('story')
                ->where('vendor_id', $vendorId)
                ->first();

            if (!$story) {
                return response()->json([
                    'success' => false,
                    'message' => 'Story not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $story
            ]);
        } catch (\Exception $e) {
            Log::error('getStory error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching story'
            ], 500);
        }
    }

    /**
     * Add or update story
     */
    public function addOrUpdateStory(Request $request)
    {
        try {
            $request->validate([
                'vendor_id' => 'required|string'
            ]);

            $data = $request->all();

            DB::table('story')->updateOrInsert(
                ['vendor_id' => $data['vendor_id']],
                $data
            );

            return response()->json([
                'success' => true,
                'message' => 'Story saved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('addOrUpdateStory error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error saving story'
            ], 500);
        }
    }

    /**
     * Remove story
     */
    public function removeStory($vendorId)
    {
        try {
            DB::table('story')
                ->where('vendor_id', $vendorId)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Story deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('removeStory error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error deleting story'
            ], 500);
        }
    }

    /**
     * Get all subscription plans
     */
    public function getAllSubscriptionPlans()
    {
        try {
            $plans = DB::table('subscription_plans')
                ->where('isEnable', true)
                ->orderBy('place', 'asc')
                ->get()
                ->filter(function ($plan) {
                    // Filter out commission subscription if needed
                    return true;
                })
                ->values();


            $plans->plan_points = json_decode($plans->plan_points, true);
            $plans->features = json_decode($plans->features, true);
            $plans->createdAt = trim($plans->createdAt, '"');

            return response()->json([
                'success' => true,
                'data' => $plans
            ]);



        } catch (\Exception $e) {
            Log::error('getAllSubscriptionPlans error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching subscription plans'
            ], 500);
        }
    }

    /**
     * Get subscription plan by ID
     */
    public function getSubscriptionPlanById($planId)
    {
        try {
            $plan = DB::table('subscription_plans')
                ->where('id', $planId)
                ->first();

            if (!$plan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Subscription plan not found'
                ], 404);
            }
            $plan->plan_points = json_decode($plan->plan_points, true);
            $plan->features = json_decode($plan->features, true);
            $plan->createdAt = trim($plan->createdAt, '"');

            return response()->json([
                'success' => true,
                'data' => $plan
            ]);
        } catch (\Exception $e) {
            Log::error('getSubscriptionPlanById error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching subscription plan'
            ], 500);
        }
    }

    /**
     * Set subscription plan
     */
    public function setSubscriptionPlan(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'id' => 'nullable|string|max:255',
                'name' => 'required|string|max:255',
                'price' => 'nullable|string|max:255',
                'image' => 'nullable|string|max:255',
                'itemLimit' => 'nullable|string|max:255',
                'orderLimit' => 'nullable|string|max:255',
                'description' => 'nullable|string|max:255',
                'plan_points' => 'nullable|string|max:255',
                'type' => 'nullable|string|max:255',
                'isEnable' => 'nullable|boolean',
                'features' => 'nullable|string|max:255',
                'expiryDay' => 'nullable|string|max:255',
                'place' => 'nullable|string|max:255'
            ]);

            if (empty($validatedData['id'])) {
                $validatedData['id'] = Str::uuid()->toString();
                $validatedData['createdAt'] = now()->toDateTimeString();
            }

            DB::table('subscription_plans')->updateOrInsert(
                ['id' => $validatedData['id']],
                $validatedData
            );

            return response()->json([
                'success' => true,
                'message' => 'Subscription plan saved successfully',
                'data' => $validatedData
            ]);

        } catch (\Exception $e) {
            Log::error('setSubscriptionPlan error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() // 👈 show actual reason while testing
            ], 500);
        }
    }

    /**
     * Set subscription transaction
     */
    public function setSubscriptionTransaction(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'id' => 'nullable|string|max:255',
                'user_id' => 'required|string|max:255',
                'payment_type' => 'nullable|string|max:255',
                'expiry_date' => 'nullable|string',
                'subscription_plan' => 'nullable|string'
            ]);

            if (empty($validatedData['id'])) {
                $validatedData['id'] = Str::uuid()->toString();
                $validatedData['createdAt'] = now()->toDateTimeString();
            }

            DB::table('subscription_history')->updateOrInsert(
                ['id' => $validatedData['id']],
                $validatedData
            );

            return response()->json([
                'success' => true,
                'message' => 'Subscription transaction saved successfully',
                'data' => $validatedData
            ]);

        } catch (\Exception $e) {
            Log::error('setSubscriptionTransaction error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() // show actual error while testing
            ], 500);
        }
    }

    /**
     * Get subscription history
     */
    public function getSubscriptionHistory(Request $request)
    {
        try {
            $userId = $request->input('user_id') ?? $request->user()->firebase_id ?? null;

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User ID is required'
                ], 400);
            }

            $history = DB::table('subscription_history')
                ->where('user_id', $userId)
                ->orderByRaw("STR_TO_DATE(REPLACE(REPLACE(createdAt, '\"', ''), 'Z', ''), '%Y-%m-%dT%H:%i:%s.%f') DESC")
                ->get();

            return response()->json([
                'success' => true,
                'data' => $history
            ]);
        } catch (\Exception $e) {
            Log::error('getSubscriptionHistory error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching subscription history'
            ], 500);
        }
    }

    /**
     * Get available drivers
     */
       public function getAvalibleDrivers(Request $request)
    {
        try {
            $zoneId = $request->input('zoneId'); // optional

            $drivers = DB::table('users')
                ->where('role', 'driver')
                ->where('active', true)
                ->where('isActive', true)
                ->when(!is_null($zoneId), function ($query) use ($zoneId) {
                    $query->where('zoneId', $zoneId);
                })
                ->get();

            // Decode JSON fields
            $drivers = $drivers->map(function ($driver) {
                $driver->userBankDetails   = $driver->userBankDetails ? json_decode($driver->userBankDetails) : null;
                $driver->inProgressOrderID = $driver->inProgressOrderID ? json_decode($driver->inProgressOrderID) : [];
                $driver->orderRequestData  = $driver->orderRequestData ? json_decode($driver->orderRequestData) : [];
                $driver->location          = $driver->location ? json_decode($driver->location) : null;
                return $driver;
            });

            return response()->json([
                'success' => true,
                'data' => $drivers
            ]);

        } catch (\Exception $e) {
            Log::error('getAvalibleDrivers error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error fetching drivers'
            ], 500);
        }
    }

    /**
     * Get all drivers
     */
    public function getAllDrivers(Request $request)
    {
        try {
            $vendorId = $request->input('vendorID') ?? $request->user()->vendorID ?? null;

            if (!$vendorId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor ID is required'
                ], 400);
            }

            $drivers = DB::table('users')
                ->where('vendorID', $vendorId)
                ->where('role', 'driver')
                ->orderByRaw("STR_TO_DATE(REPLACE(REPLACE(createdAt, '\"', ''), 'Z', ''), '%Y-%m-%dT%H:%i:%s.%f') DESC")
                ->get();

            return response()->json([
                'success' => true,
                'data' => $drivers
            ]);
        } catch (\Exception $e) {
            Log::error('getAllDrivers error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching drivers'
            ], 500);
        }
    }

    /**
     * Update product availability
     */
    public function updateProductIsAvailable(Request $request, $productId)
    {
        try {
            $isAvailable = $request->input('isAvailable', false);

            // Get vendor ID before update for cache clearing
            $product = DB::table('vendor_products')
                ->where('id', $productId)
                ->first(['vendorID']);

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found'
                ], 404);
            }

            DB::table('vendor_products')
                ->where('id', $productId)
                ->update(['isAvailable' => $isAvailable]);

            // Clear product feed cache for this vendor
            try {
                $cleared = $this->clearProductFeedCacheForVendor($product->vendorID);
                Log::info('Cache invalidation triggered in updateProductIsAvailable', [
                    'vendor_id' => $product->vendorID,
                    'product_id' => $productId,
                    'cache_cleared' => $cleared,
                ]);
            } catch (\Exception $cacheError) {
                Log::error('Failed to clear cache in updateProductIsAvailable', [
                    'vendor_id' => $product->vendorID,
                    'product_id' => $productId,
                    'error' => $cacheError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Product availability updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('updateProductIsAvailable error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating product availability'
            ], 500);
        }
    }

    /**
     * Update category is active
     */
    public function updateCategoryIsActive(Request $request, $categoryId)
    {
        try {
            $isActive = $request->input('isActive', false);

            DB::table('vendor_categories')
                ->where('id', $categoryId)
                ->update(['isActive' => $isActive]);

            return response()->json([
                'success' => true,
                'message' => 'Category status updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('updateCategoryIsActive error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating category status'
            ], 500);
        }
    }

    /**
     * Set all products availability for category
     */
    public function setAllProductsAvailabilityForCategory(Request $request, $categoryId)
    {
        try {
            $isAvailable = $request->input('isAvailable', false);
            $vendorId = $request->input('vendorID') ?? $request->user()->vendorID ?? null;

            if (!$vendorId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor ID is required'
                ], 400);
            }

            DB::table('vendor_products')
                ->where('vendorID', $vendorId)
                ->where('categoryID', $categoryId)
                ->update(['isAvailable' => $isAvailable]);

            // Clear product feed cache for this vendor
            try {
                $cleared = $this->clearProductFeedCacheForVendor($vendorId);
                Log::info('Cache invalidation triggered in setAllProductsAvailabilityForCategory', [
                    'vendor_id' => $vendorId,
                    'category_id' => $categoryId,
                    'cache_cleared' => $cleared,
                ]);
            } catch (\Exception $cacheError) {
                Log::error('Failed to clear cache in setAllProductsAvailabilityForCategory', [
                    'vendor_id' => $vendorId,
                    'category_id' => $categoryId,
                    'error' => $cacheError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Products availability updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('setAllProductsAvailabilityForCategory error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating products availability'
            ], 500);
        }
    }

    /**
     * Generate a unique cache key for product feed based on vendor and filters
     * Matches the pattern used in ProductController::generateProductFeedCacheKey()
     *
     * @param string $vendorId
     * @param array $filters
     * @return string
     */
    protected function generateProductFeedCacheKey(string $vendorId, array $filters): string
    {
        // Normalize filters for consistent cache keys (same as ProductController)
        $filterHash = md5(json_encode([
            'search' => $filters['search'] ?? null,
            'is_veg' => $filters['is_veg'] ?? null,
            'is_nonveg' => $filters['is_nonveg'] ?? null,
            'offer_only' => $filters['offer_only'] ?? null,
        ]));

        return "product_feed_vendor_{$vendorId}_filters_{$filterHash}";
    }

    /**
     * Clear all product feed cache entries for a specific vendor
     * This invalidates all cache entries matching the pattern: product_feed_vendor_{vendorId}_filters_*
     * Matches the implementation from ProductController::clearProductFeedCache()
     *
     * @param string $vendorId
     * @return bool
     */
    protected function clearProductFeedCacheForVendor(string $vendorId): bool
    {
        try {
            $cacheDriver = config('cache.default');
            $cleared = 0;
            $cachePrefix = config('cache.prefix', '');
            $pattern = "product_feed_vendor_{$vendorId}_%";

            if ($cacheDriver === 'database') {
                // For database cache, delete all entries matching the pattern
                // Laravel stores keys exactly as provided (Cache facade handles prefix internally)
                try {
                    // Try both with and without prefix to be safe
                    $patterns = [$pattern];
                    if (!empty($cachePrefix)) {
                        $patterns[] = $cachePrefix . ':' . $pattern;
                        $patterns[] = $cachePrefix . '_' . $pattern;
                    }

                    $deleted = DB::table('cache')
                        ->where(function ($q) use ($patterns) {
                            foreach ($patterns as $index => $pat) {
                                if ($index === 0) {
                                    $q->where('key', 'like', $pat);
                                } else {
                                    $q->orWhere('key', 'like', $pat);
                                }
                            }
                        })
                        ->delete();

                    $cleared = $deleted;

                    Log::info('Product feed cache cleared for vendor (database)', [
                        'vendor_id' => $vendorId,
                        'keys_cleared' => $cleared,
                        'patterns_tried' => $patterns,
                        'prefix' => $cachePrefix,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to clear vendor cache from database', [
                        'vendor_id' => $vendorId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            } elseif ($cacheDriver === 'file') {
                // For file cache, iterate through cache files
                try {
                    $cachePath = storage_path('framework/cache/data');
                    if (is_dir($cachePath)) {
                        $files = glob($cachePath . '/*');
                        foreach ($files as $file) {
                            if (is_file($file)) {
                                $content = file_get_contents($file);
                                // Check if file contains the vendor cache key pattern
                                if (strpos($content, "product_feed_vendor_{$vendorId}_") !== false) {
                                    unlink($file);
                                    $cleared++;
                                }
                            }
                        }
                    }
                    Log::info('Product feed cache cleared for vendor (file)', [
                        'vendor_id' => $vendorId,
                        'keys_cleared' => $cleared,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to clear vendor cache from files', [
                        'vendor_id' => $vendorId,
                        'error' => $e->getMessage(),
                    ]);
                }
            } elseif ($cacheDriver === 'redis') {
                // For Redis, try to use SCAN to find all matching keys
                try {
                    $redis = Cache::getStore()->getRedis();
                    $connection = Cache::getStore()->connection();

                    // Get the prefix that Laravel uses (Cache facade handles this automatically)
                    $prefix = $cachePrefix ? $cachePrefix . ':' : '';
                    $searchPattern = $prefix . "product_feed_vendor_{$vendorId}_*";

                    // Use SCAN to find all matching keys (more efficient than KEYS)
                    $cursor = 0;
                    $allKeys = [];

                    do {
                        // SCAN returns [cursor, [keys...]]
                        $result = $redis->scan($cursor, ['match' => $searchPattern, 'count' => 100]);
                        if (is_array($result) && count($result) >= 2) {
                            $cursor = $result[0];
                            $keys = $result[1];
                            if (!empty($keys)) {
                                $allKeys = array_merge($allKeys, $keys);
                            }
                        } else {
                            break;
                        }
                    } while ($cursor > 0);

                    // Delete all found keys using Cache::forget (handles prefix automatically)
                    foreach ($allKeys as $fullKey) {
                        // Extract the actual cache key (remove prefix if present)
                        $cacheKey = $prefix ? str_replace($prefix, '', $fullKey) : $fullKey;
                        if (Cache::forget($cacheKey)) {
                            $cleared++;
                        }
                    }

                    // If SCAN didn't find keys, fall back to common filters
                    if ($cleared === 0) {
                        throw new \Exception('No keys found via SCAN, using fallback');
                    }

                    Log::info('Product feed cache cleared for vendor (redis)', [
                        'vendor_id' => $vendorId,
                        'keys_cleared' => $cleared,
                        'pattern' => $searchPattern,
                        'keys_found' => count($allKeys),
                    ]);
                } catch (\Exception $e) {
                    // Fallback: Clear common filter combinations if SCAN fails or finds nothing
                    Log::warning('Redis SCAN failed or found no keys, using fallback method', [
                        'vendor_id' => $vendorId,
                        'error' => $e->getMessage(),
                    ]);

                    // Generate comprehensive filter combinations
                    $commonFilters = [
                        ['search' => null, 'is_veg' => null, 'is_nonveg' => null, 'offer_only' => null],
                        ['search' => null, 'is_veg' => true, 'is_nonveg' => null, 'offer_only' => null],
                        ['search' => null, 'is_veg' => null, 'is_nonveg' => true, 'offer_only' => null],
                        ['search' => null, 'is_veg' => null, 'is_nonveg' => null, 'offer_only' => true],
                        ['search' => null, 'is_veg' => true, 'is_nonveg' => null, 'offer_only' => true],
                        ['search' => null, 'is_veg' => null, 'is_nonveg' => true, 'offer_only' => true],
                        ['search' => null, 'is_veg' => true, 'is_nonveg' => true, 'offer_only' => null],
                        ['search' => null, 'is_veg' => true, 'is_nonveg' => true, 'offer_only' => true],
                    ];

                    foreach ($commonFilters as $filterSet) {
                        $cacheKey = $this->generateProductFeedCacheKey($vendorId, $filterSet);
                        if (Cache::forget($cacheKey)) {
                            $cleared++;
                        }
                    }

                    Log::info('Product feed cache cleared for vendor (redis fallback)', [
                        'vendor_id' => $vendorId,
                        'keys_cleared' => $cleared,
                    ]);
                }
            } else {
                // For Memcached or other drivers, clear common filter combinations
                $commonFilters = [
                    ['search' => null, 'is_veg' => null, 'is_nonveg' => null, 'offer_only' => null],
                    ['search' => null, 'is_veg' => true, 'is_nonveg' => null, 'offer_only' => null],
                    ['search' => null, 'is_veg' => null, 'is_nonveg' => true, 'offer_only' => null],
                    ['search' => null, 'is_veg' => null, 'is_nonveg' => null, 'offer_only' => true],
                    ['search' => null, 'is_veg' => true, 'is_nonveg' => null, 'offer_only' => true],
                    ['search' => null, 'is_veg' => null, 'is_nonveg' => true, 'offer_only' => true],
                ];

                foreach ($commonFilters as $filterSet) {
                    $cacheKey = $this->generateProductFeedCacheKey($vendorId, $filterSet);
                    if (Cache::forget($cacheKey)) {
                        $cleared++;
                    }
                }

                Log::info('Product feed cache cleared for vendor (common filters)', [
                    'vendor_id' => $vendorId,
                    'keys_cleared' => $cleared,
                    'driver' => $cacheDriver,
                    'note' => 'Cleared common filter combinations. Use ?refresh=true in API call for immediate refresh',
                ]);
            }

            return $cleared > 0;
        } catch (\Throwable $e) {
            Log::error('Error clearing product feed cache', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

}

