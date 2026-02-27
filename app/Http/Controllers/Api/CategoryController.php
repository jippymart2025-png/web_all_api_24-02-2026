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

            $cacheKey = 'categories_home_v1';
            $cacheTTL = 3600; // 1 hour (3600 seconds)

            $forceRefresh = $request?->boolean('refresh', false);

            // 🔄 If force refresh requested, clear cache first
            if ($forceRefresh) {
                Cache::forget($cacheKey);
            }

            return response()->json(
                Cache::remember($cacheKey, $cacheTTL, function () {

                    $categories = VendorCategory::query()
                        ->where('show_in_homepage', true)
                        ->where('publish', 1)
                        ->orderBy('title', 'asc')
                        ->get([
                            'id',
                            'title',
                            'photo',
                            'show_in_homepage',
                            'publish',
                            'description',
                            'vType'
                        ]);

                    $data = $categories->map(function ($category) {

                        // ✅ Remove "jippy" (case insensitive)
                        $cleanTitle = trim(
                            preg_replace('/\bjippy\b/i', '', $category->title ?? '')
                        );

                        return [
                            'id' => $category->id,
                            'title' => $cleanTitle,
                            'photo' => $category->photo ?? '',
                            'show_in_homepage' => (bool) $category->show_in_homepage,
                            'publish' => (bool) $category->publish,
                            'description' => $category->description ?? '',
                            'vType' => $category->vType ?? null,
                        ];
                    });

                    return [
                        'success' => true,
                        'data' => $data
                    ];
                })
            );

        } catch (\Throwable $e) {

            Log::error('Get Home Categories Error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch home categories',
                'error'   => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }    /**
     * Get All Categories
     * GET /api/categories
     * OPTIMIZED: Cached for 24 hours for fast loading
     */
    public function index(Request $request = null)
    {
        try {

            $cacheKey = 'categories_jippy_v1';
            $cacheTTL = 3600; // 24 hours

            $forceRefresh = $request && $request->boolean('refresh', false);

            // ✅ Return cache first (zero DB hit)
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            /** ---------------------------------------
             * DB Query (ONLY if cache miss)
             * ------------------------------------- */

            $categories = VendorCategory::where('publish', 1)
                ->where('title', 'LIKE', '%jippy%') // ✅ Fetch only jippy titles
//                ->orderBy('title', 'asc')
                ->get();

            $data = $categories->map(function ($category) {

                // ✅ Remove "jippy" (case insensitive)
                $cleanTitle = trim(
                    preg_replace('/\bjippy\b/i', '', $category->title ?? '')
                );

                return [
                    'id' => $category->id,
                    'title' => $cleanTitle, // ✅ cleaned title
                    'photo' => $category->photo ?? '',
                    'show_in_homepage' => (bool) $category->show_in_homepage,
                    'publish' => (bool) $category->publish,
                    'description' => $category->description ?? '',
                    'vType' => $category->vType ?? null,
                ];
            });

            $response = [
                'success' => true,
                'data' => $data,
                'count' => $data->count()
            ];

            // ✅ Cache result
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);

        } catch (\Exception $e) {

            Log::error('Get Jippy Categories Error: ' . $e->getMessage());

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


