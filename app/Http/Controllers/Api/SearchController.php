<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


class SearchController extends Controller
{
    /**
     * Constructor
     */
//    public function __construct()
//    {
//        // Only apply location check for web routes, not API routes
//        if (!request()->is('api/*') && !isset($_COOKIE['address_name'])) {
//            \Redirect::to('set-location')->send();
//        }
//    }

    public function index()
    {
        return view('search.search');
    }


    /****************************************
     * SEARCH CATEGORIES API – SQL VERSION
     ****************************************/
    public function searchCategories(Request $request): JsonResponse
    {
        $startTime = microtime(true);

        try {
            $request->validate([
                'q' => 'nullable|string|max:100',
                'page' => 'nullable|integer|min:1|max:100',
                'limit' => 'nullable|integer|min:1|max:50'
            ]);

            $searchTerm = $request->input('q', '');
            $page = $request->input('page', 1);
            $limit = $request->input('limit', 20);
            $offset = ($page - 1) * $limit;

            $query = DB::table('mart_categories');

            if (!empty($searchTerm)) {
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('title', 'LIKE', "%$searchTerm%")
                        ->orWhere('description', 'LIKE', "%$searchTerm%");
                });
            }

            $total = $query->count();

            $data = $query->orderBy('category_order')
                ->offset($offset)
                ->limit($limit)
                ->get();

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            return response()->json([
                'success' => true,
                'message' => 'Categories retrieved successfully',
                'data' => $data,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => $total,
                    'has_more' => ($offset + $limit) < $total
                ],
                'search_term' => $searchTerm,
                'response_time_ms' => $responseTime
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Category SQL search error: '.$e->getMessage());

            return response()->json([
                'success' => true,
                'message' => 'Categories retrieved with fallback',
                'data' => $this->getFallbackResponse($searchTerm, $limit, $offset)['data'],
                'fallback' => true
            ], 200);
        }
    }


    /****************************************
     * GET PUBLISHED CATEGORIES – SQL VERSION
     ****************************************/
// public function getPublishedCategories(Request $request): JsonResponse
// {
//     try {
//         $request->validate([
//             'limit' => 'nullable|integer|min:1|max:100'
//         ]);

//         $limit = $request->input('limit', 50);

//         $categories = DB::table('mart_categories')
//             ->where('publish', 1)
//             ->orderBy('category_order')
//             ->limit($limit)
//             ->get();

//         return response()->json([
//             'success' => true,
//             'message' => 'Published categories retrieved successfully',
//             'data' => $categories,
//             'count' => $categories->count()
//         ], 200);

//     } catch (\Exception $e) {
//         \Log::error('Published category error: ' . $e->getMessage());

//         return response()->json([
//             'success' => false,
//             'message' => 'Error fetching published categories',
//             'data' => []
//         ], 500);
//     }
// }

/**
 * ENHANCED SEARCH MART ITEMS
 * - Supports flexible word matching (any word order)
 * - Handles compound searches like "2kg onion" or "onion 2kg"
 * - Better relevance scoring
 */
