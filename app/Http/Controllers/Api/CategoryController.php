<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VendorCategory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Get Home Page Categories
     * GET /api/categories/home
     * OPTIMIZED: Cached for 24 hours for fast loading
     *
     * Purpose: Get categories to display on home page
     *
     * Business Logic:
     * - Filter where show_in_homepage = true AND publish = true
     * - Order by display order (if available)
     */
    public function home(Request $request = null)
    {
        try {
            /** ---------------------------------------
             * CACHE: Check cache FIRST - before any DB operations
             * This ensures zero database hits when cache exists
             * ------------------------------------- */
            $cacheKey = 'categories_home_v1';
            $cacheTTL = 86400; // 24 hours (86400 seconds)

            // Check if force refresh is requested
            $forceRefresh = $request && $request->boolean('refresh', false);

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
            // Get categories for homepage
            $categories = VendorCategory::where('show_in_homepage', true)
                ->where('publish', 1)
                ->orderBy('title', 'asc') // Default order by title
                ->get();

            // Format response
            $data = $categories->map(function ($category) {
                return [
                    'id' => $category->id,
                    'title' => $category->title ?? '',
                    'photo' => $category->photo ?? '',
                    'show_in_homepage' => (bool) $category->show_in_homepage,
                    'publish' => (bool) $category->publish,
                    'description' => $category->description ?? '',
                    'vType' => $category->vType ?? null,
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
            } catch (\Throwable $cacheError) {
                Log::warning('Failed to cache home categories response', [
                    'cache_key' => $cacheKey,
                    'error' => $cacheError->getMessage(),
                ]);
                // Continue without caching if cache fails
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Get Home Categories Error: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch home categories',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get All Categories
     * GET /api/categories
     * OPTIMIZED: Cached for 24 hours for fast loading
     */
    public function index(Request $request = null)
    {
        try {
            /** ---------------------------------------
             * CACHE: Check cache FIRST - before any DB operations
             * This ensures zero database hits when cache exists
             * ------------------------------------- */
            $cacheKey = 'categories_all_v1';
            $cacheTTL = 86400; // 24 hours (86400 seconds)

            // Check if force refresh is requested
            $forceRefresh = $request && $request->boolean('refresh', false);

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
            $categories = VendorCategory::where('publish', 1)
                ->orderBy('title', 'asc')
                ->get();

            $data = $categories->map(function ($category) {
                return [
                    'id' => $category->id,
                    'title' => $category->title ?? '',
                    'photo' => $category->photo ?? '',
                    'show_in_homepage' => (bool) $category->show_in_homepage,
                    'publish' => (bool) $category->publish,
                    'description' => $category->description ?? '',
                    'vType' => $category->vType ?? null,
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
                Log::warning('Failed to cache all categories response', [
                    'cache_key' => $cacheKey,
                    'error' => $cacheError->getMessage(),
                ]);
                // Continue without caching if cache fails
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Get Categories Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch categories',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get Single Category
     * GET /api/categories/{id}
     */
//    public function show($id)
//    {
//        try {
//            $category = VendorCategory::find($id);
//
//            if (!$category) {
//                return response()->json([
//                    'success' => false,
//                    'message' => 'Category not found'
//                ], 404);
//            }
//
//            return response()->json([
//                'success' => true,
//                'data' => [
//                    'id' => $category->id,
//                    'title' => $category->title ?? '',
//                    'photo' => $category->photo ?? '',
//                    'show_in_homepage' => (bool) $category->show_in_homepage,
//                    'publish' => (bool) $category->publish,
//                    'description' => $category->description ?? '',
//                    'vType' => $category->vType ?? null,
//                ]
//            ]);
//
//        } catch (\Exception $e) {
//            Log::error('Get Category Error: ' . $e->getMessage(), ['id' => $id]);
//
//            return response()->json([
//                'success' => false,
//                'message' => 'Failed to fetch category',
//                'error' => config('app.debug') ? $e->getMessage() : null
//            ], 500);
//        }
//    }



}


