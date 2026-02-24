<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

use App\Models\Vendor;
use App\Models\VendorProduct;
use App\Models\VendorCategory;

class SwiggySearchController extends Controller
{
    /**
     * Unified Swiggy-style Search
     */
    public function unifiedSearch(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'query'     => 'required|string|min:2',
            'zone_id'   => 'required|string',
            'latitude'  => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'limit'     => 'nullable|integer|min:1|max:100',
            'page'      => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors()
            ], 422);
        }

        try {
            // FIX: InputBag → string
            $query     = $request->input('query');
            $zoneId    = $request->input('zone_id');
            $latitude  = $request->input('latitude');
            $longitude = $request->input('longitude');

            $limit  = $request->input('limit', 20);
            $page   = $request->input('page', 1);
            $offset = ($page - 1) * $limit;

            // Run all searches
            $restaurants = $this->searchRestaurants($query, $zoneId, $latitude, $longitude, $limit, $offset);
            $products    = $this->searchProducts($query, $zoneId, $limit, $offset);
            $categories  = $this->searchCategories($query, $limit, $offset);

            // Format results
            $formattedRestaurants = $restaurants->map(fn ($r) => $this->formatRestaurantResponse($r));
            $formattedProducts    = $products->map(fn ($p) => $this->formatProductResponse($p));
            $formattedCategories  = $categories->map(fn ($c) => $this->formatCategoryResponse($c));

            // Count restaurants where is_open is true
            $openCount = $formattedRestaurants->filter(fn ($restaurant) => isset($restaurant['is_open']) && $restaurant['is_open'] === true)->count();

            $totalResults =
                $formattedRestaurants->count() +
                $formattedProducts->count() +
                $formattedCategories->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'restaurants'   => $formattedRestaurants,
                    'products'      => $formattedProducts,
                    'categories'    => $formattedCategories,
                    'total_results' => $totalResults,
                ],
                'meta' => [
                    'page'     => $page,
                    'limit'    => $limit,
                    'query'    => $query,
                    'zone_id'  => $zoneId,
                    'has_more' => $totalResults >= $limit,
                    'openCount' => $openCount
                ]
            ]);

        } catch (\Exception $e) {

            Log::error('Unified Search Error : ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to perform search',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Restaurant Search (Uses real columns only)
     */
    private function searchRestaurants($query, $zoneId, $latitude, $longitude, $limit, $offset)
    {
        $restaurantQuery = Vendor::where(function ($q) {
            $q->where('publish', 1)->orWhereNull('publish');
        })
            ->where('zoneId', $zoneId)
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%")
                    ->orWhere('location', 'like', "%{$query}%")
                    ->orWhere('vType', 'like', "%{$query}%")
                    ->orWhere('cuisineTitle', 'like', "%{$query}%")
                    ->orWhere('categoryTitle', 'like', "%{$query}%")
                    ->orWhere('restaurant_slug', 'like', "%{$query}%")
                    ->orWhere('zone_slug', 'like', "%{$query}%");
            });

        // Distance sorting
        if ($latitude && $longitude) {
            $restaurantQuery->selectRaw(
                "vendors.*,
                (6371 * acos(
                    cos(radians(?)) * cos(radians(latitude)) *
                    cos(radians(longitude) - radians(?)) +
                    sin(radians(?)) * sin(radians(latitude))
                )) AS distance",
                [$latitude, $longitude, $latitude]
            )
                ->orderBy('distance', 'asc');
        } else {
            $restaurantQuery->orderBy('title', 'asc');
        }

        return $restaurantQuery->skip($offset)->take($limit)->get();
    }

    /**
     * Product Search
     */
    private function searchProducts($query, $zoneId, $limit, $offset)
    {
        return VendorProduct::where('publish', 1)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%")
                    ->orWhere('categoryID', 'like', "%{$query}%");
            })
            ->whereHas('vendor', function ($q) use ($zoneId) {
                $q->where('zoneId', $zoneId)
                    ->where(function ($q) {
                        $q->where('publish', 1)->orWhereNull('publish');
                    });
            })
            ->orderBy('name', 'asc')
            ->skip($offset)
            ->take($limit)
            ->get();
    }

    /**
     * Category Search
     */
    private function searchCategories($query, $limit, $offset)
    {
        return VendorCategory::where('publish', 1)
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%");
            })
            ->orderBy('title', 'asc')
            ->skip($offset)
            ->take($limit)
            ->get();
    }

    /**
     * Format Restaurant Response
     */
    private function formatRestaurantResponse($r)
    {
        // CRITICAL: Parse working hours properly
        // Supports both JSON string and array formats
        $workingHours = null;
        if (!empty($r->workingHours)) {
            if (is_string($r->workingHours)) {
                $decoded = json_decode($r->workingHours, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $workingHours = $decoded;
                }
            } elseif (is_array($r->workingHours)) {
                $workingHours = $r->workingHours;
            }
        }

        // CRITICAL: Get isOpen flag - handle multiple possible values
        // Logic: NULL/not set = not manually closed (default true, check working hours)
        //        false/0 = manually closed (always closed)
        //        true/1 = not manually closed (check working hours)
        // IMPORTANT: Laravel's boolean cast converts NULL to false, so we need raw DB value
        $isOpenFlag = true; // Default: not manually closed, allow working hours check

        // Get raw value from database (before boolean cast)
        $rawValue = null;

        if ($r instanceof \Illuminate\Database\Eloquent\Model) {
            // Get raw value from database (before boolean casting)
            // Use getRawOriginal() to bypass Laravel's boolean cast
            // This is critical because the Vendor model casts isOpen to boolean
            if (method_exists($r, 'getRawOriginal')) {
                $rawValue = $r->getRawOriginal('isOpen');
                if ($rawValue === null) {
                    $rawValue = $r->getRawOriginal('is_open');
                }
            }

            // Fallback to getAttributes() if getRawOriginal doesn't work
            if ($rawValue === null) {
                $attributes = $r->getAttributes();
                if (array_key_exists('isOpen', $attributes)) {
                    $rawValue = $attributes['isOpen'];
                } elseif (array_key_exists('is_open', $attributes)) {
                    $rawValue = $attributes['is_open'];
                }
            }
        } else {
            // Fallback for stdClass objects from raw DB queries
            if (property_exists($r, 'isOpen')) {
                $rawValue = $r->isOpen;
            } elseif (property_exists($r, 'is_open')) {
                $rawValue = $r->is_open;
            }
        }

        // Process the raw value
        // NULL/not set = not manually closed (default true, check working hours)
        // 0/false = manually closed (always closed)
        // 1/true = not manually closed (check working hours)
        if ($rawValue !== null) {
            // Check if it's explicitly false/0 (manually closed)
            $isFalse = ($rawValue === false ||
                       $rawValue === 0 ||
                       $rawValue === '0' ||
                       $rawValue === 'false' ||
                       (is_string($rawValue) && strtolower(trim($rawValue)) === 'false'));

            if ($isFalse) {
                $isOpenFlag = false; // Manually closed - always return closed
            } else {
                // Any other value (1, true, '1', 'true', etc.) means not manually closed
                $isOpenFlag = true; // Not manually closed - check working hours
            }
        }
        // If NULL/not set, keep default true (check working hours)

        // Calculate actual isOpen status using two-tier logic
        $actualIsOpen = $this->calculateActualIsOpen($isOpenFlag, $workingHours);

        return [
            'id' => $r->id,
            'title' => $r->title ?? '',
            'description' => $r->description ?? '',
            'location' => $r->location ?? '',
            'latitude' => $r->latitude ?? null,
            'longitude' => $r->longitude ?? null,
            'zoneId' => $r->zoneId ?? '',
            'photo' => $r->photo ?? '',
            'cover_photo' => $r->cover_photo ?? '',
            'phonenumber' => $r->phonenumber ?? '',
            'email' => $r->email ?? '',
            'address' => $r->address ?? '',
            'publish' => (bool) ($r->publish ?? true),
            'vType' => $r->vType ?? '',
            'categoryTitle' => $r->categoryTitle ?? [],
            'workingHours' => $workingHours,
            'rating' => $r->rating ?? 0,
            'total_rating' => $r->total_rating ?? 0,
            'delivery_time' => $r->delivery_time ?? '',
            'delivery_charge' => $r->delivery_charge ?? 0,
            'minimum_order' => $r->minimum_order ?? 0,
            'is_open' => $actualIsOpen, // This is the calculated status
            'distance' => $r->distance ?? null,
            'created_at' => $r->created_at ? $r->created_at->toISOString() : null,
            'updated_at' => $r->updated_at ? $r->updated_at->toISOString() : null,
        ];
    }

    /**
     * Format Product Response
     */
    private function formatProductResponse($p)
    {
        return [
            'id'         => $p->id,
            'name'       => $p->name,
            'description'=> $p->description,
            'price'      => $p->price,
            'disPrice'   => $p->disPrice,
            'isAvailable' => $p->isAvailable,
            'photo'      => $p->photo,
            'categoryID' => $p->categoryID,
            'vendorID'   => $p->vendorID,
            'veg'        => (bool)$p->veg,
            'nonveg'     => (bool)$p->nonveg,
        ];
    }

    /**
     * Format Category Response
     */
    private function formatCategoryResponse($c)
    {
        return [
            'id'          => $c->id,
            'title'       => $c->title,
            'photo'       => $c->photo,
            'publish'     => (bool)$c->publish,
            'description' => $c->description,
            'vType'       => $c->vType,
        ];
    }

    /**
     * Calculate actual isOpen status based on isOpen flag and working hours
     *
     * TWO-TIER LOGIC:
     *
     * TIER 1: Manual Override (Priority 1)
     * - If isOpen flag is FALSE → Always CLOSED (manual override from "Apply to All Restaurants")
     *
     * TIER 2: Working Hours Check (Priority 2)
     * - If isOpen flag is TRUE → Check working hours:
     *   - No valid working hours configured → CLOSED
     *   - Current time within working hours → OPEN
     *   - Current time outside working hours → CLOSED
     *
     * Supports multiple JSON formats:
     * - Format 1: [{"day":"Monday","timeslot":[{"from":"10:00","to":"22:00"}]}]
     * - Format 2: [{"timeslot":[{"from":"07:00","to":"11:00"},{"from":"12:00","to":"15:30"}],"day":"Monday"}]
     * - Handles multiple timeslots per day (morning, afternoon, evening)
     * - Property order doesn't matter (day/timeslot can be in any order)
     *
     * @param bool $isOpenFlag The isOpen flag from database (supports both 'isOpen' and 'is_open' columns)
     * @param array|null $workingHours The working hours array from database (JSON format)
     * @return bool True if restaurant is open, false otherwise
     */
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

    /**
     * Parse a time string into minutes since midnight
     *
     * Supports multiple formats:
     * - 24-hour format: "HH:MM" (e.g., "09:30", "11:30", "22:00")
     * - 12-hour format: "h:i A" (e.g., "09:30 AM", "11:30 PM")
     * - With seconds: "HH:MM:SS" or "h:i:s A"
     *
     * @param string $timeString Time string to parse
     * @param string $timezone Timezone for parsing (default: UTC)
     * @return int|null Minutes since midnight, or null if parsing fails
     */
    protected function parseTimeToMinutes(string $timeString, string $timezone = 'UTC'): ?int
    {
        $timeString = trim($timeString);
        if ($timeString === '') {
            return null;
        }

        // Try simple HH:MM format first (most common and fastest)
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
}