public function searchMartItems(Request $request): JsonResponse
{
    $startTime = microtime(true);

    try {
        $request->validate([
            'search' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'subcategory' => 'nullable|string|max:100',
            'vendor' => 'nullable|string|max:100',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'veg' => 'nullable|boolean',
            'isAvailable' => 'nullable|boolean',
            'isBestSeller' => 'nullable|boolean',
            'isFeature' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100'
        ]);

        $page = $request->get('page', 1);
        $limit = $request->get('limit', 20);
        $offset = ($page - 1) * $limit;

        $filters = $request->only([
            'search', 'category', 'subcategory', 'vendor',
            'min_price', 'max_price', 'veg', 'isAvailable',
            'isBestSeller', 'isFeature'
        ]);

        $query = DB::table('mart_items')->where('publish', 1);

        // --------------------------------------------------
        // ENHANCED SEARCH - Word-by-word matching
        // --------------------------------------------------
        if (!empty($filters['search'])) {
            $searchTerm = trim($filters['search']);

            // Split search term into individual words
            $words = preg_split('/\s+/', $searchTerm);

            $query->where(function($q) use ($searchTerm, $words) {
                // Exact phrase match (highest priority)
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('description', 'LIKE', "%{$searchTerm}%");

                if (Schema::hasColumn('mart_items', 'keywords')) {
                    $q->orWhere('keywords', 'LIKE', "%{$searchTerm}%");
                }

                // Individual word matching (any order)
                // This handles "2kg onion" and "onion 2kg" equally
                foreach ($words as $word) {
                    if (strlen($word) >= 2) { // Skip very short words
                        $q->orWhere('name', 'LIKE', "%{$word}%")
                          ->orWhere('description', 'LIKE', "%{$word}%");

                        if (Schema::hasColumn('mart_items', 'keywords')) {
                            $q->orWhere('keywords', 'LIKE', "%{$word}%");
                        }
                    }
                }
            });
        }

        // Category filters
        if (!empty($filters['category'])) {
            $query->where('categoryTitle', 'LIKE', "%{$filters['category']}%");
        }

        if (!empty($filters['subcategory'])) {
            $query->where('subcategoryTitle', 'LIKE', "%{$filters['subcategory']}%");
        }

        if (!empty($filters['vendor'])) {
            $query->where('vendorTitle', 'LIKE', "%{$filters['vendor']}%");
        }

        // Price filters
        if (isset($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        // Boolean flags
        foreach (['veg', 'isAvailable', 'isBestSeller', 'isFeature'] as $flag) {
            if (isset($filters[$flag])) {
                $query->where($flag, $filters[$flag]);
            }
        }

        // --------------------------------------------------
        // ENHANCED RELEVANCE SCORING
        // --------------------------------------------------
        if (!empty($filters['search'])) {
            $searchTerm = trim($filters['search']);
            $words = preg_split('/\s+/', $searchTerm);

            // Build relevance score with parameterized queries
            $caseConditions = [];
            $bindings = [];

            // Exact match (score: 10)
            $caseConditions[] = "WHEN name = ? THEN 10";
            $bindings[] = $searchTerm;

            // Starts with exact phrase (score: 9)
            $caseConditions[] = "WHEN name LIKE ? THEN 9";
            $bindings[] = "{$searchTerm}%";

            // Contains exact phrase (score: 8)
            $caseConditions[] = "WHEN name LIKE ? THEN 8";
            $bindings[] = "%{$searchTerm}%";

            // All words present in name (score: 7)
            if (count($words) > 1) {
                $allWordsCondition = "WHEN " . implode(' AND ', array_fill(0, count($words), 'name LIKE ?')) . " THEN 7";
                $caseConditions[] = $allWordsCondition;
                foreach ($words as $word) {
                    $bindings[] = "%{$word}%";
                }
            }

            // Any word match (score: 5)
            foreach ($words as $word) {
                if (strlen($word) >= 2) {
                    $caseConditions[] = "WHEN name LIKE ? THEN 5";
                    $bindings[] = "%{$word}%";
                }
            }

            // Description matches (score: 3)
            $caseConditions[] = "WHEN description LIKE ? THEN 3";
            $bindings[] = "%{$searchTerm}%";

            // Default (score: 1)
            $caseConditions[] = "ELSE 1";

            $caseStatement = "CASE " . implode(" ", $caseConditions) . " END DESC";

            $query->orderByRaw($caseStatement, $bindings);
        }

        // Secondary ordering
        $query->orderByDesc('isBestSeller')
              ->orderByDesc('isFeature')
              ->orderByDesc('isAvailable')
              ->orderBy('name');

        // --------------------------------------------------
        // PAGINATION
        // --------------------------------------------------
        $total = $query->count();

        $data = $query->offset($offset)
                      ->limit($limit)
                      ->get();

        return response()->json([
            'success' => true,
            'message' => 'Mart items retrieved successfully',
            'data' => $data,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'total_pages' => ceil($total / $limit),
                'has_more' => ($offset + $limit) < $total
            ],
            'filters_applied' => $filters,
            'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
        ], 200);

    } catch (\Exception $e) {
        \Log::error("Mart search SQL error: " . $e->getMessage(), [
            'filters' => $filters ?? [],
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Error searching mart items',
            'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
        ], 500);
    }
}


    /****************************************
     * FEATURED ITEMS – SQL VERSION
     ****************************************/
    public function getFeaturedMartItems(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'type' => 'nullable|string|in:best_seller,trending,featured,new,spotlight',
                'limit' => 'nullable|integer|min:1|max:50'
            ]);

            $type = $request->get('type', 'featured');
            $limit = $request->get('limit', 20);

            $query = DB::table('mart_items')->where('isAvailable', 1);

            $types = [
                'best_seller' => 'isBestSeller',
                'trending' => 'isTrending',
                'featured' => 'isFeature',
                'new' => 'isNew',
                'spotlight' => 'isSpotlight'
            ];

            if (isset($types[$type])) {
                $query->where($types[$type], 1);
            }

            $data = $query->limit($limit)->get();

            return response()->json([
                'success' => true,
                'message' => ucfirst($type).' items retrieved successfully',
                'data' => $data,
                'type' => $type,
                'count' => count($data)
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Featured SQL error: '.$e->getMessage());

            return response()->json([
                'success' => true,
                'message' => 'Fallback featured items',
                'data' => [],
                'fallback' => true
            ], 200);
        }
    }


    /****************************************
     * FALLBACK HELPERS
     ****************************************/
    private function getFallbackResponse($searchTerm, $limit, $offset)
    {
        $fallbackData = [
            ['id'=>'fallback_1','title'=>'Groceries'],
            ['id'=>'fallback_2','title'=>'Medicine'],
            ['id'=>'fallback_3','title'=>'Pet Care']
        ];

        return [
            'data' => array_slice($fallbackData, $offset, $limit),
            'pagination' => [
                'current_page' => 1,
                'total' => count($fallbackData),
                'has_more' => false
            ]
        ];
    }

    private function getFallbackMartItemsResponse(Request $request): array
    {
        return [[
            'id' => 'fallback_item_1',
            'name' => 'Fresh Orange Juice',
            'price' => 120,
            'disPrice' => 110
        ]];
    }


    /****************************************
     * HEALTH CHECK
     ****************************************/
    public function healthCheck(Request $request): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            return response()->json(['status'=>'healthy'],200);
        } catch (\Exception $e) {
            return response()->json(['status'=>'unhealthy'],200);
        }
    }
}
