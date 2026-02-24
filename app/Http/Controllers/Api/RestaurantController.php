<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class RestaurantController extends Controller
{
    /** Cache TTL: 24 hours for restaurant lists (zone/position changes rarely) */
    private const RESTAURANT_CACHE_TTL = 60; // 1 minute
    /**
     * Get Nearest Restaurants (Stream/Real-time)
     * OPTIMIZED: Cached for 5 minutes + Lazy loading
     * GET /api/restaurants/nearest
     *
     * Query Parameters:
     * - zone_id (required): Current zone ID
     * - latitude (required): User's latitude
     * - longitude (required): User's longitude
     * - radius (required): Search radius in km
     * - is_dining (optional): Filter for dine-in restaurants (default: false)
     * - user_id (optional): For subscription filtering
     * - refresh (optional): Force refresh cache (bypass cache)
     */
    /**
     * Get Best Restaurants (curated/featured)
     * OPTIMIZED: Same patterns as nearest - cache, batch subscriptions, vType filter
     * GET /api/restaurants/best
     *
     * Query Parameters:
     * - zone_id (required): Zone ID
     * - user_id (optional): For subscription filtering
     * - refresh (optional): Force refresh cache
     */
    public function bestrestaurants(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'zone_id' => 'required|string',
            'user_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $zoneId = $request->input('zone_id');
        $userId = $request->input('user_id');
        $forceRefresh = $request->boolean('refresh', false);

        try {
            /** ---------------------------------------
             * CACHE: Check cache FIRST - zero DB hits when cache exists
             * ------------------------------------- */
            $cacheKey = $this->generateBestRestaurantsCacheKey($zoneId);

            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            /** ---------------------------------------
             * Build query - same filters as nearest (vType, publish)
             * ------------------------------------- */
            $query = Vendor::query()
                ->select('vendors.*')
                ->where('zoneId', $zoneId)
                ->where('best', 1)
                ->where(function ($q) {
                    $q->where('publish', true)->orWhereNull('publish');
                });

            // Type filter (exclude marts) - same as nearest
            static $hasVTypeColumn = null;
            if ($hasVTypeColumn === null) {
                $hasVTypeColumn = DB::getSchemaBuilder()->hasColumn('vendors', 'vType');
            }
            if ($hasVTypeColumn) {
                $query->where(function ($q) {
                    $q->where('vType', 'restaurant')
                        ->orWhere('vType', 'food')
                        ->orWhereNull('vType');
                })->where('vType', '!=', 'mart');
            }

            $query->orderBy('title', 'asc');
            $restaurants = $query->get();

            // Batch fetch subscriptions (fixes N+1)
            $subscriptionsMap = $this->batchFetchSubscriptions($restaurants->pluck('id')->toArray());

            // Format, filter by subscription, sort closed to bottom - same as nearest
            $data = $restaurants->lazy()
                ->map(fn ($r) => $this->formatRestaurantResponse($r, $userId, $subscriptionsMap))
                ->filter(fn ($r) => $this->isSubscriptionValid($r))
                ->values();

            $sortedData = $data->sortBy(function ($r, $index) {
                return [$r['isOpen'] ? 0 : 1, $index];
            })->values()->all(); // Convert lazy collection to array for caching

            $openCount = collect($sortedData)->filter(fn ($r) => isset($r['isOpen']) && $r['isOpen'] === true)->count();

            $response = [
                'success' => true,
                'count' => count($sortedData),
                'openCount' => $openCount,
                'data' => $sortedData,
            ];

            try {
                Cache::put($cacheKey, $response, self::RESTAURANT_CACHE_TTL);
            } catch (\Throwable $cacheError) {
                Log::warning('Failed to cache best restaurants response', [
                    'zone_id' => $zoneId,
                    'cache_key' => $cacheKey,
                    'error' => $cacheError->getMessage(),
                ]);
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Fetch Best Restaurants Error: ' . $e->getMessage(), [
                'zone_id' => $zoneId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch best restaurants',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }




    public function nearest(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'zone_id' => 'required|string',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|numeric|min:0',
            'is_dining' => 'nullable|boolean',
            'user_id' => 'nullable|string',
            'filter' => 'nullable|string|in:distance,rating',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $zoneId = $request->input('zone_id');
        $userLat = $request->input('latitude');
        $userLon = $request->input('longitude');
        $radius = $request->has('radius') ? $request->input('radius') : null;
        $isDining = $request->input('is_dining', false);
        $userId = $request->input('user_id');
        $filter = $request->input('filter', 'distance'); // default filter

        try {
            /** ---------------------------------------
             * CACHE: Check cache FIRST - before any DB operations
             * This ensures zero database hits when cache exists
             * ------------------------------------- */
            $cacheKey = $this->generateNearestRestaurantsCacheKey(
                $zoneId,
                $userLat,
                $userLon,
                $radius,
                $isDining,
                $filter
            );

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // CRITICAL: Check cache BEFORE any database operations
            // This ensures zero DB queries when cache exists
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    // Return cached response immediately - NO database queries executed
                    return response()->json($cachedResponse);
                }
            }

            /** ---------------------------------------
             * Only execute DB queries if cache miss or force refresh
             * ------------------------------------- */

            /** ---------------------------------------
             * Build query
             * ------------------------------------- */
            $query = Vendor::query()
                ->select('vendors.*')
                ->where('zoneId', $zoneId)
                ->where('best', 0)
                ->where(function ($q) {
                    $q->where('publish', true)->orWhereNull('publish');
                });

            $hasCoordinates = $request->filled('latitude') && $request->filled('longitude');

            if ($hasCoordinates) {
                $query->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->selectRaw(
                        'vendors.*, (6371 * acos(cos(radians(?)) * cos(radians(latitude))
                    * cos(radians(longitude) - radians(?))
                    + sin(radians(?)) * sin(radians(latitude)))) AS distance',
                        [$userLat, $userLon, $userLat]
                    );

                if ($radius !== null && $filter !== 'rating') {
                    $query->havingRaw('distance <= ?', [$radius]);
                }
            }

            // Dine-in filter
            if ($isDining) {
                $query->where('enabledDiveInFuture', true);
            }

            // Type filter (exclude marts)
            // OPTIMIZATION: Cache column existence check
            static $hasVTypeColumn = null;
            if ($hasVTypeColumn === null) {
                $hasVTypeColumn = DB::getSchemaBuilder()->hasColumn('vendors', 'vType');
            }

            if ($hasVTypeColumn) {
                $query->where(function ($q) {
                    $q->where('vType', 'restaurant')
                        ->orWhere('vType', 'food')
                        ->orWhereNull('vType');
                })
                    ->where('vType', '!=', 'mart');
            }

            // Apply sorting based on filter
            switch ($filter) {
                case 'rating':
                    $query->orderByRaw('CASE WHEN COALESCE(reviewsCount, 0) > 0 THEN COALESCE(reviewsSum, 0) / NULLIF(reviewsCount, 0) ELSE 0 END DESC')
                          ->orderByRaw('COALESCE(reviewsCount, 0) DESC');
                    break;

                case 'distance':
                default:
                    if ($hasCoordinates) {
                        $query->orderBy('distance', 'asc');
                    } else {
                        $query->orderBy('title', 'asc');
                    }
                    break;
            }

            /** ---------------------------------------
             * OPTIMIZATION: Fetch restaurants and process efficiently
             * Use get() since we need all IDs for batch subscription fetch
             * ------------------------------------- */
            $restaurants = $query->get();

            // OPTIMIZATION: Batch fetch all subscriptions in one query (fixes N+1 problem)
            $subscriptionsMap = $this->batchFetchSubscriptions($restaurants->pluck('id')->toArray());

            // Format and filter subscriptions using lazy collection for memory efficiency
            $data = $restaurants->lazy()->map(function ($restaurant) use ($userId, $subscriptionsMap) {
                return $this->formatRestaurantResponse($restaurant, $userId, $subscriptionsMap);
            })->filter(function ($restaurant) {
                return $this->isSubscriptionValid($restaurant);
            })->values();

            // Apply rating sort if requested
            if ($filter === 'rating') {
                $data = $data->sortByDesc(function ($restaurant) {
                    return $restaurant['reviewsAverage'] ?? 0;
                })->values();
            }

            // Always sort closed restaurants to bottom while preserving current order
            $sortedData = $data->sortBy(function ($r, $index) {
                return [$r['isOpen'] ? 0 : 1, $index];
            })->values()->all(); // Convert lazy collection to array for caching

            // Count restaurants where isOpen is true
            $openCount = collect($sortedData)->filter(fn ($restaurant) => isset($restaurant['isOpen']) && $restaurant['isOpen'] === true)->count();

            /** ---------------------------------------
             * RESPONSE: Build and cache response
             * ------------------------------------- */
            $response = [
                'success' => true,
                'filter' => $filter,
                'availableFilters' => ['distance','rating'],
                'count' => count($sortedData),
                'openCount' => $openCount,
                'data' => $sortedData,
            ];

            // Cache the response
            try {
                Cache::put($cacheKey, $response, self::RESTAURANT_CACHE_TTL);
            } catch (\Throwable $cacheError) {
                Log::warning('Failed to cache nearest restaurants response', [
                    'zone_id' => $zoneId,
                    'cache_key' => $cacheKey,
                    'error' => $cacheError->getMessage(),
                ]);
                // Continue without caching if cache fails
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Nearest Restaurants Error: ' . $e->getMessage(), [
                'zone_id' => $zoneId,
                'latitude' => $userLat,
                'longitude' => $userLon,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch nearest restaurants',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Check if subscription is valid (Business Logic #3)
     *
     * Rules:
     * - Include if subscriptionTotalOrders = "-1" (unlimited)
     * - OR if subscription is valid (not expired) AND subscriptionTotalOrders > 0
     * - Exclude if subscription expired or orders exhausted
     * - Include if no subscription (free/commission model)
     */
    private function isSubscriptionValid($restaurant)
    {
        // If no subscription data, include restaurant (free/commission model)
        if (empty($restaurant['subscriptionPlan'])) {
            return true;
        }

        $totalOrders = $restaurant['subscriptionTotalOrders'] ?? '0';
        $expiryDate = $restaurant['subscriptionExpiryDate'] ?? null;

        // Unlimited orders (-1 means unlimited)
        if ($totalOrders === '-1' || (int)$totalOrders === -1) {
            return true;
        }

        // Check if subscription is not expired
        $isNotExpired = true;
        if ($expiryDate !== null) {
            try {
                $expiry = new \DateTime($expiryDate);
                $now = new \DateTime();
                $isNotExpired = $expiry >= $now;
            } catch (\Exception $e) {
                // If date parsing fails, assume not expired
                $isNotExpired = true;
            }
        }

        // Check if orders available and not expired
        $ordersAvailable = (int)$totalOrders > 0;

        return $isNotExpired && $ordersAvailable;
    }

    /**
     * Batch fetch subscriptions for multiple restaurants (optimization to fix N+1 problem)
     *
     * @param array $restaurantIds
     * @return array Map of restaurant_id => subscription data
     */
    private function batchFetchSubscriptions(array $restaurantIds): array
    {
        if (empty($restaurantIds)) {
            return [];
        }

        // Cache table existence check
        static $hasSubscriptionTable = null;
        if ($hasSubscriptionTable === null) {
            $hasSubscriptionTable = DB::getSchemaBuilder()->hasTable('subscription_history');
        }

        if (!$hasSubscriptionTable) {
            return [];
        }

        // Fetch all subscriptions in one query
        $subscriptions = DB::table('subscription_history')
            ->whereIn('user_id', $restaurantIds)
            ->where(function($q) {
                $q->where('expiry_date', '>=', now())
                  ->orWhereNull('expiry_date');
            })
            ->orderBy('expiry_date', 'desc')
            ->get()
            ->groupBy('user_id')
            ->map(function ($group) {
                // Get the most recent subscription (already ordered by expiry_date desc)
                return $group->first();
            });

        // Build lookup map
        $map = [];
        foreach ($subscriptions as $subscription) {
            $plan = null;
            if (!empty($subscription->subscription_plan)) {
                $plan = json_decode($subscription->subscription_plan, true);
            }

            if ($plan) {
                $map[$subscription->user_id] = [
                    'plan' => [
                        'id' => $plan['id'] ?? null,
                        'expiryDay' => $plan['expiryDay'] ?? null,
                        'expiryDate' => $subscription->expiry_date ?? null
                    ],
                    'totalOrders' => $plan['orderLimit'] ?? null,
                    'expiryDate' => $subscription->expiry_date ?? null,
                ];
            }
        }

        return $map;
    }

    /**
     * Format restaurant data for API response
     */
    private function formatRestaurantResponse($restaurant, $userId = null, array $subscriptionsMap = [])
    {
        // Get subscription data from pre-fetched map (optimization)
        $subscriptionPlan = null;
        $subscriptionTotalOrders = null;
        $subscriptionExpiryDate = null;

        if (isset($subscriptionsMap[$restaurant->id])) {
            $subData = $subscriptionsMap[$restaurant->id];
            $subscriptionPlan = $subData['plan'];
            $subscriptionTotalOrders = $subData['totalOrders'];
            $subscriptionExpiryDate = $subData['expiryDate'];
        }

        // Calculate review average
        $reviewsAverage = 0;
        if ($restaurant->reviewsCount > 0 && isset($restaurant->reviewsSum)) {
            $reviewsAverage = round($restaurant->reviewsSum / $restaurant->reviewsCount, 1);
        }

        // CRITICAL: Parse working hours properly
        // Supports both JSON string and array formats
        // Handles multiple formats:
        // - Format 1: [{"day":"Monday","timeslot":[{"from":"10:00","to":"22:00"}]}]
        // - Format 2: [{"timeslot":[{"from":"07:00","to":"11:00"},{"from":"12:00","to":"15:30"}],"day":"Monday"}]
        $workingHours = null;
        if (!empty($restaurant->workingHours)) {
            if (is_string($restaurant->workingHours)) {
                $decoded = json_decode($restaurant->workingHours, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $workingHours = $decoded;
                }
            } elseif (is_array($restaurant->workingHours)) {
                $workingHours = $restaurant->workingHours;
            }
        }

        // CRITICAL: Get isOpen flag - handle multiple possible values
        // Logic: NULL/not set = not manually closed (default true, check working hours)
        //        false/0 = manually closed (always closed)
        //        true/1 = not manually closed (check working hours)
        // IMPORTANT: Laravel's boolean cast converts NULL to false, so we need raw DB value
        $isOpenFlag = true; // Default: not manually closed, allow working hours check

        // Get raw value from database (before boolean cast)
        // Laravel's boolean cast converts NULL to false, so we need raw DB value
        $rawValue = null;

        if ($restaurant instanceof \Illuminate\Database\Eloquent\Model) {
            // Get raw value from database (before boolean casting)
            // Use getRawOriginal() to bypass Laravel's boolean cast
            // This is critical because the Vendor model casts isOpen to boolean
            if (method_exists($restaurant, 'getRawOriginal')) {
                $rawValue = $restaurant->getRawOriginal('isOpen');
                if ($rawValue === null) {
                    $rawValue = $restaurant->getRawOriginal('is_open');
                }
            }

            // Fallback to getAttributes() if getRawOriginal doesn't work
            if ($rawValue === null) {
                $attributes = $restaurant->getAttributes();
                if (array_key_exists('isOpen', $attributes)) {
                    $rawValue = $attributes['isOpen'];
                } elseif (array_key_exists('is_open', $attributes)) {
                    $rawValue = $attributes['is_open'];
                }
            }
        } else {
            // Fallback for stdClass objects from raw DB queries
            if (property_exists($restaurant, 'isOpen')) {
                $rawValue = $restaurant->isOpen;
            } elseif (property_exists($restaurant, 'is_open')) {
                $rawValue = $restaurant->is_open;
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
            'id' => $restaurant->id,
            'title' => $restaurant->title ?? '',
            'zoneId' => $restaurant->zoneId ?? '',
            'latitude' => (float) $restaurant->latitude,
            'longitude' => (float) $restaurant->longitude,
            'distance' => round($restaurant->distance ?? 0, 2),
            'vType' => $restaurant->vType ?? 'restaurant',
            'isActive' => (bool) ($restaurant->publish ?? true),
            'isOpen' => $actualIsOpen, // This is the calculated status
            'subscriptionPlan' => $subscriptionPlan,
            'author' => $restaurant->author,
            'subscriptionTotalOrders' => $subscriptionTotalOrders,
            'subscriptionExpiryDate' => $subscriptionExpiryDate,
            'reviewsCount' => (int) ($restaurant->reviewsCount ?? 0),
            'reviewsSum' => (float) ($restaurant->reviewsSum ?? 0),
            'reviewsAverage' => $reviewsAverage,
            'workingHours' => $workingHours,
            'restaurantCost' => $restaurant->restaurantCost ?? $restaurant->DeliveryCharge ?? '0',
            'createdAt' => $restaurant->createdAt ?? $restaurant->created_at ?? now()->toISOString(),
            'photo' => $restaurant->photo ?? $restaurant->categoryPhoto ?? $restaurant->photos ?? '',
            'location' => $restaurant->location ?? '',
            'enabledDiveInFuture' => (bool) ($restaurant->enabledDiveInFuture ?? false),
            'description' => $restaurant->description ?? '',
            'phonenumber' => $restaurant->phonenumber ?? '',
            'adminCommission' => $restaurant->adminCommission ?? 0,
            'specialDiscountEnable' => (bool) ($restaurant->specialDiscountEnable ?? false),
        ];
    }


    /**
     * Get Restaurant by ID
     * GET /api/restaurants/{id}
     */
    public function show($id)
    {
        try {
            $restaurant = Vendor::find($id);

            if (!$restaurant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Restaurant not found'
                ], 404);
            }

            // OPTIMIZATION: Batch fetch subscriptions (even for single restaurant for consistency)
            $subscriptionsMap = $this->batchFetchSubscriptions([$restaurant->id]);

            return response()->json([
                'success' => true,
                'data' => $this->formatRestaurantResponse($restaurant, null, $subscriptionsMap)
            ]);

        } catch (\Exception $e) {
            Log::error('Get Restaurant Error: ' . $e->getMessage(), ['id' => $id]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch restaurant',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get Restaurants by Zone
     * GET /api/restaurants/by-zone/{zone_id}
     */
    public function byZone($zoneId)
    {
        try {
            $restaurants = Vendor::where('zoneId', $zoneId)
                ->where(function($q) {
                    // Treat NULL and TRUE as published, only FALSE as not published
                    $q->where('publish', true)->orWhereNull('publish');
                })
                ->get();

            // OPTIMIZATION: Batch fetch subscriptions
            $subscriptionsMap = $this->batchFetchSubscriptions($restaurants->pluck('id')->toArray());

            $data = $restaurants->lazy()->map(function ($restaurant) use ($subscriptionsMap) {
                return $this->formatRestaurantResponse($restaurant, null, $subscriptionsMap);
            });

            // Count restaurants where isOpen is true
            $openCount = $data->filter(fn ($restaurant) => isset($restaurant['isOpen']) && $restaurant['isOpen'] === true)->count();

            return response()->json([
                'success' => true,
                'data' => $data,
                'count' => $data->count(),
                'openCount' => $openCount
            ]);

        } catch (\Exception $e) {
            Log::error('Get Restaurants by Zone Error: ' . $e->getMessage(), ['zone_id' => $zoneId]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch restaurants',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Search Restaurants
     * GET /api/restaurants/search
     */
    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:2',
            'zone_id' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = Vendor::where(function($q) {
                    // Treat NULL and TRUE as published, only FALSE as not published
                    $q->where('publish', true)->orWhereNull('publish');
                })
                ->where(function($q) use ($request) {
                    $searchTerm = $request->input('query');
                    $q->where('title', 'like', "%{$searchTerm}%")
                      ->orWhere('description', 'like', "%{$searchTerm}%")
                      ->orWhere('location', 'like', "%{$searchTerm}%");
                });

            // Filter by zone if provided
            if ($request->has('zone_id')) {
                $query->where('zoneId', $request->input('zone_id'));
            }

            // Add distance calculation if lat/lon provided
            if ($request->has('latitude') && $request->has('longitude')) {
                $lat = $request->input('latitude');
                $lon = $request->input('longitude');

                $query->selectRaw(
                    'vendors.*, (6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) AS distance',
                    [$lat, $lon, $lat]
                )->orderBy('distance', 'asc');
            } else {
                $query->orderBy('title', 'asc');
            }

            $restaurants = $query->get();

            // OPTIMIZATION: Batch fetch subscriptions
            $subscriptionsMap = $this->batchFetchSubscriptions($restaurants->pluck('id')->toArray());

            $data = $restaurants->lazy()->map(function ($restaurant) use ($subscriptionsMap) {
                return $this->formatRestaurantResponse($restaurant, null, $subscriptionsMap);
            });

            // Count restaurants where isOpen is true
            $openCount = $data->filter(fn ($restaurant) => isset($restaurant['isOpen']) && $restaurant['isOpen'] === true)->count();

            return response()->json([
                'success' => true,
                'data' => $data,
                'count' => $data->count(),
                'openCount' => $openCount
            ]);

        } catch (\Exception $e) {
            Log::error('Search Restaurants Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to search restaurants',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
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

    /**
     * Generate cache key for best restaurants (zone-only)
     */
    protected function generateBestRestaurantsCacheKey(string $zoneId): string
    {
        return "best_restaurants_{$zoneId}";
    }

    /**
     * Generate a unique cache key for nearest restaurants query
     * Rounds coordinates to reduce cache fragmentation while maintaining accuracy
     *
     * @param string $zoneId
     * @param float $latitude
     * @param float $longitude
     * @param float|null $radius
     * @param bool $isDining
     * @param string $filter
     * @return string
     */
    protected function generateNearestRestaurantsCacheKey(
        string $zoneId,
        float $latitude,
        float $longitude,
        ?float $radius,
        bool $isDining,
        string $filter
    ): string {
        // Round coordinates to 3 decimal places (~111 meters accuracy)
        // This reduces cache fragmentation while maintaining reasonable accuracy
        $roundedLat = round($latitude, 3);
        $roundedLon = round($longitude, 3);
        $roundedRadius = $radius !== null ? round($radius, 1) : 'null';

        // Create hash of all parameters
        $paramsHash = md5(json_encode([
            'zone_id' => $zoneId,
            'lat' => $roundedLat,
            'lon' => $roundedLon,
            'radius' => $roundedRadius,
            'is_dining' => $isDining,
            'filter' => $filter,
        ]));

        return "nearest_restaurants_{$zoneId}_{$paramsHash}";
    }

    /**
     * Clear cache for best restaurants (zone-level)
     */
    public function clearBestRestaurantsCache(?string $zoneId = null): bool
    {
        try {
            if ($zoneId) {
                Cache::forget($this->generateBestRestaurantsCacheKey($zoneId));
            }
            Log::info('Best restaurants cache cleared', ['zone_id' => $zoneId]);
            return true;
        } catch (\Throwable $e) {
            Log::error('Error clearing best restaurants cache', ['zone_id' => $zoneId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Clear cache for nearest restaurants queries
     * Note: For immediate refresh, use ?refresh=true parameter in the API call.
     *
     * @param string|null $zoneId Optional zone ID to clear specific zone cache
     * @return bool
     */
    public function clearNearestRestaurantsCache(?string $zoneId = null): bool
    {
        try {
            // Note: Laravel cache doesn't support pattern-based deletion by default
            // Cache will expire naturally after TTL (5 minutes)
            // For immediate refresh, use ?refresh=true in API call

            Log::info('Nearest restaurants cache clear requested', [
                'zone_id' => $zoneId,
                'note' => 'Use ?refresh=true in API call for immediate refresh',
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Error clearing nearest restaurants cache', [
                'zone_id' => $zoneId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}





