<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Driver;

class FirebaseUserController extends Controller
{

    public function index(Request $request)
    {
        $role = $request->query('role', null);
        $limit = (int) $request->query('limit', 10);
        $page = (int) $request->query('page', 1);

        if (!$role || !in_array($role, ['customer', 'vendor', 'driver'])) {
            return response()->json([
                'status' => false,
                'message' => 'Valid role is required (customer, vendor, driver)',
            ], 400);
        }

        // Calculate offset for page-based pagination
        $offset = ($page - 1) * $limit;

        $cacheKey = "firebase_users_v4_role_{$role}_page_{$page}_limit_{$limit}";

        $data = (function () use ($role, $limit, $offset) {
            if ($role === 'vendor') {
                $base = Vendor::query()->orderByDesc('created_at');
                $total = $base->count();
                $rows = $base->skip($offset)->take($limit)->get();
                $users = $rows->map(function ($row) {
                    return $this->transformUserData('vendor', [
                        'id' => $row->id,
                        'firstName' => $row->first_name ?? ($row->name ?? ''),
                        'lastName' => $row->last_name ?? '',
                        'email' => $row->email ?? '',
                        'phoneNumber' => $row->phone ?? '',
                        'countryCode' => $row->country_code ?? '',
                        'createdAt' => $row->created_at,
                        'active' => (bool) ($row->active ?? $row->status ?? 0),
                        'zoneId' => $row->zone_id ?? '',
                        'vendorID' => $row->id,
                        'vType' => $row->v_type ?? ($row->type ?? ''),
                        'subscriptionPlanId' => $row->subscription_plan_id ?? null,
                        'subscriptionExpiryDate' => $row->subscription_expiry_date ?? null,
                        'isDocumentVerify' => (bool) ($row->is_document_verify ?? 0),
                        'wallet_amount' => $row->wallet_amount ?? 0,
                    ], (string) $row->id);
                })->all();
                return [
                    'users' => $users,
                    'has_more' => ($offset + $limit) < $total,
                    'next_created_at' => null,
                    'next_doc_id' => null,
                ];
            }

            if ($role === 'driver') {
                $base = Driver::query()->orderByDesc('created_at');
                $total = $base->count();
                $rows = $base->skip($offset)->take($limit)->get();
                $users = $rows->map(function ($row) {
                    return $this->transformUserData('driver', [
                        'id' => $row->id,
                        'firstName' => $row->first_name ?? ($row->name ?? ''),
                        'lastName' => $row->last_name ?? '',
                        'email' => $row->email ?? '',
                        'phoneNumber' => $row->phone ?? '',
                        'countryCode' => $row->country_code ?? '',
                        'createdAt' => $row->created_at,
                        'active' => (bool) ($row->active ?? $row->status ?? 0),
                        'isDocumentVerify' => (bool) ($row->is_document_verify ?? 0),
                        'isActive' => (bool) ($row->is_active ?? $row->active ?? 0),
                        'fcmToken' => $row->fcm_token ?? null,
                        'wallet_amount' => $row->wallet_amount ?? 0,
                        'orderCompleted' => $row->order_completed ?? 0,
                        'zoneId' => $row->zone_id ?? '',
                        'inProgressOrderID' => $row->in_progress_order_id ?? null,
                    ], (string) $row->id);
                })->all();
                return [
                    'users' => $users,
                    'has_more' => ($offset + $limit) < $total,
                    'next_created_at' => null,
                    'next_doc_id' => null,
                ];
            }

            $base = User::query()->where('role', $role)->orderByDesc('created_at');
            $total = $base->count();
            $rows = $base->skip($offset)->take($limit)->get();
            $users = $rows->map(function ($row) {
                return $this->transformUserData('customer', [
                    'id' => $row->id,
                    'firstName' => $row->first_name ?? ($row->name ?? ''),
                    'lastName' => $row->last_name ?? '',
                    'email' => $row->email ?? '',
                    'phoneNumber' => $row->phone ?? '',
                    'countryCode' => $row->country_code ?? '',
                    'createdAt' => $row->created_at,
                    'active' => (bool) ($row->active ?? $row->status ?? 0),
                    'zoneId' => $row->zone_id ?? '',
                    'profilePictureURL' => $row->profile_picture_url ?? null,
                ], (string) $row->id);
            })->all();
            return [
                'users' => $users,
                'has_more' => ($offset + $limit) < $total,
                'next_created_at' => null,
                'next_doc_id' => null,
            ];
        })();

        // Get detailed statistics (cached separately for performance)
        // Always fetch statistics regardless of page or limit
        $countsCacheKey = "firebase_users_statistics_v2_role_{$role}";
        
        $statistics = $this->getDetailedStatistics($role);

        return response()->json([
            'status' => true,
            'message' => 'Users fetched successfully',
            'meta' => [
                'role' => $role,
                'page' => $page,
                'limit' => $limit,
                'count' => count($data['users']),
                'has_more' => $data['has_more'],
                'next_created_at' => $data['next_created_at'],
                'next_doc_id' => $data['next_doc_id'],
            ],
            'statistics' => $statistics,
            'data' => $data['users'],
        ]);
    }

