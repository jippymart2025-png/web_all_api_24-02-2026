<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class restaurantUserController extends Controller
{
    public function getUserProfile(string $firebase_id): JsonResponse
    {
        try {
            $user = User::where('firebase_id', $firebase_id)->first(); // or 'uuid' column if separate

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }
            $locationString = $user->location;
            $location = json_decode($locationString, true);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->firebase_id,
                    'firstName' => $user->firstName,
                    "lastName" => $user->lastName,
                    'email' => $user->email,
                    'phone' => $user->phoneNumber ?? null,
                    'shippingAddress' => $user->shippingAddress ? json_decode($user->shippingAddress, true) : null,
                    'appIdentifier' => $user->appIdentifier,
                    'vendorID' => $user->vendorID,
                    'isDocumentVerify' => $user->isDocumentVerify,
                    'profile_pic' => $user->profilePictureURL ?? null,
                    "countryCode" => $user->countryCode ?? null,
                    "role" => $user->role ?? null,
                    "active" => $user->active ?? null,
                    "vType" => $user->vType ?? null,
                    "zoneId" => $user->zoneId ?? null,
                    "wallet_amount" => $user->wallet_amount ?? null,
                    "isActive" => $user->isActive ?? null,

                    // ↓ JSON DECODE APPLIED
                    "userBankDetails" => $user->userBankDetails ? json_decode($user->userBankDetails, true) : null,

                    "photos" => $user->photos ?? null,
                    'location' => $location,
                    '_created_at' => $user->_created_at ?? null,
                    '_updated_at' => $user->_updated_at ?? null,
                    'orderCompleted' => $user->orderCompleted ?? null,
                    'orderRequestData' => $user->orderRequestData ?? null,
                    'subscriptionPlanId' => $user->subscriptionPlanId ?? null,
                    'subscription_plan' => $user->subscriptionPlan ?? null,
                    'subscriptionExpiryDate' => $user->subscriptionExpiryDate ?? null,
                    'fcmToken' => $user->fcmToken ?? null,
                    'inProgressOrderID' => $user->inProgressOrderID ?? null,
                    'carName' => $user->carName ?? null,
                    'carNumber' => $user->carNumber ?? null,
                    'carPictureURL' => $user->carPictureURL ?? null,
                ]

            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user profile: ' . $e->getMessage(),
            ], 500);
        }
    }





    public function updateUser(Request $request)
    {
        $request->validate([
            'id' => 'required|string' // firebase_id
        ]);

        try {
            $user = User::where('firebase_id', $request->id)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $updateData = [
                "firstName" => $request->firstName ?? $user->firstName,
                "lastName" => $request->lastName ?? $user->lastName,
                "email" => $request->email ?? $user->email,
                "phoneNumber" => $request->phone ?? $user->phoneNumber,
                "shippingAddress" => $request->shippingAddress ?? $user->shippingAddress,
                "appIdentifier" => $request->appIdentifier ?? $user->appIdentifier,
                "vendorID" => $request->vendorID ?? $user->vendorID,
                "isDocumentVerify" => $request->isDocumentVerify ?? $user->isDocumentVerify,
                "profilePictureURL" => $request->profile_pic ?? $user->profilePictureURL,
                "countryCode" => $request->countryCode ?? $user->countryCode,
                "role" => $request->role ?? $user->role,
                "active" => $request->active ?? $user->active,
                "vType" => $request->vType ?? $user->vType,
                "zoneId" => $request->zoneId ?? $user->zoneId,
                "wallet_amount" => $request->wallet_amount ?? $user->wallet_amount,
                "isActive" => $request->isActive ?? $user->isActive,
                "userBankDetails" => $request->userBankDetails ?? $user->userBankDetails,
                "photos" => $request->photos ?? $user->photos,
                "_created_at" => $request->_created_at ?? $user->_created_at,
                "_updated_at" => now(),
                "orderCompleted" => $request->orderCompleted ?? $user->orderCompleted,
                "orderRequestData" => $request->orderRequestData ?? $user->orderRequestData,
                "subscriptionPlanId" => $request->subscriptionPlanId ?? $user->subscriptionPlanId,
                "subscription_plan" => $request->subscription_plan ?? $user->subscriptionPlan,
                "subscriptionExpiryDate" => $request->subscriptionExpiryDate ?? $user->subscriptionExpiryDate,
                "fcmToken" => $request->fcmToken ?? $user->fcmToken,
                "inProgressOrderID" => $request->inProgressOrderID ?? $user->inProgressOrderID,
                "carName" => $request->carName ?? $user->carName,
                "carNumber" => $request->carNumber ?? $user->carNumber,
                "carPictureURL" => $request->carPictureURL ?? $user->carPictureURL,
            ];

            // Update user
            $user->update($updateData);

            // Location needs special handling if available
            if ($request->has('location')) {
                $user->location = $request->location;
                $user->save();
            }

            return response()->json([
                "success" => true,
                "message" => "User updated successfully",
                "data" => $user
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                "success" => false,
                "message" => $e->getMessage(),
            ], 500);
        }
    }


    public function updateDriverUser(Request $request)
    {
        $request->validate([
            'id' => 'required|string' // firebase_id
        ]);

        try {
            $user = User::where('firebase_id', $request->id)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $updateData = [
                "firstName" => $request->firstName ?? $user->firstName,
                "lastName" => $request->lastName ?? $user->lastName,
                "email" => $request->email ?? $user->email,
                "phoneNumber" => $request->phone ?? $user->phoneNumber,
                "shippingAddress" => $request->shippingAddress ?? $user->shippingAddress,
                "appIdentifier" => $request->appIdentifier ?? $user->appIdentifier,
                "vendorID" => $request->vendorID ?? $user->vendorID,
                "isDocumentVerify" => $request->isDocumentVerify ?? $user->isDocumentVerify,
                "profilePictureURL" => $request->profile_pic ?? $user->profilePictureURL,
                "countryCode" => $request->countryCode ?? $user->countryCode,
                "role" => $request->role ?? $user->role,
                "active" => $request->active ?? $user->active,
                "vType" => $request->vType ?? $user->vType,
                "zoneId" => $request->zoneId ?? $user->zoneId,
                "wallet_amount" => $request->wallet_amount ?? $user->wallet_amount,
                "isActive" => $request->isActive ?? $user->isActive,
                "userBankDetails" => $request->userBankDetails ?? $user->userBankDetails,
                "photos" => $request->photos ?? $user->photos,
                "_created_at" => $request->_created_at ?? $user->_created_at,
                "_updated_at" => now(),
                "orderCompleted" => $request->orderCompleted ?? $user->orderCompleted,
                "orderRequestData" => $request->orderRequestData ?? $user->orderRequestData,
                "subscriptionPlanId" => $request->subscriptionPlanId ?? $user->subscriptionPlanId,
                "subscription_plan" => $request->subscription_plan ?? $user->subscriptionPlan,
                "subscriptionExpiryDate" => $request->subscriptionExpiryDate ?? $user->subscriptionExpiryDate,
                "fcmToken" => $request->fcmToken ?? $user->fcmToken,
                "inProgressOrderID" => $request->inProgressOrderID ?? $user->inProgressOrderID,
                "carName" => $request->carName ?? $user->carName,
                "carNumber" => $request->carNumber ?? $user->carNumber,
                "carPictureURL" => $request->carPictureURL ?? $user->carPictureURL,
            ];

            // Update user
            $user->update($updateData);

            // Location needs special handling if available
            if ($request->has('location')) {
                $user->location = $request->location;
                $user->save();
            }

            return response()->json([
                "success" => true,
                "message" => "User updated successfully",
                "data" => $user
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                "success" => false,
                "message" => $e->getMessage(),
            ], 500);
        }
    }



}
