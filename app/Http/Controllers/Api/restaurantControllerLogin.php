<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\restaurant_orders;
use App\Models\restaurants_orders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;



class restaurantControllerLogin extends Controller
{
public function restaurantLogin(Request $request)
{
    $request->validate([
        "email" => "required|email",
        "password" => "required"
    ]);

    // Check if user exists
    $user = User::where("email", $request->email)
    ->where("role", "vendor")
    ->first();
    if (!$user) {
        return response()->json([
            "success" => false,
            "message" => "No user found for that email."
        ], 404);
    }

    // Allow only vendor login
    if ($user->role !== "vendor") {
        return response()->json([
            "success" => false,
            "message" => "This user is not created in vendor application."
        ], 403);
    }

    // Check active status - KEEP integer comparison since field isn't cast yet
    if ((int)$user->active !== 1) {
        return response()->json([
            "success" => false,
            "message" => "This user is disabled, please contact administrator."
        ], 403);
    }

    // Validate password
    if (!Hash::check($request->password, $user->password)) {
        return response()->json([
            "success" => false,
            "message" => "Wrong password provided for that user."
        ], 401);
    }

    // Update FCM token
    if ($request->has('fcmToken')) {
        $user->update([
            'fcmToken' => $request->fcmToken
        ]);
    }

    /**
     * 👉 JSON Decoding Fields
     */
    $user->shippingAddress       = !empty($user->shippingAddress)       ? json_decode($user->shippingAddress)       : null;
    $user->userBankDetails       = !empty($user->userBankDetails)       ? json_decode($user->userBankDetails)       : null;
    $user->subscriptionExpiryDate = !empty($user->subscriptionExpiryDate) ? json_decode($user->subscriptionExpiryDate) : null;

    // Ensure active field is returned as boolean for Flutter
    $user->active = (bool)$user->active;

    // Also fix isActive field if it exists
    if (isset($user->isActive)) {
        $user->isActive = (bool)$user->isActive;
    }

    return response()->json([
        "success" => true,
        "message" => "Login successful",
        "data" => $user
    ], 200);
}


public function restaurantSignup(Request $request): \Illuminate\Http\JsonResponse
{
    // Common validation
    $request->validate([
        "type" => "required|in:email",
        "first_name" => "required|string",
        "last_name" => "required|string",
        "zone_id" => "required|string",
        "app_identifier" => "required|in:android,ios",
    ]);

    // Auto approval settings
    $autoApprove = false;
    $isDocumentVerify = false;

    /*
    |--------------------------------------------------------------------------
    | EMAIL SIGNUP
    |--------------------------------------------------------------------------
    */
    if ($request->type === "email") {

        $request->validate([
            "email" => "required|email",
            "password" => "required|min:6",
        ]);

        // Normalize email
        $email = strtolower($request->email);

        // 🔴 Check if vendor already exists with same email
        $vendorExists = User::where('email', $email)
            ->where('role', 'vendor')
            ->exists();

        if ($vendorExists) {
            return response()->json([
                "success" => false,
                "message" => "Vendor with this email already exists."
            ], 409);
        }

        // Generate Firebase ID
        $firebaseId = $this->generateFirebaseId();

        // Create Vendor (Restaurant)
        $user = User::create([
            "firebase_id" => $firebaseId,
            "firstName" => $request->first_name,
            "lastName" => $request->last_name,
            "email" => $email,
            "phoneNumber" => $request->phone_number ?? null,
            "countryCode" => $request->country_code ?? null,
            "password" => Hash::make($request->password),
            "role" => "vendor",
            "vType" => "restaurant",
            "fcmToken" => $request->fcm_token ?? null,
            "isActive" => $autoApprove ? 1 : 0,
            "isDocumentVerify" => $isDocumentVerify ? 1 : 0,
            "zoneId" => $request->zone_id,
            "provider" => "email",
            "appIdentifier" => $request->app_identifier,
            "createdAt" => Carbon::now(),
        ]);

        return response()->json([
            "success" => true,
            "auto_approve" => $autoApprove,
            "message" => $autoApprove
                ? "Account created successfully."
                : "Your signup is under approval.",
            "data" => $user,
        ], 201);
    }

    return response()->json([
        "success" => false,
        "message" => "Invalid signup type.",
    ], 400);
}

    // --- FIREBASE ID GENERATOR ---
    private function generateFirebaseId($length = 20)
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $max = strlen($characters) - 1;

        do {
            $id = '';

            for ($i = 0; $i < $length; $i++) {
                $id .= $characters[random_int(0, $max)];
            }

            // Check if this ID exists in users table
            $exists = \DB::table('users')->where('firebase_id', $id)->exists();

        } while ($exists);