    /**
     * Get detailed statistics for users by role
     * 
     * @param string $role
     * @return array
     */
    private function getDetailedStatistics(string $role): array
    {
        if ($role === 'vendor') {
            $total = Vendor::count();
            $active = Vendor::where(function ($q) { $q->where('active', 1)->orWhere('status', 1); })->count();
            $inactive = max(0, $total - $active);
            $verified = Vendor::where('is_document_verify', 1)->count();
            return [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'total_vendors' => $total,
                'active_vendors' => $active,
                'inactive_vendors' => $inactive,
                'verified_vendors' => $verified,
            ];
        }

        if ($role === 'driver') {
            $total = Driver::count();
            $active = Driver::where(function ($q) { $q->where('active', 1)->orWhere('is_active', 1)->orWhere('status', 1); })->count();
            $inactive = max(0, $total - $active);
            $verified = Driver::where('is_document_verify', 1)->count();
            return [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'total_drivers' => $total,
                'active_drivers' => $active,
                'inactive_drivers' => $inactive,
                'verified_drivers' => $verified,
            ];
        }

        $total = User::where('role', $role)->count();
        $active = User::where('role', $role)->where(function ($q) { $q->where('active', 1)->orWhere('status', 1); })->count();
        $inactive = max(0, $total - $active);
        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'total_customers' => $total,
            'active_customers' => $active,
            'inactive_customers' => $inactive,
        ];
    }

    /**
     * Transform user data based on role to return only necessary fields
     */
    private function transformUserData(string $role, array $data, string $docId): array
    {
        // Common fields
        $transformed = [
            'id' => $data['id'] ?? $docId,
            'firstName' => $data['firstName'] ?? '',
            'lastName' => $data['lastName'] ?? '',
            'email' => $data['email'] ?? '',
            'phoneNumber' => $data['phoneNumber'] ?? '',
            'countryCode' => $data['countryCode'] ?? '',
            'createdAt' => $this->formatTimestamp($data['createdAt'] ?? null),
            'active' => $data['active'] ?? false,
        ];

        switch ($role) {
            case 'customer':
                // Customer fields: Username, Email, Phone Number, Zone Management, Date, Active/Inactive
                $transformed['userName'] = trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? ''));
                $transformed['zoneId'] = $data['zoneId'] ?? '';
                $transformed['profilePictureURL'] = $data['profilePictureURL'] ?? null;
                break;

            case 'vendor':
                // Vendor fields: vendorname, Email, Phone Number, Zone Management, Vendor Type, Current Plan, ExpiryDate, Date, Documents, Active
                $transformed['vendorName'] = trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? ''));
                $transformed['vendorID'] = $data['vendorID'] ?? '';
                $transformed['zoneId'] = $data['zoneId'] ?? '';
                $transformed['vType'] = $data['vType'] ?? '';
                $transformed['subscriptionPlanId'] = $data['subscriptionPlanId'] ?? $data['subscription_plan'] ?? null;
                $transformed['subscriptionExpiryDate'] = $this->formatTimestamp($data['subscriptionExpiryDate'] ?? null);
                $transformed['isDocumentVerify'] = $data['isDocumentVerify'] ?? false;
                $transformed['wallet_amount'] = $data['wallet_amount'] ?? 0;
                break;

            case 'driver':
                // Driver fields: Name, Email, Phone Number, Date, Documents, Active, Online, Wallet History, Total Orders
                $transformed['name'] = trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? ''));
                $transformed['isDocumentVerify'] = $data['isDocumentVerify'] ?? false;
                $transformed['isActive'] = $data['isActive'] ?? null;
                $transformed['online'] = !empty($data['fcmToken']); // Presence of FCM token indicates online
                $transformed['wallet_amount'] = $data['wallet_amount'] ?? 0;
                $transformed['orderCompleted'] = $data['orderCompleted'] ?? 0;
                $transformed['zoneId'] = $data['zoneId'] ?? '';
                $transformed['inProgressOrderID'] = $data['inProgressOrderID'] ?? null;
                break;
        }

        return $transformed;
    }

    /**
     * Format Firestore timestamp to readable format
     */
    private function formatTimestamp($timestamp)
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
