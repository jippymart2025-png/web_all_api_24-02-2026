<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverUserController extends Controller
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
                    'firstName' => $user->firstName ?? null,
                    'lastName' => $user->lastName ?? null,
                    'email' => $user->email ?? null,
                    'phone' => $user->phoneNumber ?? null,
                    'profile_pic' => $user->profilePictureURL ?? null,
                    'countryCode' => $user->countryCode ?? null,
                    'role' => $user->role ?? null,
                    'active' => $user->active ?? null,
                    'vType' => $user->vType ?? null,
                    'zoneId' => $user->zoneId ?? null,
                    'vendorID' => $user->vendorID ?? null,
                    'isDocumentVerify' => $user->isDocumentVerify ?? null,
                    'wallet_amount' => $user->wallet_amount ?? null,
                    'isActive' => $user->isActive ?? null,
                    'userBankDetails' => json_decode($user->userBankDetails ?? '{}', true),
                    'photos' => $user->photos ?? null,
                    'location' => json_decode($user->location ?? '{}', true),
                    'shippingAddress' => json_decode($user->shippingAddress ?? '{}', true),
                    'inProgressOrderID' => json_decode($user->inProgressOrderID, true) ?? [],
                    'inProgressOrderID' => json_decode($user->inProgressOrderID ?? '[]', true),
	            'fcmToken' => $user->fcmToken ?? null,
                    'subscriptionPlanId' => $user->subscriptionPlanId ?? null,
                    'subscription_plan' => $user->subscription_plan ?? null,
                    'subscriptionExpiryDate' => $user->subscriptionExpiryDate ?? null,
                    'carName' => $user->carName ?? null,
                    'carNumber' => $user->carNumber ?? null,
                    'carPictureURL' => $user->carPictureURL ?? null,
                    'rotation' => $user->rotation ?? null,
                    'orderCompleted' => $user->orderCompleted ?? null,
                    'orderRequestData' => json_decode($user->orderRequestData, true) ?? [],
                    'deliveryAmount' => $user->deliveryAmount ?? null,
                    'settings' => json_decode($user->settings ?? '{}', true),
                    'lastOnlineTimestamp' => $user->lastOnlineTimestamp ?? null,
                    'password' => $user->password ?? null,
                    'remember_token' => $user->remember_token ?? null,
                    'migratedBy' => $user->migratedBy ?? null,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user profile: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function getDocumentList()
    {
        $documents = DB::table('documents')
            ->where('type', 'driver')
            ->where('enable', 1) // true
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Driver document type list fetched',
            'data' => $documents
        ]);
    }



    public function getDriverDocuments($driver_id)
    {
        $driverDocs = DB::table('documents_verify')
            ->where('id', $driver_id)  // firebase_id stored as 'id' in documents_verify
            ->first();

        if (!$driverDocs) {
            return response()->json([
                'success' => false,
                'message' => 'No document uploaded yet',
                'data' => null
            ], 404);
        }

        // convert JSON documents to array
        $driverDocs->documents = json_decode($driverDocs->documents, true);

        return response()->json([
            'success' => true,
            'message' => 'Driver uploaded document fetched',
            'data' => $driverDocs
        ]);
    }

}