        return $id;
    }


    public function checkUserExists($uid)
    {
        try {
            $exists = \DB::table('users')->where('firebase_id', $uid)->exists();

            return response()->json([
                'success' => true,
                'exists' => $exists
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteUserById(Request $request)
    {
        try {
            $request->validate([
                'user_id' => 'required|string'
            ]);

            $userId = $request->input('user_id');

            $user = DB::table('users')->where('firebase_id', $userId)->first();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $vendorId = $user->vendorID ?? null;

            if ($vendorId) {

                // Delete coupons related to vendor
                DB::table('coupons')->where('resturant_id', $vendorId)->delete();

                // Delete food reviews related to vendor
                DB::table('foods_review')->where('VendorId', $vendorId)->delete();

                // Get vendor products
                $vendorProducts = DB::table('vendor_products')
                    ->where('vendorID', $vendorId)
                    ->get();

                foreach ($vendorProducts as $product) {

                    // Delete favourite items for each product
                    DB::table('favorite_item')
                        ->where('product_id', $product->id)
                        ->delete();
                }

                // Delete vendor products
                DB::table('vendor_products')->where('vendorID', $vendorId)->delete();

                // Delete vendor
                DB::table('vendors')->where('id', $vendorId)->delete();
            }

            // Delete user
            DB::table('users')->where('firebase_id', $userId)->delete();

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            Log::error("Delete user error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong!',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    public function getVendorOrders($vendorId)
    {
        try {
            $orders = restaurants_orders::where('vendorID', $vendorId)
                ->orderBy('createdAt', 'DESC')
                ->get()
                ->map(function ($order) {

                    // Fetch vendor details
                    $vendor = DB::table('vendors')->where('id', $order->vendorID)->first();


                    // Date render - Format like "Oct 1, 2025 11:27 PM"
                    // Date render - Format like "Oct 1, 2025 11:27 PM"
                    $dateText = '';

                    if (!empty($order->createdAt)) {
                        try {
                            // Parse ISO 8601 string (e.g., "2025-10-14T14:53:43.860219Z")
                            $date = \Carbon\Carbon::parse($order->createdAt)
                                ->setTimezone('Asia/Kolkata');

                            $dateText = $date->format('M j, Y g:i A'); // Output Example: Oct 1, 2025 11:27 PM

                        } catch (\Throwable $e) {
                            \Log::warning('⚠️ Date parsing failed:', [
                                'date' => $order->createdAt,
                                'error' => $e->getMessage()
                            ]);

                            $dateText = (string) $order->createdAt; // fallback
                        }
                    }


                    $vendorData = $vendor ? [
                        "id" => $vendor->id,
                        "title" => $vendor->title,
                        "phonenumber" => $vendor->phonenumber,
//                        "email" => $vendor->email ?? null,
                        "description" => $vendor->description,
                        "address" => $vendor->location,
                        "categoryID" => $vendor->categoryID,
                        "categoryTitle" => $vendor->categoryTitle,
                        "zoneId" => $vendor->zoneId,
                        "zone_slug" => $vendor->zone_slug,
                        "cuisineID" => $vendor->cuisineID,
                        "cuisineTitle" => $vendor->cuisineTitle,
                        "longitude" => $vendor->longitude,
                        "latitude" => $vendor->latitude,
                        "walletAmount" => (float) $vendor->walletAmount,
                        "restaurantCost" => $vendor->restaurantCost,
                        "author" => $vendor->author,
                        "isOpen" => (bool) $vendor->isOpen,
                        "reststatus" => (bool) $vendor->reststatus,
                        "publish" => (bool) $vendor->publish,
                        "isSelfDelivery" => (bool) $vendor->isSelfDelivery,
                        "specialDiscountEnable" => (bool) $vendor->specialDiscountEnable,
                        "workingHours" => json_decode($vendor->workingHours, true),
                        "photos" => json_decode($vendor->photos, true),
                        "categoryPhoto" => $vendor->categoryPhoto,
                        "restaurantMenuPhotos" => $vendor->restaurantMenuPhotos,
                        "reviewsCount" => $vendor->reviewsCount,
                        "reviewsSum" => $vendor->reviewsSum,
                        "subscriptionPlanId" => $vendor->subscriptionPlanId,
                        "subscriptionTotalOrders" => $vendor->subscriptionTotalOrders,
                        "subscription_plan" => $vendor->subscription_plan,
                        "subscriptionExpiryDate" => $vendor->subscriptionExpiryDate,
                    ] : null;

                    return [
                        'id'                => $order->id,
                        'vendorID'          => $order->vendorID,
                        'vendor'            => $vendorData,
                        'authorID'          => $order->authorID,
                        'driverID'          => $order->driverID,
                        'status'            => $order->status,
                        'payment_method'    => $order->payment_method,
                        'couponCode'        => $order->couponCode,
                        'deliveryCharge'    => (float)$order->deliveryCharge,
                        'discount'          => (float)$order->discount,
                        'tip_amount'        => (float)$order->tip_amount,
                        'ToPay'             => (float)$order->ToPay,
                        'toPayAmount'       => (float)$order->toPayAmount,
                        'adminCommission'   => (float)$order->adminCommission,
                        'adminCommissionType' => $order->adminCommissionType,
                        'specialDiscount'   => is_string($order->specialDiscount) ? json_decode($order->specialDiscount, true) : $order->specialDiscount,
                        'products'          => is_string($order->products) ? json_decode($order->products, true) : $order->products,
                        'author'            => is_string($order->author) ? json_decode($order->author, true) : $order->author,
                        'address'           => is_string($order->address) ? json_decode($order->address, true) : $order->address,
                        'rejectedByDrivers' => $order->rejectedByDrivers,
                        'scheduleTime'      => $order->scheduleTime,
                        'triggerDelivery'   => $order->triggerDelivery,
                        'notes'             => $order->notes,
                        'createdAt' => $dateText,
                    // ⬅ EXACT OUTPUT
                    ];
                });

            return response()->json(["success" => true, "data" => $orders]);

        } catch (\Throwable $e) {
            return response()->json([
                "success" => false,
                "message" => "Error fetching orders: " . $e->getMessage()
            ], 500);
        }
    }



}
