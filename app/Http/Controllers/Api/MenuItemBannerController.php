<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MenuItemBannerController extends Controller
{
    /**
     * Get Top Menu Item Banners
     * GET /api/menu-items/banners/top
     *
     * Purpose: Get top position menu item banners for home page
     *
     * Query Parameters:
     * - zone_id (optional): Filter banners by zone
     *
     * Business Logic:
     * 1. Filter where is_publish = true AND position = "top"
     * 2. Zone Filtering:
     *    - If banner has no zoneId or empty → show to all zones
     *    - If user zone matches banner zoneId → show
     *    - If user zone is null → show all (fallback)
     * 3. Order by set_order ASC
     */
    public function top(Request $request)
    {
        return $this->getMenuItemsByPosition($request, 'top');
    }

    public function middle(Request $request)
    {
        return $this->getMenuItemsByPosition($request, 'middle');
    }

    public function bottom(Request $request)
    {
        return $this->getMenuItemsByPosition($request, 'bottom');
    }

    public function deals(Request $request)
    {
        return $this->getMenuItemsByPosition($request, 'deals');
    }


    /**
     * Common function to fetch menu items by position
     * OPTIMIZED: Cached for 24 hours for fast loading
     */
    private function getMenuItemsByPosition(Request $request, string $position)
    {
        // Validate optional zone_id parameter
        $validator = Validator::make($request->all(), [
            'zone_id' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $zoneId = $request->input('zone_id');

        try {
            /** ---------------------------------------
             * CACHE: Check cache FIRST - before any DB operations
             * This ensures zero database hits when cache exists
             * ------------------------------------- */
            $cacheKey = $this->generateMenuItemsCacheKey($position, $zoneId);
            $cacheTTL = 3600; // 1 hour (3600 seconds)

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
            // Base query: published menu items with given position
            $query = MenuItem::where('is_publish', true)
                ->where('position', $position);

            // Apply zone filtering logic
            if ($zoneId) {
                $query->where(function ($q) use ($zoneId) {
                    $q->where('zoneId', $zoneId)
                        ->orWhereNull('zoneId')
                        ->orWhere('zoneId', '');
                });
            }

            // Order by set_order
            $query->orderBy('set_order', 'asc');

            // Get menu items
            $menuItems = $query->get();

            // Format response
            $data = $menuItems->map(function ($item) {
                return [
                    'id' => $item->id,
                    'title' => $item->title ?? '',
                    'photo' => $item->photo ?? '',
                    'position' => $item->position ?? '',
                    'is_publish' => (bool) $item->is_publish,
                    'set_order' => (int) ($item->set_order ?? 0),
                    'zoneId' => $item->zoneId ?? null,
                    'zoneTitle' => $item->zoneTitle ?? null,
                    'redirect_type' => $item->redirect_type ?? null,
                    'redirect_id' => $item->redirect_id ?? null,
                ];
            });

            /** ---------------------------------------
             * RESPONSE: Build and cache response
             * ------------------------------------- */
            $response = [
                'success' => true,
                'data' => $data
            ];

            // Cache the response
            try {
                Cache::put($cacheKey, $response, $cacheTTL);

                // Only log in debug mode to reduce production log noise
                if (config('app.debug')) {
                    Log::debug('Menu items cache created', [
                        'position' => $position,
                        'zone_id' => $zoneId,
                        'cache_key' => $cacheKey
                    ]);
                }
            } catch (\Throwable $cacheError) {
                // Log cache failures (important for production monitoring)
                Log::warning('Failed to cache menu items response', [
                    'position' => $position,
                    'zone_id' => $zoneId,
                    'error' => $cacheError->getMessage()
                ]);
                // Continue without caching if cache fails
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error("Get {$position} Menu Item Error: " . $e->getMessage(), [
                'zone_id' => $zoneId,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ]);

            return response()->json([
                'success' => false,
                'message' => "Failed to fetch {$position} menu item banners",
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get All Menu Item Banners
     * GET /api/menu-items/banners
     * OPTIMIZED: Cached for 24 hours for fast loading
     */
    public function index(Request $request)
    {
        try {
            /** ---------------------------------------
             * CACHE: Check cache FIRST - before any DB operations
             * This ensures zero database hits when cache exists
             * ------------------------------------- */
            $zoneId = $request->input('zone_id');
            $position = $request->input('position'); // optional filter by position

            $cacheKey = $this->generateMenuItemsCacheKey('all', $zoneId, $position);
            $cacheTTL = 86400; // 24 hours (86400 seconds)

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
            $query = MenuItem::where('is_publish', true);

            // Filter by position if provided
            if ($position) {
                $query->where('position', $position);
            }

            // Apply zone filtering
            if ($zoneId) {
                $query->where(function($q) use ($zoneId) {
                    $q->where('zoneId', $zoneId)
                      ->orWhereNull('zoneId')
                      ->orWhere('zoneId', '');
                });
            }

            $query->orderBy('set_order', 'asc');
            $banners = $query->get();

            $data = $banners->map(function ($banner) {
                return [
                    'id' => $banner->id,
                    'title' => $banner->title ?? '',
                    'photo' => $banner->photo ?? '',
                    'position' => $banner->position ?? '',
                    'is_publish' => (bool) $banner->is_publish,
                    'set_order' => (int) ($banner->set_order ?? 0),
                    'zoneId' => $banner->zoneId ?? null,
                    'zoneTitle' => $banner->zoneTitle ?? null,
                    'redirect_type' => $banner->redirect_type ?? null,
                    'redirect_id' => $banner->redirect_id ?? null,
                ];
            });

            /** ---------------------------------------
             * RESPONSE: Build and cache response
             * ------------------------------------- */
            $response = [
                'success' => true,
                'data' => $data,
                'count' => $data->count()
            ];

            // Cache the response
            try {
                Cache::put($cacheKey, $response, $cacheTTL);
            } catch (\Throwable $cacheError) {
                Log::warning('Failed to cache menu items index response', [
                    'zone_id' => $zoneId,
                    'position' => $position,
                    'cache_key' => $cacheKey,
                    'error' => $cacheError->getMessage(),
                ]);
                // Continue without caching if cache fails
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Get Menu Item Banners Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch menu item banners',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get Single Menu Item Banner
     * GET /api/menu-items/banners/{id}
     */
    public function show($id)
    {
        try {
            $banner = MenuItem::find($id);

            if (!$banner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Menu item banner not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $banner->id,
                    'title' => $banner->title ?? '',
                    'photo' => $banner->photo ?? '',
                    'position' => $banner->position ?? '',
                    'is_publish' => (bool) $banner->is_publish,
                    'set_order' => (int) ($banner->set_order ?? 0),
                    'zoneId' => $banner->zoneId ?? null,
                    'zoneTitle' => $banner->zoneTitle ?? null,
                    'redirect_type' => $banner->redirect_type ?? null,
                    'redirect_id' => $banner->redirect_id ?? null,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get Menu Item Banner Error: ' . $e->getMessage(), ['id' => $id]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch menu item banner',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Generate a unique cache key for menu items based on position, zone, and filters
     *
     * @param string $position
     * @param string|null $zoneId
     * @param string|null $filterPosition
     * @return string
     */
    public function generateMenuItemsCacheKey(string $position, ?string $zoneId = null, ?string $filterPosition = null): string
    {
        // Create hash of all parameters
        $paramsHash = md5(json_encode([
            'position' => $position,
            'zone_id' => $zoneId ?? 'all',
            'filter_position' => $filterPosition ?? null,
        ]));

        return "menu_items_{$position}_{$paramsHash}";
    }

    /**
     * Flush cache for menu items
     * POST /api/menu-items/banners/flush-cache
     *
     * Query Parameters (all optional):
     * - position: Clear cache for specific position (top, middle, bottom, all)
     * - zone_id: Clear cache for specific zone
     * - all: Clear all menu items cache (default: true if no position/zone_id)
     */
    public function flushCache(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'position' => 'nullable|string|in:top,middle,bottom,all',
                'zone_id' => 'nullable|string',
                'all' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $position = $request->input('position', 'all');
            $zoneId = $request->input('zone_id');
            $flushAll = $request->boolean('all', !$position && !$zoneId);

            $cleared = 0;
            $cacheDriver = config('cache.default');
            $cachePrefix = config('cache.prefix', '');

            // Only log detailed info in debug mode
            if (config('app.debug')) {
                Log::debug('Flushing menu items cache', [
                    'position' => $position,
                    'zone_id' => $zoneId ?? 'all',
                    'cache_driver' => $cacheDriver
                ]);
            }

            if ($flushAll || ($position === 'all' && !$zoneId)) {
                // Clear all menu items cache
                // Try to clear by generating possible cache keys first (more reliable)
                $positions = ['top', 'middle', 'bottom', 'all'];
                $commonZones = [null, '', 'all'];

                foreach ($positions as $pos) {
                    foreach ($commonZones as $zone) {
                        $cacheKey = $this->generateMenuItemsCacheKey($pos, $zone);
                        if (Cache::forget($cacheKey)) {
                            $cleared++;
                        }
                    }
                }

                // Also try database/file specific clearing for any remaining keys
                try {
                    if ($cacheDriver === 'database') {
                        $pattern = $cachePrefix ? "{$cachePrefix}_menu_items_%" : "menu_items_%";
                        $deleted = DB::table('cache')
                            ->where('key', 'like', $pattern)
                            ->delete();
                        if ($deleted > $cleared) {
                            $cleared = $deleted;
                        }
                        if (config('app.debug')) {
                            Log::debug('Database cache cleared', ['deleted' => $deleted, 'cleared_via_forget' => $cleared]);
                        }
                    } elseif ($cacheDriver === 'file') {
                        // Get cache path from config
                        $cachePath = config('cache.stores.file.path', storage_path('framework/cache/data'));

                        if (is_dir($cachePath)) {
                            $files = glob($cachePath . '/*');
                            $totalFiles = count($files);
                            $foundKeys = [];

                            foreach ($files as $file) {
                                if (is_file($file)) {
                                    try {
                                        $content = file_get_contents($file);

                                        // Laravel file cache stores serialized data: a:2:{i:0;s:X:"key";i:1;...}
                                        // Extract all cache keys from the file
                                        $matches = [];
                                        preg_match_all('/s:\d+:"([^"]+)"/', $content, $matches);

                                        if (!empty($matches[1])) {
                                            foreach ($matches[1] as $cacheKey) {
                                                // Remove cache prefix if present for comparison
                                                $keyWithoutPrefix = $cachePrefix ? str_replace($cachePrefix . '_', '', $cacheKey) : $cacheKey;

                                                // Check if this is a menu_items cache key
                                                if (strpos($keyWithoutPrefix, 'menu_items_') === 0 || strpos($cacheKey, 'menu_items_') !== false) {
                                                    $foundKeys[] = $cacheKey;
                                                    unlink($file);
                                                    $cleared++;
                                                    break; // File deleted, move to next file
                                                }
                                            }
                                        } else {
                                            // Fallback: check raw content for menu_items_
                                            if (strpos($content, 'menu_items_') !== false) {
                                                $foundKeys[] = basename($file);
                                                unlink($file);
                                                $cleared++;
                                            }
                                        }
                                    } catch (\Exception $fileError) {
                                        // Skip files that can't be read
                                        Log::warning('Error reading cache file', [
                                            'file' => basename($file),
                                            'error' => $fileError->getMessage()
                                        ]);
                                    }
                                }
                            }

                            if (config('app.debug')) {
                                Log::debug('File cache cleared', [
                                    'files_deleted' => $cleared,
                                    'total_files_checked' => $totalFiles
                                ]);
                            }
                        } else {
                            Log::warning('Cache directory does not exist', ['path' => $cachePath]);
                        }
                    } else {
                        // For Redis/Memcached, try to clear menu_items_* keys
                        // Try common cache key patterns
                        $positions = ['top', 'middle', 'bottom', 'all'];
                        $commonZones = [null, '', 'all'];

                        foreach ($positions as $pos) {
                            foreach ($commonZones as $zone) {
                                $cacheKey = $this->generateMenuItemsCacheKey($pos, $zone);
                                if (Cache::forget($cacheKey)) {
                                    $cleared++;
                                }
                            }
                        }

                        // Also try to clear with various zone IDs if we can detect them
                        // For now, we'll clear the common patterns
                        if (config('app.debug') && $cleared > 0) {
                            Log::debug('Redis/Memcached cache cleared', ['keys_cleared' => $cleared]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Error clearing all menu items cache', [
                        'error' => $e->getMessage(),
                        'trace' => config('app.debug') ? $e->getTraceAsString() : null
                    ]);
                }
            } else {
                // Clear specific cache entries
                if ($position && $zoneId) {
                    // Clear cache for specific position and zone
                    $cacheKey = $this->generateMenuItemsCacheKey($position, $zoneId);
                    if (Cache::forget($cacheKey)) {
                        $cleared = 1;
                    }
                } elseif ($position && $position !== 'all') {
                    // Clear all cache keys for a specific position (all zones)
                    if ($cacheDriver === 'database') {
                        $pattern = $cachePrefix ? "{$cachePrefix}_menu_items_{$position}_%" : "menu_items_{$position}_%";
                        $deleted = DB::table('cache')
                            ->where('key', 'like', $pattern)
                            ->delete();
                        $cleared = $deleted;
                    } elseif ($cacheDriver === 'file') {
                        $cachePath = config('cache.stores.file.path', storage_path('framework/cache/data'));
                        if (is_dir($cachePath)) {
                            $files = glob($cachePath . '/*');
                            foreach ($files as $file) {
                                if (is_file($file)) {
                                    try {
                                        $content = file_get_contents($file);

                                        // Extract cache keys from serialized content
                                        $matches = [];
                                        preg_match_all('/s:\d+:"([^"]+)"/', $content, $matches);

                                        $shouldDelete = false;
                                        if (!empty($matches[1])) {
                                            foreach ($matches[1] as $cacheKey) {
                                                // Remove cache prefix if present
                                                $keyWithoutPrefix = $cachePrefix ? str_replace($cachePrefix . '_', '', $cacheKey) : $cacheKey;

                                                // Check if this matches the position pattern
                                                if (strpos($keyWithoutPrefix, "menu_items_{$position}_") === 0 ||
                                                    strpos($cacheKey, "menu_items_{$position}_") !== false) {
                                                    $shouldDelete = true;
                                                    break;
                                                }
                                            }
                                        } else {
                                            // Fallback: check raw content
                                            if (strpos($content, "menu_items_{$position}_") !== false) {
                                                $shouldDelete = true;
                                            }
                                        }

                                        if ($shouldDelete) {
                                            unlink($file);
                                            $cleared++;
                                        }
                                    } catch (\Exception $fileError) {
                                        // Skip files that can't be read
                                    }
                                }
                            }
                        }
                    } else {
                        // For Redis/Memcached, try to clear common combinations
                        $commonZones = ['all', null, ''];
                        foreach ($commonZones as $zone) {
                            $cacheKey = $this->generateMenuItemsCacheKey($position, $zone);
                            if (Cache::forget($cacheKey)) {
                                $cleared++;
                            }
                        }
                    }
                } elseif ($zoneId) {
                    // Clear cache for specific zone (all positions)
                    $positions = ['top', 'middle', 'bottom', 'all'];
                    foreach ($positions as $pos) {
                        $cacheKey = $this->generateMenuItemsCacheKey($pos, $zoneId);
                        if (Cache::forget($cacheKey)) {
                            $cleared++;
                        }
                    }
                }
            }

            // Log cache flush result (important for production monitoring)
            if ($cleared > 0) {
                Log::info('Menu items cache flushed', [
                    'position' => $position,
                    'zone_id' => $zoneId ?? 'all',
                    'cleared_count' => $cleared
                ]);
            }

            // If clearing all and no keys found, still return success (cache might not exist)
            $message = 'No menu items cache entries found to clear';
            if ($cleared > 0) {
                $message = "Menu items cache cleared successfully ({$cleared} keys)";
            } elseif ($flushAll || ($position === 'all' && !$zoneId)) {
                $message = 'Menu items cache cleared (or no cache entries existed)';
            }

            // For debugging: Try to find actual cache keys that exist
            $debugInfo = null;
            if ($cleared === 0 && config('app.debug')) {
                $debugInfo = $this->debugCacheKeys($cacheDriver, $cachePrefix);
            }

            $response = [
                'success' => true,
                'message' => $message,
                'cleared_count' => $cleared,
                'position' => $position,
                'zone_id' => $zoneId ?? 'all',
            ];

            // Only include debug info and technical details in debug mode
            if (config('app.debug')) {
                $response['cache_driver'] = $cacheDriver;
                $response['cache_prefix'] = $cachePrefix;
                $response['note'] = $cleared === 0 ? 'Cache may not exist or was already cleared. Next API call will refresh cache.' : null;
                $response['debug'] = $debugInfo;
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Error flushing menu items cache: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to flush menu items cache',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Debug method to find actual cache keys
     */
    private function debugCacheKeys(string $cacheDriver, string $cachePrefix): array
    {
        $foundKeys = [];
        $allKeys = [];
        $cachePath = null;
        $fileSamples = [];

        try {
            if ($cacheDriver === 'file') {
                $cachePath = config('cache.stores.file.path', storage_path('framework/cache/data'));
                if (is_dir($cachePath)) {
                    $files = glob($cachePath . '/*');
                    $fileCount = 0;

                    foreach ($files as $file) {
                        if (is_file($file) && $fileCount < 10) { // Limit to first 10 files
                            try {
                                $content = file_get_contents($file);
                                $fileSize = strlen($content);

                                // Try multiple methods to extract cache key
                                // Method 1: Look for serialized string patterns
                                preg_match_all('/s:\d+:"([^"]+)"/', $content, $matches);

                                // Method 2: Try to unserialize and extract
                                $unserialized = @unserialize($content);

                                // Method 3: Look for JSON if it's JSON encoded
                                $jsonData = @json_decode($content, true);

                                // Extract keys from matches
                                if (!empty($matches[1])) {
                                    foreach ($matches[1] as $key) {
                                        if (!in_array($key, $allKeys)) {
                                            $allKeys[] = $key;

                                            // Check both with and without prefix
                                            $keyWithoutPrefix = $cachePrefix ? str_replace($cachePrefix . '_', '', $key) : $key;

                                            if (strpos($key, 'menu_items_') !== false ||
                                                strpos($keyWithoutPrefix, 'menu_items_') === 0) {
                                                $foundKeys[] = $key;
                                            }
                                        }
                                    }
                                }

                                // Check unserialized data
                                if (is_array($unserialized) && isset($unserialized[0])) {
                                    $possibleKey = is_string($unserialized[0]) ? $unserialized[0] : null;
                                    if ($possibleKey && !in_array($possibleKey, $allKeys)) {
                                        $allKeys[] = $possibleKey;
                                        if (strpos($possibleKey, 'menu_items_') !== false) {
                                            $foundKeys[] = $possibleKey;
                                        }
                                    }
                                }

                                // Store sample file info (without exposing content for security)
                                if ($fileCount < 3) {
                                    $fileSamples[] = [
                                        'filename' => basename($file),
                                        'size' => $fileSize,
                                        'has_menu_items' => strpos($content, 'menu_items_') !== false
                                    ];
                                }

                                $fileCount++;
                            } catch (\Exception $e) {
                                Log::warning('Error reading cache file in debug', [
                                    'file' => basename($file),
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }
                    }
                }
            } elseif ($cacheDriver === 'database') {
                $keys = DB::table('cache')
                    ->where('key', 'like', '%menu_items_%')
                    ->pluck('key')
                    ->toArray();
                $foundKeys = $keys;
                $allKeys = DB::table('cache')->pluck('key')->take(20)->toArray();
            }
        } catch (\Exception $e) {
            Log::error('Debug cache keys error', ['error' => $e->getMessage()]);
        }

        return [
            'found_keys' => array_slice($foundKeys, 0, 10),
            'total_found' => count($foundKeys),
            'cache_prefix' => $cachePrefix,
            'cache_path' => $cachePath,
            'sample_all_keys' => array_slice($allKeys, 0, 5),
            'total_files' => $cacheDriver === 'file' && $cachePath ? count(glob($cachePath . '/*')) : null,
            'file_samples' => $fileSamples
        ];
    }
}

