<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MartCategory;
use App\Models\MartSubcategory;
use App\Models\Vendor;
use Illuminate\Http\Request;
use App\Models\MartItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MartItemController extends Controller
{
    /**
     * Values that represent truthy booleans when stored in Firestore exports / SQL.
     *
     * @var array<int, mixed>
     */
    private array $truthyValues = [true, 1, '1', 'true', 'TRUE', 'True'];

    /**
     * Values that represent falsy booleans when stored in Firestore exports / SQL.
     *
     * @var array<int, mixed>
     */
    private array $falsyValues = [false, 0, '0', 'false', 'FALSE', 'False'];

    /**
     * Normalises an incoming limit value.
     */
    private function normalizeLimit(Request $request, int $default = 20, int $max = 100, string $key = 'limit'): int
    {
        $limit = (int) $request->get($key, $default);

        if ($limit <= 0) {
            $limit = $default;
        }

        if ($limit > $max) {
            $limit = $max;
        }

        return $limit;
    }

    /**
     * Determines whether a request value should be interpreted as boolean true/false.
     */
    private function interpretBoolean($value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (in_array($value, $this->truthyValues, true)) {
            return true;
        }

        if (in_array($value, $this->falsyValues, true)) {
            return false;
        }

        if (is_string($value)) {
            $valueLower = Str::lower($value);

            if (in_array($valueLower, ['true', '1'], true)) {
                return true;
            }

            if (in_array($valueLower, ['false', '0'], true)) {
                return false;
            }
        }

        return null;
    }

    public function getTrendingItems(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'limit' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $limit = (int) $request->get('limit', 20);
            if ($limit <= 0) {
                $limit = 20;
            }

            // Cache key based on request parameters
            $cacheKey = 'mart_items_trending_' . md5(json_encode(['limit' => $limit]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            // 🔥 Equivalent to Firestore query
            $items = MartItem::query()
                ->where('isTrending', [true, 1, '1', 'true'])
                ->where('isAvailable', [true, 1, '1', 'true'])
                ->where('publish', [true, 1, '1', 'true'])
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            $response = [
                'status' => true,
                'message' => 'Trending items fetched successfully',
                'count' => $items->count(),
                'data' => $items
            ];

            if ($items->isEmpty()) {
                $response = [
                    'status' => false,
                    'message' => 'No trending items found',
                    'data' => []
                ];
            }

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Failed to fetch trending mart items', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Error fetching trending items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getFeaturedItems(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'limit' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $limit = $this->normalizeLimit($request);

            // Cache key based on request parameters
            $cacheKey = 'mart_items_featured_' . md5(json_encode(['limit' => $limit]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            $items = MartItem::query()
                ->where('isFeature', $this->truthyValues)
                ->where('isAvailable', $this->truthyValues)
                ->where('publish', $this->truthyValues)
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            $response = [
                'status' => true,
                'message' => 'Featured items fetched successfully',
                'count' => $items->count(),
                'data' => $items
            ];

            if ($items->isEmpty()) {
                $response = [
                    'status' => false,
                    'message' => 'No featured items found',
                    'data' => []
                ];
            }

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Failed to fetch featured mart items', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Error fetching featured items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getItemsOnSale(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'limit' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $limit = $this->normalizeLimit($request);

            // Cache key based on request parameters
            $cacheKey = 'mart_items_on_sale_' . md5(json_encode(['limit' => $limit]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            $items = MartItem::query()
                ->where('isAvailable', $this->truthyValues)
                ->where('publish', $this->truthyValues)
                ->where(function ($query) {
                    $query->whereNotNull('disPrice')
                        ->where('disPrice', '>', 0);
                })
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->limit($limit * 3) // fetch extra to allow post-filtering
                ->get()
                ->filter(function (MartItem $item) {
                    $price = (int) $item->price;
                    $discountPrice = (int) $item->disPrice;

                    return $price > 0 && $discountPrice > 0 && $discountPrice < $price;
                })
                ->values()
                ->take($limit);

            $response = [
                'status' => true,
                'message' => 'Sale items fetched successfully',
                'count' => $items->count(),
                'data' => $items
            ];

            if ($items->isEmpty()) {
                $response = [
                    'status' => false,
                    'message' => 'No sale items found',
                    'data' => []
                ];
            }

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Failed to fetch sale mart items', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Error fetching sale items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function searchItems(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'searchQuery' => 'required|string|max:255',
                'limit' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $limit = $this->normalizeLimit($request);
            $searchQuery = trim($request->get('searchQuery'));
            $searchLower = Str::lower($searchQuery);

            // Cache key based on request parameters
            $cacheKey = 'mart_items_search_' . md5(json_encode(['searchQuery' => $searchLower, 'limit' => $limit]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            $items = MartItem::query()
                ->where('isAvailable', $this->truthyValues)
                ->where('publish', $this->truthyValues)
                ->where(function ($query) use ($searchLower) {
                    $query->whereRaw('LOWER(name) LIKE ?', ['%' . $searchLower . '%'])
                        ->orWhereRaw('LOWER(description) LIKE ?', ['%' . $searchLower . '%']);
                })
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            $response = [
                'status' => true,
                'message' => 'Items search completed successfully',
                'count' => $items->count(),
                'data' => $items
            ];

            if ($items->isEmpty()) {
                $response = [
                    'status' => false,
                    'message' => 'No items matched your search',
                    'data' => []
                ];
            }

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Failed to search mart items', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Error searching items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getItemsByCategory(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'categoryId' => 'required|string',
                'subcategoryId' => 'nullable|string',
                'search' => 'nullable|string|max:255',
                'isAvailable' => 'nullable',
                'limit' => 'nullable|integer|min:1|max:200',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $limit = $this->normalizeLimit($request, 100, 200);
            $categoryId = $request->get('categoryId');
            $subcategoryId = $request->get('subcategoryId');
            $search = Str::lower((string) $request->get('search', ''));
            $isAvailable = $this->interpretBoolean($request->get('isAvailable'));

            // Cache key based on request parameters
            $cacheKey = 'mart_items_by_category_' . md5(json_encode([
                'categoryId' => $categoryId,
                'subcategoryId' => $subcategoryId,
                'search' => $search,
                'isAvailable' => $isAvailable,
                'limit' => $limit
            ]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            $query = MartItem::query()
                ->where('publish', $this->truthyValues)
                ->where('categoryID', $categoryId);

            if ($isAvailable !== null) {
                $query->where(
                    'isAvailable',
                    $isAvailable ? $this->truthyValues : $this->falsyValues
                );
            }

            if (!empty($subcategoryId)) {
                $query->where(function ($subQuery) use ($subcategoryId) {
                    $subQuery->where('subcategoryID', $subcategoryId)
                        ->orWhere('subcategoryID', 'LIKE', '%"' . $subcategoryId . '"%')
                        ->orWhere('subcategoryID', 'LIKE', '%[' . $subcategoryId . ']%')
                        ->orWhere('subcategoryID', 'LIKE', '%|' . $subcategoryId . '|%')
                        ->orWhere('subcategoryID', 'LIKE', '%,' . $subcategoryId . ',%')
                        ->orWhere('subcategoryID', 'LIKE', '%' . $subcategoryId . '%');
                });
            }

            $items = $query
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            if ($search !== '') {
                $items = $items->filter(function (MartItem $item) use ($search) {
                    $name = Str::lower($item->name ?? '');
                    $description = Str::lower($item->description ?? '');

                    return Str::contains($name, $search) || Str::contains($description, $search);
                })->values();
            }

            $response = [
                'status' => true,
                'message' => 'Category items fetched successfully',
                'count' => $items->count(),
                'data' => $items
            ];

            if ($items->isEmpty()) {
                $response = [
                    'status' => false,
                    'message' => 'No items found for the requested category',
                    'data' => []
                ];
            }

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Failed to fetch mart items by category', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Error fetching category items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getItemsByCategoryOnly(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'categoryId' => 'required|string',
                'isAvailable' => 'nullable',
                'limit' => 'nullable|integer|min:1|max:200',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $limit = $this->normalizeLimit($request, 20, 200);
            $categoryId = $request->get('categoryId');
            $isAvailable = $this->interpretBoolean($request->get('isAvailable'));

            // Cache key based on request parameters
            $cacheKey = 'mart_items_by_category_only_' . md5(json_encode([
                'categoryId' => $categoryId,
                'isAvailable' => $isAvailable,
                'limit' => $limit
            ]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            $query = MartItem::query()
                ->where('publish', $this->truthyValues)
                ->where('categoryID', $categoryId);

            if ($isAvailable !== null) {
                $query->where(
                    'isAvailable',
                    $isAvailable ? $this->truthyValues : $this->falsyValues
                );
            }

            $items = $query
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            $response = [
                'status' => true,
                'message' => 'Category items fetched successfully',
                'count' => $items->count(),
                'data' => $items
            ];

            if ($items->isEmpty()) {
                $response = [
                    'status' => false,
                    'message' => 'No items found for the requested category',
                    'data' => []
                ];
            }

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Failed to fetch mart items by category only', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Error fetching category items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getItemsByVendor(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'vendorId' => 'required|string',
                'categoryId' => 'nullable|string',
                'isAvailable' => 'nullable',
                'limit' => 'nullable|integer|min:1|max:200',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $limit = $this->normalizeLimit($request, 20, 200);
            $vendorId = $request->get('vendorId');
            $categoryId = $request->get('categoryId');
            $isAvailable = $this->interpretBoolean($request->get('isAvailable'));

            // Cache key based on request parameters
            $cacheKey = 'mart_items_by_vendor_' . md5(json_encode([
                'vendorId' => $vendorId,
                'categoryId' => $categoryId,
                'isAvailable' => $isAvailable,
                'limit' => $limit
            ]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            $query = MartItem::query()
                ->where('publish', $this->truthyValues)
                ->where('vendorID', $vendorId);

            if (!empty($categoryId)) {
                $query->where('categoryID', $categoryId);
            }

            if ($isAvailable !== null) {
                $query->whereIn(
                    'isAvailable',
                    $isAvailable ? $this->truthyValues : $this->falsyValues
                );
            }

            $items = $query
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            $response = [
                'status' => true,
                'message' => 'Vendor items fetched successfully',
                'count' => $items->count(),
                'data' => $items
            ];

            if ($items->isEmpty()) {
                $response = [
                    'status' => false,
                    'message' => 'No items found for the requested vendor',
                    'data' => []
                ];
            }

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Failed to fetch mart items by vendor', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Error fetching vendor items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getItemsBySection(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'section' => 'required|string',
                'limit' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $limit = $this->normalizeLimit($request, 15, 100);
            $section = $request->get('section');

            // Cache key based on request parameters
            $cacheKey = 'mart_items_by_section_' . md5(json_encode(['section' => $section, 'limit' => $limit]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            $items = MartItem::query()
                ->where('publish', $this->truthyValues)
                ->where('section', $section)
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            $response = [
                'status' => true,
                'message' => 'Section items fetched successfully',
                'count' => $items->count(),
                'data' => $items
            ];

            if ($items->isEmpty()) {
                $response = [
                    'status' => false,
                    'message' => 'No items found for the requested section',
                    'data' => []
                ];
            }

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Failed to fetch mart items by section', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Error fetching section items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getMartItems(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'search' => 'nullable|string|max:255',
                'categoryID' => 'nullable|string|max:255',
                'subcategoryID' => 'nullable|string|max:255',
                'section' => 'nullable|string|max:255',
                'isTrending' => 'nullable',
                'isFeature' => 'nullable',
                'isBestSeller' => 'nullable',
                'isNew' => 'nullable',
                'on_sale' => 'nullable',
                'publish' => 'nullable',
                'limit' => 'nullable|integer|min:1|max:200',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $limit = $this->normalizeLimit($request, 300, 500);
            $search = Str::lower((string) $request->get('search', ''));

            // Cache key based on all request parameters
            $cacheKey = 'mart_items_all_' . md5(json_encode($request->all()));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            $query = MartItem::query();

            // Publish filter always applied
            $query->where('publish', $this->truthyValues);

            // Category filters
            if ($request->filled('categoryID')) {
                $query->where('categoryID', $request->categoryID);
            }

            if ($request->filled('subcategoryID')) {
                $query->where('subcategoryID', $request->subcategoryID);
            }

            // Section filter
            if ($request->filled('section')) {
                $query->where('section', $request->section);
            }

            // Boolean filters
            $booleanFilters = [
                'isTrending'   => 'isTrending',
                'isFeature'    => 'isFeature',
                'isBestSeller' => 'isBestSeller',
                'isNew'        => 'isNew',
            ];

            foreach ($booleanFilters as $reqKey => $dbColumn) {
                if ($request->filled($reqKey)) {
                    $query->where(
                        $dbColumn,
                        $this->interpretBoolean($request->get($reqKey))
                            ? $this->truthyValues
                            : $this->falsyValues
                    );
                }
            }

            $items = $query
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            // Local Search Filter
            if ($search !== '') {
                $items = $items->filter(function (MartItem $item) use ($search) {
                    return Str::contains(Str::lower($item->name ?? ''), $search) ||
                        Str::contains(Str::lower($item->description ?? ''), $search);
                })->values();
            }

            $response = [
                'status' => true,
                'message' => 'Mart items fetched successfully',
                'count' => $items->count(),
                'data' => $items
            ];

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Failed to fetch mart items', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => false,
                'message' => 'Error fetching mart items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getItemsByBrand(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'brandId' => 'required|string',
                'limit' => 'nullable|integer|min:1|max:200',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $limit = $this->normalizeLimit($request, 20, 200);
            $brandId = $request->get('brandId');

            // Cache key based on request parameters
            $cacheKey = 'mart_items_by_brand_' . md5(json_encode(['brandId' => $brandId, 'limit' => $limit]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            $items = MartItem::query()
                ->where('isAvailable', $this->truthyValues)
                ->where('publish', $this->truthyValues)
                ->where('brandID', $brandId)
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            $response = [
                'status' => true,
                'message' => 'Brand items fetched successfully',
                'count' => $items->count(),
                'data' => $items
            ];

            if ($items->isEmpty()) {
                $response = [
                    'status' => false,
                    'message' => 'No items found for the requested brand',
                    'data' => []
                ];
            }

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Failed to fetch mart items by brand', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Error fetching brand items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getUniqueSections(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'limit' => 'nullable|integer|min:1|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $limit = $this->normalizeLimit($request, 50, 500);

            // Cache key based on request parameters
            $cacheKey = 'mart_items_unique_sections_' . md5(json_encode(['limit' => $limit]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            $sections = MartItem::query()
                ->where('publish', $this->truthyValues)
                ->limit($limit)
                ->pluck('section')
                ->filter()
                ->map(function ($section) {
                    return trim((string) $section);
                })
                ->filter()
                ->unique()
                ->values()
                ->sort()
                ->values();

            $response = [
                'status' => true,
                'message' => 'Sections fetched successfully',
                'count' => $sections->count(),
                'data' => $sections
            ];

            if ($sections->isEmpty()) {
                $response = [
                    'status' => false,
                    'message' => 'No sections found',
                    'data' => []
                ];
            }

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Failed to fetch unique mart item sections', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Error fetching sections',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getmartcategory(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'limit' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $limit = (int) $request->get('limit', 20);
            if ($limit <= 0) {
                $limit = 20;
            }

            // Cache key based on request parameters
            $cacheKey = 'mart_category_' . md5(json_encode(['limit' => $limit]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            // 🔥 Equivalent to Firestore query
            $items = MartCategory::query()
                ->where('publish', [true, 1, '1', 'true'])
                ->limit($limit)
                ->get();

            $response = [
                'status' => true,
                'message' => 'Trending items fetched successfully',
                'count' => $items->count(),
                'data' => $items
            ];

            if ($items->isEmpty()) {
                $response = [
                    'status' => false,
                    'message' => 'No trending items found',
                    'data' => []
                ];
            }

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Failed to fetch trending mart items', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Error fetching trending items',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getcategoryhome(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'limit' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $limit = (int) $request->get('limit', 20);
            if ($limit <= 0) {
                $limit = 20;
            }

            // Cache key based on request parameters
            $cacheKey = 'mart_category_home_' . md5(json_encode(['limit' => $limit]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            // 🔥 Equivalent to Firestore query
            $items = MartCategory::query()
                ->where('show_in_homepage', $this->truthyValues)
                ->where('publish', $this->truthyValues)
//                ->whereIn('show_in_homepage', $this->truthyValues)
                ->limit($limit)
                ->get();

            $response = [
                'status' => true,
                'message' => 'Trending items fetched successfully',
                'count' => $items->count(),
                'data' => $items
            ];

            if ($items->isEmpty()) {
                $response = [
                    'status' => false,
                    'message' => 'No trending items found',
                    'data' => []
                ];
            }

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Failed to fetch trending mart items', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Error fetching trending items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getSubcategoriesByParent(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'parent_category_id' => 'required|string',
                'limit' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $limit = (int) $request->get('limit', 20);
            if ($limit <= 0) {
                $limit = 20;
            }
            $parent_category_id = $request->get('parent_category_id');

            // Cache key based on request parameters
            $cacheKey = 'mart_subcategories_by_parent_' . md5(json_encode([
                'parent_category_id' => $parent_category_id,
                'limit' => $limit
            ]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            // 🔥 Equivalent to Firestore query
            $items = MartSubcategory::query()
                ->where('parent_category_id', $parent_category_id )
                ->where('publish', $this->truthyValues)
                ->orderBy('title', 'asc')
                ->limit($limit)
                ->get();

            $response = [
                'status' => true,
                'message' => 'Trending items fetched successfully',
                'count' => $items->count(),
                'data' => $items
            ];

            if ($items->isEmpty()) {
                $response = [
                    'status' => false,
                    'message' => 'No trending items found',
                    'data' => []
                ];
            }

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Failed to fetch trending mart items', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Error fetching trending items',
                'error' => $e->getMessage()
            ], 500);
        }
    }


        public function getSubcategories_home(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'limit' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $limit = (int) $request->get('limit', 10);
            $page  = (int) $request->get('page', 1);

            // Cache key based on request parameters
            $cacheKey = 'mart_subcategories_home_' . md5(json_encode(['limit' => $limit, 'page' => $page]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            // Query with pagination
            $query = MartSubcategory::query()
                ->whereIn('show_in_homepage', $this->truthyValues)
                ->whereIn('publish', $this->truthyValues);
//                ->orderBy('title', 'asc');

            $items = $query->paginate($limit, ['*'], 'page', $page);

            $response = [
                'status' => true,
                'message' => 'Trending items fetched successfully',
                'count' => $items->count(),
                'data' => $items->items(),
                'meta' => [
                    'page' => $items->currentPage(),
                    'limit' => $items->perPage(),
                    'total' => $items->total(),
                    'last_page' => $items->lastPage(),
                ]
            ];

            if ($items->isEmpty()) {
                $response = [
                    'status' => false,
                    'message' => 'No trending items found',
                    'data' => [],
                    'meta' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => 0,
                        'last_page' => 0
                    ]
                ];
            }

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Failed to fetch trending mart items', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Error fetching trending items',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    public function searchSubcategories(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'query' => 'required|string',
            'limit' => 'nullable|integer'
        ]);

        $query = strtolower($request->get('query'));
        $limit = $request->limit ?? 20;

        // Cache key based on request parameters
        $cacheKey = 'mart_search_subcategories_' . md5(json_encode(['query' => $query, 'limit' => $limit]));
        $cacheTTL = 300; // 5 minutes

        // Check if force refresh is requested
        $forceRefresh = $request->boolean('refresh', false);

        // Check cache before database operations
        if (!$forceRefresh) {
            $cachedResponse = Cache::get($cacheKey);
            if ($cachedResponse !== null) {
                return response()->json($cachedResponse);
            }
        }

        // Fetch only published subcategories
        $subcategories = MartSubcategory::where('publish', true)
            ->limit($limit)
            ->get();

        // Filter in PHP (same as Flutter code)
        $results = $subcategories->filter(function ($subcategory) use ($query) {
            $title = strtolower($subcategory->title ?? '');
            $description = strtolower($subcategory->description ?? '');

            return str_contains($title, $query) ||
                str_contains($description, $query);
        })->values();

        $response = [
            'success' => true,
            'count' => $results->count(),
            'data'   => $results
        ];

        // Cache the response
        Cache::put($cacheKey, $response, $cacheTTL);

        return response()->json($response);
    }

    /**
     * Search Categories
     */
    public function searchCategories(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'query' => 'required|string',
            'limit' => 'nullable|integer'
        ]);

        $query = strtolower($request->get('query'));
        $limit = $request->limit ?? 20;

        // Cache key based on request parameters
        $cacheKey = 'mart_search_categories_' . md5(json_encode(['query' => $query, 'limit' => $limit]));
        $cacheTTL = 300; // 5 minutes

        // Check if force refresh is requested
        $forceRefresh = $request->boolean('refresh', false);

        // Check cache before database operations
        if (!$forceRefresh) {
            $cachedResponse = Cache::get($cacheKey);
            if ($cachedResponse !== null) {
                return response()->json($cachedResponse);
            }
        }

        // Fetch published categories
        $categories = MartCategory::where('publish', true)
            ->limit($limit)
            ->get();

        // Filter in PHP (same logic as Flutter)
        $results = $categories->filter(function ($category) use ($query) {
            $title = strtolower($category->title ?? '');
            $description = strtolower($category->description ?? '');

            return str_contains($title, $query) ||
                str_contains($description, $query);
        })->values();

        $response = [
            'success' => true,
            'count' => $results->count(),
            'data'   => $results
        ];

        // Cache the response
        Cache::put($cacheKey, $response, $cacheTTL);

        return response()->json($response);
    }


    public function getFeaturedCategories(Request $request)
    {
        $request->validate([
            'martId' => 'nullable|string'
        ]);

        try {
            $martId = $request->input('martId');

            // Cache key based on request parameters
            $cacheKey = 'mart_featured_categories_' . md5(json_encode(['martId' => $martId]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            // Base query
            $query = MartCategory::where('publish', true);
//                ->where('isFeature', true);

            // Optional mart filtering
            if (!empty($martId)) {
                $query->where('mart_id', $martId);
            }

            // Get data (limit 50 to match Flutter)
            $categories = $query->limit(50)->get();

            // Handle fields & sorting
            $formatted = $categories->map(function ($category) {

                // Ensure attributes exist
                if (empty($category->review_attributes)) {
                    $category->review_attributes = [];
                }

                // Ensure numeric values
                $category->category_order = is_numeric($category->category_order)
                    ? intval($category->category_order)
                    : 0;

                $category->section_order = is_numeric($category->section_order)
                    ? intval($category->section_order)
                    : 0;

                return $category;
            });

            // Sort by category_order
            $sorted = $formatted->sortBy('category_order')->values();

            $response = [
                'success' => true,
                'count' => $sorted->count(),
                'data' => $sorted
            ];

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching featured categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getItemById(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'id' => 'required|string',
                'limit' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $limit = (int) $request->get('limit', 20);
            if ($limit <= 0) {
                $limit = 20;
            }
            $id = $request->get('id');

            // Cache key based on request parameters
            $cacheKey = 'mart_item_by_id_' . md5(json_encode(['id' => $id, 'limit' => $limit]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            // 🔥 Equivalent to Firestore query
            $items = MartItem::query()
                ->where('id', $id )
                ->where('publish', $this->truthyValues)
                ->limit($limit)
                ->get();

            $response = [
                'status' => true,
                'message' => 'Trending items fetched successfully',
                'count' => $items->count(),
                'data' => $items
            ];

            if ($items->isEmpty()) {
                $response = [
                    'status' => false,
                    'message' => 'No trending items found',
                    'data' => []
                ];
            }

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Failed to fetch trending mart items', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Error fetching trending items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getSimilarProducts(Request $request)
    {
        $request->validate([
            'categoryId' => 'required|string',
            'subcategoryId' => 'nullable|string',
            'excludeId' => 'nullable|string',
            'limit' => 'nullable|integer'
        ]);

        try {
            $limit = $request->input('limit', 6);

            // Cache key based on request parameters
            $cacheKey = 'mart_similar_products_' . md5(json_encode([
                'categoryId' => $request->categoryId,
                'subcategoryId' => $request->subcategoryId,
                'excludeId' => $request->excludeId,
                'limit' => $limit
            ]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            $query = MartItem::where('publish', true)
                ->where('isAvailable',true)
                ->where('categoryID', $request->categoryId);

            if ($request->has('subcategoryId')) {
                $query->where('subcategoryID', $request->subcategoryId);
            }

            if ($request->has('excludeId')) {
                $query->where('id', '!=', $request->excludeId);
            }

            $items = $query->limit($limit)->get();

            $response = [
                'success' => true,
                'count' => $items->count(),
                'data' => $items
            ];

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching similar items',
                'error' => $e->getMessage()
            ], 500);
        }
    }
//    public function getItemsBySectionName(Request $request)
//    {
//        $request->validate([
//            'name' => 'required|string'
//        ]);
//
//        try {
//            $items = MartItem::where('publish', true)
//                ->where('section', $request->name)
//                ->get();
//
//            return response()->json([
//                'success' => true,
//                'count' => $items->count(),
//                'data' => $items
//            ]);
//        } catch (\Exception $e) {
//            return response()->json([
//                'success' => false,
//                'message' => 'Error fetching section items',
//                'error' => $e->getMessage()
//            ], 500);
//        }
//    }



    public function getMartVendors(Request $request)
    {
        try {
            /* ---------------- Validate ---------------- */
            $validator = Validator::make($request->all(), [
                'limit' => 'nullable|integer|min:1|max:200',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid request parameters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $limit = $this->normalizeLimit($request, 100, 200);

            // Cache key based on request parameters
            $cacheKey = 'mart_vendors_' . md5(json_encode(['limit' => $limit]));
            $cacheTTL = 300; // 5 minutes

            // Check if force refresh is requested
            $forceRefresh = $request->boolean('refresh', false);

            // Check cache before database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    return response()->json($cachedResponse);
                }
            }

            /* ---------------- Query ---------------- */
            $vendors = Vendor::query()
                ->where(function ($q) {
                    $q->whereIn('publish', $this->truthyValues)
                        ->orWhereNull('publish'); // NULL = published
                })
                ->where('vType', 'mart')
                ->limit($limit)
                ->get();

            /* ---------------- Normalize Response ---------------- */
            $data = $vendors->map(function ($vendor) {

                return [
                    'id' => $vendor->id,
                    'title' => $vendor->title,
                    'description' => $vendor->description,
                    'latitude' => (float)$vendor->latitude,
                    'longitude' => (float)$vendor->longitude,
                    'phonenumber' => $vendor->phonenumber,
                    'location' => $vendor->location,

                    // Images
                    'photo' => $vendor->photo,
                    'photos' => $this->safeJson($vendor->photos),

                    // Zone & slugs
                    'zoneId' => $vendor->zoneId,
                    'zone_slug' => $vendor->zone_slug,
                    'restaurant_slug' => $vendor->restaurant_slug,

                    // Schedule & config
                    'workingHours' => $this->safeJson($vendor->workingHours),
                    'filters' => $this->safeJson($vendor->filters),
                    'adminCommission' => $this->safeJson($vendor->adminCommission),
                    'specialDiscount' => $this->safeJson($vendor->specialDiscount),

                    // Categories
                    'categoryID' => $this->safeJson($vendor->categoryID),
                    'categoryTitle' => $this->safeJson($vendor->categoryTitle),
                    'restaurantMenuPhotos' => $this->safeJson($vendor->restaurantMenuPhotos),

                    // Geo
                    'g' => $this->safeJson($vendor->g),

                    // Status
                    'isOpen' => (bool)$vendor->isOpen,
                    'reststatus' => (bool)$vendor->reststatus,
                    'vType' => $vendor->vType,

                    // Publish (null allowed)
                    'publish' => $vendor->publish,
                ];
            });

            /* ---------------- Response ---------------- */
            $response = [
                'success' => true,
                'count' => $data->count(),
                'data' => $data
            ];

            // Cache the response
            Cache::put($cacheKey, $response, $cacheTTL);

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Failed to fetch mart vendors', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching mart vendors',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    private function safeJson($value)
    {
        if (empty($value)) {
            return null;
        }

        // Already decoded
        if (is_array($value) || is_object($value)) {
            return $value;
        }

        // Decode JSON string
        try {
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE
                ? $decoded
                : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

}
