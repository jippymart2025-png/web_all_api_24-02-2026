<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class UserProfileController extends Controller
{
    /**
     * Get User Profile
     * GET /api/users/profile/{firebase_id}
     *
     * Purpose: Get complete customer profile with all details
     *
     * Path Parameters:
     * - firebase_id (required): User's firebase_id
     *
     * Note: Only returns customers (role = 'customer')
     */
    public function show($firebase_id)
    {
        try {
            // Validate firebase_id is not empty
            if (empty($firebase_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Firebase ID is required'
                ], 400);
            }
            Log::info('getUserProfile: Fetching customer with firebase_id: ' . $firebase_id);
            // Find customer by firebase_id only
            $user = User::where('firebase_id', $firebase_id)
                ->first();

            if (!$user) {
                Log::info('getUserProfile: Customer not found for firebase_id: ' . $firebase_id);
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found'
                ], 404);
            }

            Log::info('getUserProfile: Customer found, ID: ' . $user->id);

            // Get subscription plan if exists
            $subscriptionPlan = $this->getSubscriptionPlan($user);
            // Format response
            $data = $this->formatUserProfile($user, $subscriptionPlan);
            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            Log::error('Get User Profile Error: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user profile',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }


    /**
     * Get Authenticated User Profile
     * GET /api/user/profile
     *
     * Purpose: Get profile of currently authenticated customer
     * Requires: auth:sanctum middleware
     *
     * Note: Only returns customers (role = 'customer')
     */
    public function me(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Ensure user is a customer
            if ($user->role !== 'customer') {
                return response()->json([
                    'success' => false,
                    'message' => 'This endpoint is only for customers'
                ], 403);
            }

            // Get subscription plan if exists
            $subscriptionPlan = $this->getSubscriptionPlan($user);
            // Format response
            $data = $this->formatUserProfile($user, $subscriptionPlan);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            Log::error('Get Current User Profile Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user profile',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update User Profile
     * PUT /api/user/profile
     */
    public function update(Request $request)
    {
        try {
            // ✅ Get user by firebase_id (if sent) OR by token
            $user = null;

            if ($request->has('firebase_id')) {
                $user = User::where('firebase_id', $request->input('firebase_id'))->first();
            } else {
                $user = $request->user();
            }

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found or not authenticated',
                ], 404);
            }

            // ✅ Validate base fields
            $validator = Validator::make($request->all(), [
                'firstName' => 'nullable|string|max:100',
                'lastName' => 'nullable|string|max:100',
                'email' => 'nullable|email|max:255',
                'countryCode' => 'nullable|string|max:10',
                'profilePictureURL' => 'nullable|image|max:4096',
                'fcmToken' => 'nullable|string',
                'shippingAddress' => 'nullable', // ✅ can't validate as array directly (could be JSON string)
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $updateData = [];

            // ✅ Handle profile picture upload
            if ($request->hasFile('profilePictureURL')) {
                $file = $request->file('profilePictureURL');
                $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();
                $file->storeAs('public/users', $fileName);
                $updateData['profilePictureURL'] = url('storage/users/' . $fileName);
            }

            // ✅ Handle basic fields
            foreach (['firstName', 'lastName', 'email', 'fcmToken', 'countryCode', 'zoneId'] as $field) {
                if ($request->has($field)) {
                    $updateData[$field] = $request->input($field);
                }
            }

            // ✅ Handle shipping address update
            if ($request->has('shippingAddress')) {
                $addresses = $request->input('shippingAddress');

                // ✅ Decode JSON string if necessary
                if (is_string($addresses)) {
                    $decoded = json_decode($addresses, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $addresses = $decoded;
                    } else {
                        return response()->json([
                            'success' => false,
                            'message' => 'Invalid JSON format for shippingAddress'
                        ], 400);
                    }
                }

                // ✅ Convert single address object to array
                if (!empty($addresses) && !is_array(reset($addresses))) {
                    $addresses = [$addresses];
                }

                // ✅ Decode existing addresses from DB
                $existingAddresses = json_decode($user->shippingAddress ?? '[]', true);
                if (!is_array($existingAddresses)) {
                    $existingAddresses = [];
                }

                foreach ($addresses as $newAddress) {
                    if (!is_array($newAddress)) {
                        continue;
                    }

                    // Auto-generate ID if missing
                    if (empty($newAddress['id'])) {
                        $newAddress['id'] = 'addr_' . Str::random(8);
                    }

                    // If isDefault = 1, reset others to 0
                    if (isset($newAddress['isDefault']) && $newAddress['isDefault'] == 1) {
                        foreach ($existingAddresses as &$addr) {
                            $addr['isDefault'] = 0;
                        }
                    }

                    // Check if existing address matches ID, then update
                    $found = false;
                    foreach ($existingAddresses as &$addr) {
                        if (isset($addr['id']) && $addr['id'] === $newAddress['id']) {
                            $addr = array_merge($addr, $newAddress);
                            $found = true;
                            break;
                        }
                    }

                    // If not found, append new one
                    if (!$found) {
                        $existingAddresses[] = $newAddress;
                    }
                }

                // ✅ Save updated JSON
                $updateData['shippingAddress'] = json_encode($existingAddresses);
            }

            // ✅ Save user
            if (!empty($updateData)) {
                $user->update($updateData);
                $user->refresh();
            }

            // ✅ Fetch subscription if any
            $subscriptionPlan = $this->getSubscriptionPlan($user);

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $this->formatUserProfile($user, $subscriptionPlan),
            ]);
        } catch (\Exception $e) {
            Log::error('Update User Profile Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update user profile',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }


    /**
     * Format user profile for API response
     */
    private function formatUserProfile($user, $subscriptionPlan = null)
    {
        // Parse shipping addresses
        $shippingAddress = $this->parseShippingAddress($user->shippingAddress ?? null);

        // Parse location
        $location = $this->parseLocation($user->location ?? null);

        // Parse user bank details
        $userBankDetails = $this->parseBankDetails($user->userBankDetails ?? null);

        // Parse in-progress orders
        $inProgressOrderID = $this->parseJsonArray($user->inProgressOrderID ?? null);
        $orderRequestData = $this->parseJsonArray($user->orderRequestData ?? null);

        // Ensure wallet_amount is a number
        $walletAmount = 0;
        if (isset($user->wallet_amount)) {
            if (is_numeric($user->wallet_amount)) {
                $walletAmount = (int) $user->wallet_amount;
            } elseif (is_string($user->wallet_amount)) {
                $walletAmount = (int) ($user->wallet_amount ?? 0);
            }
        }

        return [
            'id' => $user->id ?? $user->firebase_id ?? $user->_id,
            'firstName' => $user->firstName ?? '',
            'lastName' => $user->lastName ?? '',
            'email' => $user->email ?? '',
            'profilePictureURL' => $user->profilePictureURL ?? $user->profile_picture_url ?? '',
            'fcmToken' => $user->fcmToken ?? $user->fcm_token ?? '',
            'countryCode' => $user->countryCode ?? $user->country_code ?? '',
            'phoneNumber' => $user->phoneNumber ?? $user->phone_number ?? '',
            'wallet_amount' => $walletAmount,
            'active' => (bool) ($user->active ?? false),
            'isActive' => (bool) ($user->isActive ?? false),
            'isDocumentVerify' => (bool) ($user->isDocumentVerify ?? $user->is_document_verify ?? false),
            'createdAt' => $user->createdAt ?? $user->created_at ?? $user->_created_at ?? null,
            'role' => $user->role ?? 'customer',
            'location' => $location,
            'userBankDetails' => $userBankDetails,
            'shippingAddress' => $shippingAddress,
            'carName' => $user->carName ?? $user->car_name ?? null,
            'carNumber' => $user->carNumber ?? $user->car_number ?? null,
            'carPictureURL' => $user->carPictureURL ?? $user->car_picture_url ?? null,
            'inProgressOrderID' => $inProgressOrderID,
            'orderRequestData' => $orderRequestData,
            'vendorID' => $user->vendorID ?? $user->vendor_id ?? null,
            'zoneId' => $user->zoneId ?? $user->zone_id ?? null,
            'rotation' => $user->rotation ?? 0,
            'appIdentifier' => $user->appIdentifier ?? $user->app_identifier ?? 'android',
            'provider' => $user->provider ?? 'email',
            'subscriptionPlanId' => $user->subscriptionPlanId ?? $user->subscription_plan_id ?? null,
            'subscriptionExpiryDate' => $user->subscriptionExpiryDate ?? $user->subscription_expiry_date ?? null,
            'subscriptionPlan' => $subscriptionPlan,
        ];
    }

    /**
     * Parse shipping address from JSON or array
     */
private function parseShippingAddress($shippingAddress)
{
    if (empty($shippingAddress)) {
        return [];
    }

    // Decode JSON string if needed
    if (is_string($shippingAddress)) {
        $decoded = json_decode($shippingAddress, true);
        if (is_array($decoded)) {
            $shippingAddress = $decoded;
        } else {
            return [];
        }
    }

    if (!is_array($shippingAddress)) {
        return [];
    }

    // Wrap single address into array
    if (isset($shippingAddress['address']) || isset($shippingAddress['locality'])) {
        $addresses = [$shippingAddress];
    } else {
        $addresses = $shippingAddress;
    }

    // Format each address
    return array_map(function($addr) {
        if (!is_array($addr)) {
            return null;
        }

        return [
            'id' => $addr['id'] ?? null,
            'address' => $addr['address'] ?? '',
            'addressAs' => $addr['addressAs'] ?? '',
            'landmark' => $addr['landmark'] ?? '',
            'locality' => $addr['locality'] ?? '',
            'latitude' => $addr['latitude'] ?? '',
            'longitude' => $addr['longitude'] ?? '',
            // Use parseLocation() to return location AS-IS
            'location' => isset($addr['location']) ? $this->parseLocation($addr['location']) : null,
            'isDefault' => (bool) ($addr['isDefault'] ?? false),
            'zoneId' => $addr['zoneId'] ?? null,
        ];
    }, array_filter($addresses));
}


    /**
     * Parse location from JSON
     */
private function parseLocation($location)
{
    // If it's a JSON string, decode it
    if (is_string($location)) {
        $location = json_decode($location, true);
    }

    // If not array, return as-is
    if (!is_array($location)) {
        return $location;
    }

    // Return EXACTLY as stored (no float conversion)
    return $location;
}


    /**
     * Parse bank details from JSON
     */
    private function parseBankDetails($bankDetails)
    {
        if (empty($bankDetails)) {
            return null;
        }

        // If it's a string, decode it
        if (is_string($bankDetails)) {
            try {
                $bankDetails = json_decode($bankDetails, true);
            } catch (\Exception $e) {
                return null;
            }
        }

        if (!is_array($bankDetails)) {
            return null;
        }

        return [
            'bankName' => $bankDetails['bankName'] ?? '',
            'branchName' => $bankDetails['branchName'] ?? '',
            'holderName' => $bankDetails['holderName'] ?? '',
            'accountNumber' => $bankDetails['accountNumber'] ?? '',
            'otherDetails' => $bankDetails['otherDetails'] ?? '',
        ];
    }

    /**
     * Parse JSON array field
     */
    private function parseJsonArray($field)
    {
        if (empty($field)) {
            return [];
        }

        if (is_string($field)) {
            try {
                $decoded = json_decode($field, true);
                return is_array($decoded) ? $decoded : [];
            } catch (\Exception $e) {
                return [];
            }
        }

        return is_array($field) ? $field : [];
    }

    /**
     * Get subscription plan for user
     */
    private function getSubscriptionPlan($user)
    {
        if (empty($user->subscriptionPlanId) && empty($user->subscription_plan_id)) {
            return null;
        }

        try {
            $planId = $user->subscriptionPlanId ?? $user->subscription_plan_id;

            // Check if subscription_plans table exists
            if (!DB::getSchemaBuilder()->hasTable('subscription_plans')) {
                return null;
            }

            $plan = DB::table('subscription_plans')
                ->where('id', $planId)
                ->first();

            if (!$plan) {
                return null;
            }

            return [
                'id' => $plan->id ?? null,
                'name' => $plan->name ?? '',
                'price' => $plan->price ?? '0',
                'expiryDay' => $plan->expiryDay ?? $plan->expiry_day ?? null,
                'type' => $plan->type ?? 'free',
                'description' => $plan->description ?? '',
                'itemLimit' => $plan->itemLimit ?? $plan->item_limit ?? '-1',
                'orderLimit' => $plan->orderLimit ?? $plan->order_limit ?? '-1',
            ];

        } catch (\Exception $e) {
            Log::error('Error fetching subscription plan: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete user and related data from database safely.
     *
     * @param string $firebaseId
     * @return \Illuminate\Http\JsonResponse
     */
public function destroy(string $firebaseId)
{
    try {
        $user = User::where('firebase_id', $firebaseId)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        DB::transaction(function () use ($user, $firebaseId) {

            $userId = $user->id;

            // ================= restaurant_orders =================
            // if (Schema::hasTable('restaurant_orders')) {

            //     if (Schema::hasColumn('restaurant_orders', 'user_id')) {
            //         DB::table('restaurant_orders')
            //             ->where('user_id', $userId)
            //             ->delete();
            //     }

            //     if (Schema::hasColumn('restaurant_orders', 'firebase_id')) {
            //         DB::table('restaurant_orders')
            //             ->where('firebase_id', $firebaseId)
            //             ->delete();
            //     }

            //     if (Schema::hasColumn('restaurant_orders', 'authorID')) {
            //         DB::table('restaurant_orders')
            //             ->where('authorID', $firebaseId)
            //             ->delete();
            //     }
            // }
            // ================= favorite_restaurant =================
            if (Schema::hasTable('favorite_restaurant')) {


                if (Schema::hasColumn('favorite_restaurant', 'user_id')) {
                    DB::table('favorite_restaurant')
                        ->where('user_id', $firebaseId)
                        ->delete();
                }
            }

            // ================= favorite_item =================
            if (Schema::hasTable('favorite_item')) {


                if (Schema::hasColumn('favorite_item', 'user_id')) {
                    DB::table('favorite_item')
                        ->where('user_id', $firebaseId)
                        ->delete();
                }
            }

            // ================= delete user =================
            $user->delete();
        });

        return response()->json([
            'message' => 'User profile deleted successfully'
        ]);

    } catch (\Throwable $e) {

        Log::error('Failed to delete user profile', [
            'firebase_id' => $firebaseId,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'error'   => 'Failed to delete user profile',
            'message' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}
}
