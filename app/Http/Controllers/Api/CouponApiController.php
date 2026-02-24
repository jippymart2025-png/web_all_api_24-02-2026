<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CouponApiController extends Controller
{
    /**
     * Get coupons by type (restaurant / mart)
     * GET /api/coupons/restaurant
     * GET /api/coupons/mart
     */
    public function byType(Request $request, string $type)
{
    try {
        // Validate coupon type
        if (!in_array($type, ['restaurant', 'mart'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid coupon type. Allowed: restaurant, mart',
            ], 400);
        }

        $resturantId = $request->input('resturant_id'); // optional
        $now = Carbon::now('UTC');

        $coupons = Coupon::query()
            ->where('isEnabled', true)
            ->where('isPublic', true)
            ->where('cType', $type)
            ->when($resturantId, function ($query) use ($resturantId) {
                // If restaurant id is provided → filter by it OR ALL
                $query->where(function ($q) use ($resturantId) {
                    $q->where('resturant_id', $resturantId)
                      ->orWhere('resturant_id', 'ALL');
                });
            })
            // If restaurant id is NOT provided → return ALL coupons of this type
            ->orderBy('expiresAt', 'asc')
            ->get();

        // Filter expired coupons
        $coupons = $coupons->filter(function (Coupon $coupon) use ($now) {
            $expiresAt = $this->parseTimestamp($coupon->expiresAt);
            return $expiresAt === null || $expiresAt->greaterThanOrEqualTo($now);
        });

        // Format response
        $data = $coupons->map(function (Coupon $coupon) {
            $usedBy = $coupon->usedBy;
            if (is_string($usedBy) && $usedBy !== '') {
                $decoded = json_decode($usedBy, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $usedBy = $decoded;
                }
            }

            return [
                'id' => (string) ($coupon->id ?? ''),
                'code' => (string) ($coupon->code ?? ''),
                'description' => (string) ($coupon->description ?? ''),
                'discount' => (string) ($coupon->discount ?? '0'),
                'expiresAt' => $this->formatTimestamp($coupon->expiresAt),
                'discountType' => (string) ($coupon->discountType ?? ''),
                'image' => $coupon->image ?? null,
                'resturant_id' => (string) ($coupon->resturant_id ?? ''),
                'cType' => (string) ($coupon->cType ?? ''),
                'item_value' => (float) ($coupon->item_value ?? 0),
                'usageLimit' => (int) ($coupon->usageLimit ?? 0),
                'usedCount' => (int) ($coupon->usedCount ?? 0),
                'usedBy' => is_array($usedBy) ? $usedBy : [],
                'isPublic' => (bool) $coupon->isPublic,
                'isEnabled' => (bool) $coupon->isEnabled,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data->values(),
            'count' => $data->count(),
        ]);

    } catch (\Exception $e) {

        Log::error('Coupon fetch failed: ' . $e->getMessage(), [
            'type' => $type,
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch coupons',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

    // public function byType(Request $request, string $type)
    // {
    //     try {
    //         if (!in_array($type, ['restaurant', 'mart'])) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Invalid coupon type. Allowed: restaurant, mart',
    //             ], 400);
    //         }

    //         $resturantId = $request->input('resturant_id');
    //         if (empty($resturantId)) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'The resturant_id field is required.',
    //             ], 400);
    //         }

    //         $now = Carbon::now('UTC');

    //         $coupons = Coupon::query()
    //             ->where('isEnabled', true)
    //             ->where('isPublic', true)
    //             ->where('cType', $type)
    //             ->where(function ($query) use ($resturantId) {
    //                 $query->where('resturant_id', $resturantId)
    //                     ->orWhere('resturant_id', 'ALL');
    //             })
    //             ->orderBy('expiresAt', 'asc')
    //             ->get();

    //         // Filter expired coupons
    //         $coupons = $coupons->filter(function (Coupon $coupon) use ($now) {
    //             $expiresAt = $this->parseTimestamp($coupon->expiresAt);
    //             return $expiresAt === null || $expiresAt->greaterThanOrEqualTo($now);
    //         });

    //         // Format response data
    //         $data = $coupons->map(function (Coupon $coupon) {
    //             $usedBy = $coupon->usedBy;
    //             if (is_string($usedBy) && $usedBy !== '') {
    //                 $decoded = json_decode($usedBy, true);
    //                 if (json_last_error() === JSON_ERROR_NONE) {
    //                     $usedBy = $decoded;
    //                 }
    //             }

    //             return [
    //                 'id' => (string) ($coupon->id ?? ''),
    //                 'code' => (string) ($coupon->code ?? ''),
    //                 'description' => (string) ($coupon->description ?? ''),
    //                 'discount' => (string) ($coupon->discount ?? '0'),
    //                 'expiresAt' => $this->formatTimestamp($coupon->expiresAt),
    //                 'discountType' => (string) ($coupon->discountType ?? ''),
    //                 'image' => $coupon->image ?? null,
    //                 'resturant_id' => (string) ($coupon->resturant_id ?? ''),
    //                 'cType' => (string) ($coupon->cType ?? ''),
    //                 'item_value' => (float) ($coupon->item_value ?? 0),
    //                 'usageLimit' => (int) ($coupon->usageLimit ?? 0),
    //                 'usedCount' => (int) ($coupon->usedCount ?? 0),
    //                 'usedBy' => is_array($usedBy) ? $usedBy : [],
    //                 'isPublic' => (bool) $coupon->isPublic,
    //                 'isEnabled' => (bool) $coupon->isEnabled,
    //             ];
    //         });

    //         return response()->json([
    //             'success' => true,
    //             'data' => $data->values(),
    //             'count' => $data->count(),
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('Coupon fetch failed: ' . $e->getMessage(), [
    //             'type' => $type,
    //             'trace' => $e->getTraceAsString(),
    //         ]);

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to fetch coupons',
    //             'error' => config('app.debug') ? $e->getMessage() : null,
    //         ], 500);
    //     }
    // }


    private function parseTimestamp($value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        $value = trim((string) $value, " \t\n\r\0\x0B\"'");

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function formatTimestamp($value): ?string
    {
        $parsed = $this->parseTimestamp($value);
        return $parsed ? $parsed->toIso8601String() : null;
    }
}
