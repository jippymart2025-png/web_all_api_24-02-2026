<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\vendor_products;
use App\Models\VendorCategory;
use App\Models\Coupon;
use App\Models\Vendor;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class VendorController extends Controller
{
    /**
     * Get Products by Vendor ID
     */
//    public function getProductsByVendorId(Request $request, $vendorId)
//    {
//        try {
//            $foodType = $request->query('food_type', 'Delivery');
//            $limit = 400;
//
//            $query = vendor_products::query()
//                ->where('vendorID', $vendorId)
//                ->where('publish', true)
//                ->orderBy('createdAt', 'asc')
//                ->limit($limit);
//
//            if ($foodType === 'Delivery') {
//                $query->where('takeaway_option', false);
//            }
//
//            $products = $query->get();
//
//            return response()->json([
//                'success' => true,
//                'data' => $products,
//                'count' => $products->count(),
//                'food_type' => $foodType,
//            ]);
//        } catch (\Throwable $e) {
//            Log::error('getProductsByVendorId error: ' . $e->getMessage());
//            return response()->json([
//                'success' => false,
//                'message' => 'Failed to load products'
//            ], 500);
//        }
//    }

    /**
     * Get Vendor Category by ID
     */
    public function getVendorCategoryById($id)
    {
        $category = VendorCategory::find($id);
        if (!$category) {
            return response()->json(['success' => false, 'message' => 'Category not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $category]);
    }

    /**
     * Get Product by ID
     */
    public function getProductById($id)
    {
        $product = vendor_products::find($id);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $product]);
    }

    /**
     * 0021a904-ff79-4e2f-93ab-b71bd98f32de
     * Get Offers by Vendor ID
     */
    public function getOffersByVendorId($vendorId)
    {
        $offers = Coupon::where('resturant_id', $vendorId)
            ->where('isEnabled', true)
            ->where('isPublic', true)
            ->where('expiresAt', '>=', Carbon::now())
            ->get();

        return response()->json(['success' => true, 'data' => $offers]);
    }


public function getNearestRestaurantByCategory(Request $request, $categoryId)
{
    $request->validate([
        'latitude'  => 'required|numeric|between:-90,90',
        'longitude' => 'required|numeric|between:-180,180',
        'radius'    => 'nullable|numeric|min:0',
        'filter'    => 'nullable|in:distance,rating',
    ]);

    $lat    = (float) $request->latitude;
    $lng    = (float) $request->longitude;
    $radius = (float) ($request->radius ?? 10);
    $filter = $request->filter ?? 'distance';

    /* ---------- BOUNDING BOX ---------- */
    $earthRadius = 6371;

    $latDelta = rad2deg($radius / $earthRadius);
    $lngDelta = rad2deg($radius / $earthRadius / cos(deg2rad($lat)));

    $minLat = $lat - $latDelta;
    $maxLat = $lat + $latDelta;
    $minLng = $lng - $lngDelta;
    $maxLng = $lng + $lngDelta;

    /* ---------- QUERY ---------- */
    $vendors = Vendor::query()
        ->where('publish', 1)
        ->where('isOpen', 1) // manual override still respected
        ->whereRaw("JSON_CONTAINS(categoryID, JSON_QUOTE(?))", [$categoryId])
        ->whereBetween('latitude', [$minLat, $maxLat])
        ->whereBetween('longitude', [$minLng, $maxLng])
        ->select('*')
        ->selectRaw(
            '(6371 * acos(
                cos(radians(?)) * cos(radians(latitude)) *
                cos(radians(longitude) - radians(?)) +
                sin(radians(?)) * sin(radians(latitude))
            )) AS distance',
            [$lat, $lng, $lat]
        )
        ->having('distance', '<=', $radius)
        ->when($filter === 'rating', function ($q) {
            $q->orderByRaw(
                '(CASE WHEN reviewsCount > 0
                THEN reviewsSum / reviewsCount
                ELSE 0 END) DESC'
            );
        }, function ($q) {
            $q->orderBy('distance');
        })
        ->limit(50)
        ->get();

    /* ---------- JSON DECODE + ACTUAL OPEN STATUS ---------- */
    $jsonFields = [
        'photos',
        'workingHours',
        'categoryID',
        'categoryTitle',
        'filters',
        'adminCommission',
        'specialDiscount',
        'restaurantMenuPhotos',
        'g',
    ];

    $vendors->transform(function ($vendor) use ($jsonFields) {

        // Decode JSON fields
        foreach ($jsonFields as $field) {
            if (!empty($vendor->$field) && is_string($vendor->$field)) {
                $decoded = json_decode($vendor->$field, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $vendor->$field = $decoded;
                }
            }
        }

        // ✅ REAL-TIME OPEN STATUS
        $vendor->actualIsOpen = $this->calculateActualIsOpen(
            (bool) $vendor->isOpen,
            $vendor->workingHours ?? null
        );

        return $vendor;
    });

    return response()->json([
        'success' => true,
        'count'   => $vendors->count(),
        'data'    => $vendors,
    ]);
}

    protected function calculateActualIsOpen(bool $isOpenFlag, ?array $workingHours): bool
    {
        // TIER 1: Manual Override Check
        // If restaurant is manually closed (isOpen = false), always return false
        if (!$isOpenFlag) {
            return false;
        }

        // TIER 2: Working Hours Check
        // Restaurant is not manually closed (isOpen = true), now check working hours

        // Validate and check if working hours are configured
        if (empty($workingHours) || !is_array($workingHours)) {
            return false;
        }

        // Check if working hours are disabled (all timeslots are empty)
        $hasValidWorkingHours = false;
        foreach ($workingHours as $daySchedule) {
            // Handle both property orders: {"day":"Monday","timeslot":[...]} or {"timeslot":[...],"day":"Monday"}
            if (!isset($daySchedule['timeslot']) || !is_array($daySchedule['timeslot']) || empty($daySchedule['timeslot'])) {
                continue;
            }

            foreach ($daySchedule['timeslot'] as $timeslot) {
                $fromRaw = isset($timeslot['from']) ? trim((string)$timeslot['from']) : '';
                $toRaw = isset($timeslot['to']) ? trim((string)$timeslot['to']) : '';
                if ($fromRaw !== '' && $toRaw !== '') {
                    $hasValidWorkingHours = true;
                    break 2;
                }
            }
        }

        // If working hours are disabled (no valid timeslots), restaurant is closed
        if (!$hasValidWorkingHours) {
            return false;
        }

        // Working hours are enabled, check if current time is within working hours
        // Use Asia/Kolkata timezone for Indian restaurants (explicitly set for restaurant operations)
        // Defaults to Asia/Kolkata for restaurant operations regardless of app timezone
        $tz = 'Asia/Kolkata';
        $now = Carbon::now($tz);
        $currentDay = $now->format('l'); // Returns: Monday, Tuesday, Wednesday, etc.
        $currentMinutes = (int)$now->format('H') * 60 + (int)$now->format('i');


        // Check if current time is within any timeslot for today
        // Loops through all days and checks all timeslots (handles multiple slots per day)
        foreach ($workingHours as $daySchedule) {
            // Match current day (property order doesn't matter)
            $dayName = $daySchedule['day'] ?? null;

            // Skip if no day name or doesn't match current day
            if ($dayName === null || trim($dayName) === '') {
                continue;
            }

            // Normalize day names for comparison (handle any whitespace)
            $dayNameNormalized = trim($dayName);
            if ($dayNameNormalized !== $currentDay) {
                continue;
            }

            // Get timeslots for this day (property order doesn't matter)
            $timeslots = $daySchedule['timeslot'] ?? null;
            if (!is_array($timeslots) || empty($timeslots)) {
                continue;
            }

            // Check each timeslot for today (processes in order: morning → afternoon → evening)
            foreach ($timeslots as $timeslot) {
                $fromRaw = isset($timeslot['from']) ? trim((string)$timeslot['from']) : '';
                $toRaw = isset($timeslot['to']) ? trim((string)$timeslot['to']) : '';

                if ($fromRaw === '' || $toRaw === '') {
                    continue;
                }

                $fromMinutes = $this->parseTimeToMinutes($fromRaw, $tz);
                $toMinutes = $this->parseTimeToMinutes($toRaw, $tz);

                if ($fromMinutes === null || $toMinutes === null) {
                    // Log warning for invalid time format (data issue)
                    Log::warning('Invalid time format in timeslot', [
                        'day' => $dayName,
                        'from' => $fromRaw,
                        'to' => $toRaw
                    ]);
                    continue;
                }

                // Check if current time falls within this timeslot
                if ($toMinutes >= $fromMinutes) {
                    // Normal case: same day time range (e.g., 09:00 to 17:00, or 07:00 to 11:00)
                    if ($currentMinutes >= $fromMinutes && $currentMinutes <= $toMinutes) {
                        return true; // Restaurant is OPEN - found matching timeslot
                    }
                } else {
                    // Edge case: crosses midnight (e.g., 22:00 to 02:00)
                    if ($currentMinutes >= $fromMinutes || $currentMinutes <= $toMinutes) {
                        return true; // Restaurant is OPEN - found matching timeslot
                    }
                }
            }
        }

        // Not within any working hours timeslot
        return false;
    }

        protected function parseTimeToMinutes(string $timeString, string $timezone = 'UTC'): ?int
    {
        $timeString = trim($timeString);
        if ($timeString === '') {
            return null;
        }

        // Try simple HH:MM format first
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $timeString, $matches)) {
            $hours = (int)$matches[1];
            $minutes = (int)$matches[2];
            if ($hours >= 0 && $hours <= 23 && $minutes >= 0 && $minutes <= 59) {
                return $hours * 60 + $minutes;
            }
        }

        // Fallback to Carbon parsing
        $formats = ['H:i', 'G:i', 'h:i A', 'g:i A', 'H:i:s', 'h:i:s A'];
        foreach ($formats as $format) {
            try {
                $dt = Carbon::createFromFormat($format, $timeString, $timezone);
                if ($dt !== false) {
                    return (int)$dt->format('H') * 60 + (int)$dt->format('i');
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Final fallback
        try {
            $dt = Carbon::parse($timeString, $timezone);
            return (int)$dt->format('H') * 60 + (int)$dt->format('i');
        } catch (\Exception $e) {
            return null;
        }
    }


    public function getMartVendorById($vendorId): \Illuminate\Http\JsonResponse
    {
        try {
            $vendor = Vendor::query()
                ->where('vType', 'LIKE', '%mart%')
                ->where(function ($query) use ($vendorId) {
                    foreach ($this->expandMartVendorIds($vendorId) as $candidate) {
                        $query->orWhere('id', $candidate);
                    }
                })
                ->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mart vendor not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->transformVendor($vendor),
            ]);
        } catch (\Throwable $e) {
            Log::error('getMartVendorById error: ' . $e->getMessage(), [
                'vendor_id' => $vendorId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch mart vendor',
            ], 500);
        }
    }


    public function getDefaultMartVendor(): \Illuminate\Http\JsonResponse
    {
        try {
            $vendor = Vendor::query()
                ->where('vType', 'LIKE', '%mart%')
                ->where('isOpen', 1)
                ->where('publish', 1)
                ->orderByDesc('createdAt')
                ->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'No mart vendors available',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->transformVendor($vendor),
            ]);
        } catch (\Throwable $e) {
            Log::error('getDefaultMartVendor error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch default mart vendor',
            ], 500);
        }
    }

    public function getMartVendorsByZone($zoneId)
    {
        if (empty($zoneId)) {
            return response()->json([
                'success' => false,
                'message' => 'Zone ID is required',
            ], 400);
        }

        try {
            $vendors = Vendor::query()
                ->whereRaw('LOWER(vType) = ?', ['mart'])
                ->where('zoneId', $zoneId)
                ->orderByDesc('isOpen')
                ->orderBy('title')
                ->get()
                ->map(function ($vendor) {
                    return $this->transformVendor($vendor);
                });

            return response()->json([
                'success' => true,
                'count' => $vendors->count(),
                'zone_id' => $zoneId,
                'data' => $vendors->values(),
            ]);
        } catch (\Throwable $e) {
            Log::error('getMartVendorsByZone error: ' . $e->getMessage(), [
                'zone_id' => $zoneId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch mart vendors by zone',
            ], 500);
        }
    }

    private function transformVendor(Vendor $vendor): array
    {
        $data = $vendor->toArray();
        foreach ($this->jsonFields() as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->safeJsonDecode($data[$field]);
            }
        }

        return $data;
    }

    private function jsonFields(): array
    {
        return [
            'restaurantMenuPhotos',
            'photos',
            'workingHours',
            'filters',
            'coordinates',
            'lastAutoScheduleUpdate',
            'createdAt',
            'categoryID',
            'categoryTitle',
            'specialDiscount',
            'adminCommission',
            'g',
            'location',
        ];
    }

    private function safeJsonDecode($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return $value;
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function expandMartVendorIds(string $vendorId): array
    {
        $baseId = $vendorId;
        $lowerVendorId = strtolower($vendorId);

        if (Str::startsWith($lowerVendorId, 'mart_')) {
            $baseId = substr($vendorId, strpos($vendorId, '_') + 1);
        }

        return array_values(array_unique(array_filter([
            $vendorId,
            $baseId,
            'mart_' . $baseId,
            'MART_' . $baseId,
        ])));
    }
}
