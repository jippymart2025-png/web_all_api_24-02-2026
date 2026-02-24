<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class CacheController extends Controller
{
    /**
     * Flush cache for product feeds
     * POST /api/cache/flush/products
     *
     * Query Parameters:
     * - vendor_id (optional): Clear cache for specific vendor
     * - all (optional): Clear all product feed caches (default: false)
     */
    public function flushProductCache(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'vendor_id' => 'nullable|string',
                'all' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $vendorId = $request->input('vendor_id');
            // Default to flushing all if no vendor_id is provided
            $flushAll = $request->boolean('all', !$vendorId);

            $productController = new \App\Http\Controllers\Api\ProductController();
            $cleared = 0;

            if ($flushAll || !$vendorId) {
                // Clear all product feed caches
                $cacheDriver = config('cache.default');

                try {
                    // Try to clear all cache (works with redis, memcached, file)
                    Cache::flush();
                    $cleared = -1; // -1 means all cache cleared

                    Log::info('All product cache flushed', [
                        'cache_driver' => $cacheDriver,
                    ]);
                } catch (\Exception $flushError) {
                    // For database cache, try to delete product feed and vendor products cache entries
                    if ($cacheDriver === 'database') {
                        try {
                            $deleted = DB::table('cache')
                                ->where(function ($query) {
                                    $query->where('key', 'like', 'product_feed_%')
                                          ->orWhere('key', 'like', 'vendor_products_v1_%');
                                })
                                ->delete();
                            $cleared = $deleted > 0 ? 1 : 0;
                            Log::info('Product cache entries deleted from database', [
                                'deleted_count' => $deleted,
                            ]);
                        } catch (\Exception $dbError) {
                            Log::error('Failed to delete product cache from database', [
                                'error' => $dbError->getMessage(),
                            ]);
                        }
                    } elseif ($cacheDriver === 'file') {
                        // For file cache, delete matching files
                        try {
                            $cachePath = config('cache.stores.file.path');
                            $files = array_merge(
                                glob("{$cachePath}/product_feed_*"),
                                glob("{$cachePath}/vendor_products_v1_*")
                            );
                            foreach ($files as $file) {
                                if (is_file($file)) {
                                    unlink($file);
                                    $cleared++;
                                }
                            }
                            if ($cleared > 0) {
                                Log::info('Product cache files deleted', ['count' => $cleared]);
                            }
                        } catch (\Exception $fileError) {
                            Log::error('Failed to delete product cache files', [
                                'error' => $fileError->getMessage(),
                            ]);
                        }
                    }

                    if ($cleared === 0) {
                        return response()->json([
                            'success' => true,
                            'message' => 'Product feed cache will expire naturally (24 hours). Use ?refresh=true in API calls for immediate refresh.',
                            'note' => 'For immediate refresh, use ?refresh=true parameter in product feed API calls',
                            'cache_driver' => $cacheDriver,
                        ]);
                    }
                }
            } elseif ($vendorId) {
                // Clear cache for specific vendor (both product feed and vendor products)
                $cacheDriver = config('cache.default');
                $cleared = 0;

                // Clear product feed cache
                if ($productController->clearProductFeedCache($vendorId)) {
                    $cleared++;
                }

                // Clear vendor products cache
                $vendorProductsCacheKey = 'vendor_products_v1_' . md5($vendorId);
                if ($cacheDriver === 'database') {
                    try {
                        $deleted = DB::table('cache')
                            ->where('key', 'like', "vendor_products_v1_" . md5($vendorId) . "%")
                            ->orWhere('key', $vendorProductsCacheKey)
                            ->delete();
                        if ($deleted > 0) {
                            $cleared++;
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to delete vendor products cache from database', [
                            'vendor_id' => $vendorId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } elseif ($cacheDriver === 'file') {
                    try {
                        $cachePath = config('cache.stores.file.path');
                        $files = glob("{$cachePath}/vendor_products_v1_" . md5($vendorId) . "*");
                        foreach ($files as $file) {
                            if (is_file($file)) {
                                unlink($file);
                                $cleared++;
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to delete vendor products cache files', [
                            'vendor_id' => $vendorId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    // For Redis/Memcached
                    if (Cache::forget($vendorProductsCacheKey)) {
                        $cleared++;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => $cleared === -1
                    ? 'All product cache cleared successfully'
                    : ($cleared > 0
                        ? ($vendorId ? "Product feed cache cleared for vendor: {$vendorId}" : 'Product feed cache cleared successfully')
                        : 'No cache entries found to clear'),
                'cleared_count' => $cleared,
                'vendor_id' => $vendorId ?? 'all',
            ]);

        } catch (\Exception $e) {
            Log::error('Error flushing product cache: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to flush product cache',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Flush cache for nearest restaurants
     * POST /api/cache/flush/restaurants
     *
     * Query Parameters (all optional):
     * - zone_id (optional): Clear cache for specific zone
     * - all (optional): Clear all restaurant caches (default: true if no zone_id)
     */
    public function flushRestaurantCache(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
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

            $zoneId = $request->input('zone_id');
            $flushAll = $request->boolean('all', !$zoneId); // Default to true if no zone_id

            $restaurantController = new RestaurantController();
            $cleared = 0;

            if ($flushAll || !$zoneId) {
                // Clear all restaurant caches - try to clear cache entries matching pattern
                $cacheDriver = config('cache.default');

                try {
                    // Try to clear all cache (works with redis, memcached, file)
                    Cache::flush();
                    $cleared = -1; // -1 means all cache cleared

                    Log::info('All restaurant cache flushed', [
                        'cache_driver' => $cacheDriver,
                    ]);
                } catch (\Exception $flushError) {
                    // For database cache, try to truncate
                    if ($cacheDriver === 'database') {
                        try {
                            DB::table('cache')->where('key', 'like', 'nearest_restaurants_%')->delete();
                            $cleared = 1;
                            Log::info('Restaurant cache entries deleted from database');
                        } catch (\Exception $dbError) {
                            Log::error('Failed to delete restaurant cache from database', [
                                'error' => $dbError->getMessage(),
                            ]);
                        }
                    }

                    if ($cleared === 0) {
                        return response()->json([
                            'success' => true,
                            'message' => 'Restaurant cache will expire naturally (24 hours). Use ?refresh=true in API calls for immediate refresh.',
                            'note' => 'For immediate refresh, use ?refresh=true parameter in restaurant API calls',
                            'cache_driver' => $cacheDriver,
                        ]);
                    }
                }
            } elseif ($zoneId) {
                // Clear cache for specific zone
                $cleared = $restaurantController->clearNearestRestaurantsCache($zoneId) ? 1 : 0;
            }

            return response()->json([
                'success' => true,
                'message' => $cleared === -1
                    ? 'All restaurant cache cleared successfully'
                    : ($cleared > 0
                        ? ($zoneId ? "Restaurant cache cleared for zone: {$zoneId}" : 'Restaurant cache cleared successfully')
                        : 'No cache entries found to clear'),
                'cleared_count' => $cleared,
                'zone_id' => $zoneId ?? 'all',
            ]);

        } catch (\Exception $e) {
            Log::error('Error flushing restaurant cache: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to flush restaurant cache',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Flush all cache (products + restaurants)
     * POST /api/cache/flush/all
     *
     * This endpoint clears all cache regardless of driver type.
     * For database/file cache, it attempts to clear cache entries.
     */
    public function flushAllCache(Request $request)
    {
        try {
            $cacheDriver = config('cache.default');
            $cleared = false;

            try {
                // Attempt to flush cache - works with all drivers
                Cache::flush();
                $cleared = true;

                Log::info('All cache flushed successfully', [
                    'cache_driver' => $cacheDriver,
                ]);
            } catch (\Exception $flushError) {
                // If flush fails, try alternative method for database/file cache
                Log::warning('Cache::flush() failed, trying alternative method', [
                    'cache_driver' => $cacheDriver,
                    'error' => $flushError->getMessage(),
                ]);

                // For database cache, we can try to clear the cache table
                if ($cacheDriver === 'database') {
                    try {
                        DB::table('cache')->truncate();
                        $cleared = true;
                        Log::info('Database cache table truncated');
                    } catch (\Exception $dbError) {
                        Log::error('Failed to truncate cache table', [
                            'error' => $dbError->getMessage(),
                        ]);
                    }
                }
            }

            if ($cleared) {
                // Also clear settings, categories, and menu items cache
                try {
                    Cache::forget('mobile_settings_v1');
                    Cache::forget('delivery_charge_settings_v1');
                    Cache::forget('categories_home_v1');
                    Cache::forget('categories_all_v1');
                } catch (\Exception $e) {
                    // Ignore if cache clear fails
                }

                // Clear menu items cache by pattern
                try {
                    if ($cacheDriver === 'database') {
                        DB::table('cache')->where('key', 'like', 'menu_items_%')->delete();
                    } elseif ($cacheDriver === 'file') {
                        $cachePath = storage_path('framework/cache/data');
                        if (is_dir($cachePath)) {
                            $files = glob($cachePath . '/*');
                            foreach ($files as $file) {
                                if (is_file($file)) {
                                    $content = file_get_contents($file);
                                    if (strpos($content, 'menu_items_') !== false) {
                                        unlink($file);
                                    }
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Ignore if menu items cache clear fails
                }

                return response()->json([
                    'success' => true,
                    'message' => 'All cache cleared successfully',
                    'cleared' => ['products', 'restaurants', 'settings', 'categories', 'menu_items', 'all_other_cache'],
                    'cache_driver' => $cacheDriver,
                ]);
            } else {
                return response()->json([
                    'success' => true,
                    'message' => 'Cache flush attempted. For immediate refresh, use ?refresh=true in API calls.',
                    'note' => 'Cache will expire naturally after 24 hours. Use ?refresh=true parameter in API calls for immediate refresh.',
                    'cache_driver' => $cacheDriver,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error flushing all cache: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to flush cache',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Flush cache for mobile settings
     * POST /api/cache/flush/settings
     */
    public function flushSettingsCache(Request $request)
    {
        try {
            $cacheKeys = [
                'mobile_settings_v1',
                'delivery_charge_settings_v1',
            ];

            $cleared = 0;
            foreach ($cacheKeys as $key) {
                if (Cache::forget($key)) {
                    $cleared++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => $cleared > 0
                    ? "Settings cache cleared successfully ({$cleared} keys)"
                    : 'No settings cache entries found to clear',
                'cleared_count' => $cleared,
                'cleared_keys' => $cacheKeys,
            ]);

        } catch (\Exception $e) {
            Log::error('Error flushing settings cache: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to flush settings cache',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Flush cache for categories
     * POST /api/cache/flush/categories
     */
    public function flushCategoryCache(Request $request)
    {
        try {
            $cacheKeys = [
                'categories_home_v1',
                'categories_all_v1',
            ];

            $cleared = 0;
            foreach ($cacheKeys as $key) {
                if (Cache::forget($key)) {
                    $cleared++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => $cleared > 0
                    ? "Category cache cleared successfully ({$cleared} keys)"
                    : 'No category cache entries found to clear',
                'cleared_count' => $cleared,
                'cleared_keys' => $cacheKeys,
            ]);

        } catch (\Exception $e) {
            Log::error('Error flushing category cache: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to flush category cache',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Flush cache for menu item banners
     * POST /api/cache/flush/menu-items
     */
    public function flushMenuItemsCache(Request $request)
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
            $flushAll = $request->boolean('all', $position === 'all' && !$zoneId);

            $cleared = 0;
            $cacheDriver = config('cache.default');
            $cachePrefix = config('cache.prefix', '');
            $menuItemController = new \App\Http\Controllers\Api\MenuItemBannerController();

            if ($flushAll || ($position === 'all' && !$zoneId)) {
                // Clear all menu item caches
                try {
                    if ($cacheDriver === 'database') {
                        $pattern = $cachePrefix ? "{$cachePrefix}_menu_items_%" : "menu_items_%";
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
                                        // Check if this file contains menu_items cache
                                        if (strpos($content, 'menu_items_') !== false) {
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
                        $positions = ['top', 'middle', 'bottom', 'all'];
                        $commonZones = [null, '', 'all'];
                        foreach ($positions as $pos) {
                            foreach ($commonZones as $zone) {
                                $cacheKey = $menuItemController->generateMenuItemsCacheKey($pos, $zone);
                                if (Cache::forget($cacheKey)) {
                                    $cleared++;
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Error clearing menu items cache', [
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                // Clear specific cache entries
                if ($position && $zoneId && $position !== 'all') {
                    // Clear cache for specific position and zone
                    $cacheKey = $menuItemController->generateMenuItemsCacheKey($position, $zoneId);
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
                                        if (strpos($content, "menu_items_{$position}_") !== false) {
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
                        // For Redis/Memcached, try to clear common zone combinations
                        $commonZones = ['all', null, ''];
                        foreach ($commonZones as $zone) {
                            $cacheKey = $menuItemController->generateMenuItemsCacheKey($position, $zone);
                            if (Cache::forget($cacheKey)) {
                                $cleared++;
                            }
                        }
                    }
                } elseif ($zoneId) {
                    // Clear cache for specific zone (all positions)
                    $positions = ['top', 'middle', 'bottom', 'all'];
                    foreach ($positions as $pos) {
                        $cacheKey = $menuItemController->generateMenuItemsCacheKey($pos, $zoneId);
                        if (Cache::forget($cacheKey)) {
                            $cleared++;
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => $cleared === -1
                    ? 'All menu items cache cleared successfully'
                    : ($cleared > 0
                        ? "Menu items cache cleared successfully ({$cleared} keys)"
                        : 'No menu items cache entries found to clear'),
                'cleared_count' => $cleared,
                'position' => $position,
                'zone_id' => $zoneId ?? 'all',
            ]);

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
     * Get cache statistics
     * GET /api/cache/stats
     */
    public function getCacheStats(Request $request)
    {
        try {
            $cacheDriver = config('cache.default');

            $stats = [
                'cache_driver' => $cacheDriver,
                'cache_prefix' => config('cache.prefix'),
                'note' => 'Cache statistics may vary by driver',
            ];

            // Try to get some cache info (driver-dependent)
            // Note: Redis-specific stats require direct Redis connection
            // This is simplified to avoid driver-specific implementation details

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting cache stats: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get cache statistics',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}

