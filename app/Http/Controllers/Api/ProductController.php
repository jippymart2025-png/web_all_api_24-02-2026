<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Models\Vendor;
use App\Models\VendorCategory;
use App\Models\VendorProduct;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;



class ProductController extends Controller
{
    /**
     * Fetch all published and available products for a vendor
     * OPTIMIZED: Cached for 1 hour for fast loading with fresher data
     */
    public function getProductsByVendorId($vendorId, Request $request = null)
    {
        try {
            // Normalize vendorId
            $vendorId = trim($vendorId);

            if (empty($vendorId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid vendor ID provided.',
                ], 400);
            }

            /** ---------------------------------------
             * CACHE: Check cache FIRST - before any DB operations
             * This ensures zero database hits when cache exists
             * ------------------------------------- */
            $cacheKey = 'vendor_products_v1_' . md5($vendorId);
            $cacheTTL = 300; // 1 hour (3600 seconds) - Reduced for fresher data

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
            $products = $this->fetchPublishedProducts(
                function ($query) use ($vendorId) {
                    $query->where('vendorID', $vendorId);
                }
            );

            /** ---------------------------------------
             * RESPONSE: Build and cache response
             * ------------------------------------- */
            $response = [
                'success' => true,
                'data' => $products,
                'message' => $products->isEmpty()
                    ? 'No available products found for this vendor'
                    : 'Products retrieved successfully'
            ];

            // Cache the response
            try {
                Cache::put($cacheKey, $response, $cacheTTL);
            } catch (\Throwable $cacheError) {
                Log::warning('Failed to cache vendor products response', [
                    'vendor_id' => $vendorId,
                    'cache_key' => $cacheKey,
                    'error' => $cacheError->getMessage(),
                ]);
                // Continue without caching if cache fails
            }

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Error fetching vendor products', [
                'vendor_id' => $vendorId ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error fetching vendor products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch all published and available products across vendors.
     */
    public function getAllPublishedProducts(Request $request)
    {
        try {
            $products = $this->fetchPublishedProducts(null, $request);

            if ($products instanceof LengthAwarePaginator) {
                $items = $products->items();

                return response()->json([
                    'success' => true,
                    'data' => $items,
                    'meta' => [
                        'total' => $products->total(),
                        'per_page' => $products->perPage(),
                        'current_page' => $products->currentPage(),
                        'last_page' => $products->lastPage(),
                    ],
                    'links' => [
                        'first' => $products->url(1),
                        'last' => $products->url($products->lastPage()),
                        'prev' => $products->previousPageUrl(),
                        'next' => $products->nextPageUrl(),
                    ],
                    'message' => empty($items)
                        ? 'No available products found'
                        : 'Products retrieved successfully',
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $products,
                'message' => $products->isEmpty()
                    ? 'No available products found'
                    : 'Products retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Comprehensive product feed for restaurant detail screen
     * OPTIMIZED: Lazy loading with cursor + CACHED for 30 minutes
     */
    public function getRestaurantProductFeed(Request $request, string $vendorId, ?string $extra = null)
    {
        try {
            /** ---------------------------------------
             * Extra Query Handling (NO CHANGE)
             * ------------------------------------- */
            if ($extra && $request->query->count() === 0) {
                $extraQuery = ltrim($extra, '?&');
                if ($extraQuery !== '') {
                    parse_str($extraQuery, $extraParams);
                    foreach ($extraParams as $key => $value) {
                        $request->query->set($key, $value);
                    }
                }
            }

            $filters  = $this->parseFilters($request);
            $vendorId = trim($vendorId);

            // Validate vendorId
            if (empty($vendorId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid vendor ID provided.',
                ], 400);
            }

            /** ---------------------------------------
             * CACHE: Check cache FIRST - before any DB operations
             * This ensures zero database hits when cache exists
             * ------------------------------------- */
            $cacheKey = $this->generateProductFeedCacheKey($vendorId, $filters);
            $cacheTTL = 300; // 30 minutes (1800 seconds) - Reduced for fresher data

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
             * PRODUCT QUERY (OPTIMIZED + LAZY)
             * ------------------------------------- */
            $query = VendorProduct::query()
                ->select([
                    'id', 'name', 'description', 'categoryID', 'categoryTitle',
                    'vendorID', 'price', 'disPrice', 'quantity', 'publish',
                    'isAvailable', 'veg', 'nonveg', 'photo', 'photos',
                    'addOnsTitle', 'addOnsPrice', 'item_attribute',
                    'product_specification', 'reviewsCount', 'reviewsSum'
                ])
                ->where('vendorID', $vendorId)
                ->where('isAvailable', 1)
                ->where(function ($q) {
                    $q->whereNull('publish')
                      ->orWhere('publish', 1)
                      ->orWhere('publish', '1');
               
                });

            /** Search */
            if ($filters['search'] !== null) {
                $search = '%' . $filters['search'] . '%';
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', $search)
                      ->orWhere('description', 'LIKE', $search);
                });
            }

            /** Veg / Non-veg filter */
            $isVeg    = $filters['is_veg'];
            $isNonVeg = $filters['is_nonveg'];

            if ($isVeg === true && $isNonVeg !== true) {
                $query->where('veg', 1);
            } elseif ($isNonVeg === true && $isVeg !== true) {
                $query->where('nonveg', 1);
            }

            if ($isVeg === false) {
                $query->where('veg', 0);
            }
            if ($isNonVeg === false) {
                $query->where('nonveg', 0);
            }

            /** ---------------------------------------
             * LAZY LOAD PRODUCTS
             * ------------------------------------- */
            $productsCursor = $query
                ->orderBy('name')
                ->cursor(); // 🔥 LazyCollection

            /** ---------------------------------------
             * Vendor (OPTIMIZED: Select only needed columns)
             * ------------------------------------- */
            $vendor = Vendor::query()
                ->select(['id', 'title'])
                ->find($vendorId);

            /** ---------------------------------------
             * Promotions (OPTIMIZED: Select only needed columns)
             * ------------------------------------- */
            $now = Carbon::now();

            $promotions = Promotion::query()
                ->select(['id', 'product_id', 'special_price', 'item_limit', 'start_time', 'end_time', 'restaurant_id'])
                ->when($vendor, function ($q) use ($vendor) {
                    $q->whereIn('restaurant_id', [$vendor->id, $vendor->title]);
                }, function ($q) use ($vendorId) {
                    $q->where('restaurant_id', $vendorId);
                })
                ->where('isAvailable', true)
                ->where(function ($q) use ($now) {
                    $q->whereNull('start_time')->orWhere('start_time', '<=', $now);
                })
                ->where(function ($q) use ($now) {
                    $q->whereNull('end_time')->orWhere('end_time', '>=', $now);
                })
                ->get()
                ->groupBy('product_id');

            /** ---------------------------------------
             * COLLECT PRODUCTS AND CATEGORY IDS (OPTIMIZED)
             * ------------------------------------- */
            $products = collect();
            $categoryIds = collect();

            foreach ($productsCursor as $product) {
                $products->push($product);
                if ($product->categoryID) {
                    $categoryIds->push($product->categoryID);
                }
            }

            $categoryIds = $categoryIds->unique()->values();

            /** ---------------------------------------
             * Categories (OPTIMIZED: Single query with select)
             * ------------------------------------- */
            $restaurantKeys = array_values(array_filter([
                $vendor?->title,
                $vendorId,
                $vendor?->id
            ]));

            $categories = VendorCategory::query()
                ->select(['id', 'title', 'description', 'photo', 'restaurant_id'])
                ->whereIn('id', $categoryIds)
                ->whereIn('restaurant_id', $restaurantKeys)
                ->get()
                ->keyBy('id');

            if ($categories->isEmpty() && $categoryIds->isNotEmpty()) {
                $categories = VendorCategory::query()
                    ->select(['id', 'title', 'description', 'photo', 'restaurant_id'])
                    ->whereIn('id', $categoryIds)
                    ->get()
                    ->keyBy('id');
            }

            /** ---------------------------------------
             * TRANSFORM PRODUCTS (OPTIMIZED: Use collected products)
             * ------------------------------------- */
            $transformedProducts = collect();

            foreach ($products as $product) {
                $category = $categories->get($product->categoryID);

                $data = $this->transformProduct(
                    $product,
                    $promotions,
                    $category
                );

                $data['vendorID'] = $vendorId;

                $transformedProducts->push($data);
            }

            /** ---------------------------------------
             * Offer-only Filter (NO CHANGE)
             * ------------------------------------- */
            if ($filters['offer_only'] === true) {
                $transformedProducts = $transformedProducts
                    ->filter(function ($p) {
                        if ($p['has_active_promotion']) return true;

                        return $p['discount_price']
                            && $p['original_price']
                            && floatval($p['discount_price']) > 0
                            && floatval($p['discount_price']) < floatval($p['original_price']);
                    })
                    ->values();
            }

            /** ---------------------------------------
             * Category Summaries
             * ------------------------------------- */
            $categorySummaries = $this->buildCategorySummaries(
                $transformedProducts,
                $categories
            );

            /** ---------------------------------------
             * RESPONSE: Build and cache response
             * ------------------------------------- */
            $response = [
                'success' => true,
                'data' => [
                    'filters' => $filters,
                    'meta' => [
                        'total_products' => $transformedProducts->count(),
                        'offer_products' => $transformedProducts
                            ->where('has_active_promotion', true)
                            ->count(),
                        'categories' => $categorySummaries->count(),
                    ],
                    'categories' => $categorySummaries->values(),
                    'products' => $transformedProducts->values(),
                ],
            ];

            // Cache the response
            try {
                Cache::put($cacheKey, $response, $cacheTTL);
            } catch (\Throwable $cacheError) {
                Log::warning('Failed to cache product feed response', [
                    'vendor_id' => $vendorId,
                    'cache_key' => $cacheKey,
                    'error' => $cacheError->getMessage(),
                ]);
                // Continue without caching if cache fails
            }

            return response()->json($response);

        } catch (\Throwable $e) {
            Log::error('Error building restaurant product feed: ' . $e->getMessage(), [
                'vendor_id' => $vendorId ?? 'unknown',
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load restaurant products at the moment.',
            ], 500);
        }
    }


    /**
     * Fetch single product details by product ID.
     *
     * @param string $productId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProductById(string $productId)
    {
        try {
            $product = VendorProduct::find($productId);

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found',
                ], 404);
            }

            $vendor = Vendor::find($product->vendorID);

            $promotions = Promotion::query()
                ->where(function ($query) use ($productId, $product) {
                    $query->where('product_id', $productId)
                        ->orWhere('product_id', $product->id);
                })
                ->where('isAvailable', true)
                ->get()
                ->groupBy('product_id');

            $category = null;
            if (!empty($product->categoryID)) {
                $category = VendorCategory::find($product->categoryID);

                if (!$category && $vendor) {
                    $category = VendorCategory::query()
                        ->where('id', $product->categoryID)
                        ->whereIn('restaurant_id', array_filter([$vendor->id, $vendor->title]))
                        ->first();
                }
            }

            $data = $this->transformProduct($product, $promotions, $category);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching product by ID', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load product at the moment.',
            ], 500);
        }
    }

    protected function parseFilters(Request $request): array
    {
        return [
            'search' => $this->stringOrNull($request->query('search')),
            'is_veg' => $this->nullableBool($request->query('is_veg')),
            'is_nonveg' => $this->nullableBool($request->query('is_nonveg')),
            'offer_only' => $this->nullableBool($request->query('offer_only')),
        ];
    }

    protected function transformProduct(VendorProduct $product, Collection $promotions, ?VendorCategory $category = null): array
    {
        $safeDecode = fn ($value) => $this->safeDecode($value);
        $promotion = optional($promotions->get($product->id))->first();

        $originalPrice = $this->numericString($product->price);
        $discountPrice = $this->numericString($product->disPrice);
        $hasPromotion = $promotion !== null;

        $finalPrice = $hasPromotion
            ? $this->numericString($promotion->special_price)
            : ($this->isValidDiscount($originalPrice, $discountPrice)
                ? $discountPrice
                : $originalPrice);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'category_id' => $product->categoryID,
            'category_title' => $category->title ?? $product->categoryTitle,
            'is_available' => $this->coerceBoolean($product->isAvailable),
            'nonveg' => $this->coerceBoolean($product->nonveg),
            'veg' => $this->coerceBoolean($product->veg),
            'photo' => $product->photo,
            'photos' => $safeDecode($product->photos),
            'add_ons_title' => $safeDecode($product->addOnsTitle),
            'add_ons_price' => $safeDecode($product->addOnsPrice),
            'item_attribute' => $safeDecode($product->item_attribute),
            'product_specification' => $safeDecode($product->product_specification),
            'reviews_count' => (int) ($product->reviewsCount ?? 0),
            'reviews_sum' => (float) ($product->reviewsSum ?? 0),
            'quantity' => $product->quantity,
            'original_price' => $originalPrice,
            'discount_price' => $discountPrice,
            'final_price' => $finalPrice,
            'has_active_promotion' => $hasPromotion,
            'promotion' => $hasPromotion ? [
                'id' => $promotion->id,
                'special_price' => $this->numericString($promotion->special_price),
                'item_limit' => $promotion->item_limit,
                'start_time' => $this->formatDateTime($promotion->start_time),
                'end_time' => $this->formatDateTime($promotion->end_time),
            ] : null,
        ];
    }
    public function getAllProducts(Request $request)
    {
        try {
            $products = $this->fetchPublishedProducts(null, $request);

            if ($products instanceof LengthAwarePaginator) {
                $items = $products->items();

                return response()->json([
                    'success' => true,
                    'data' => $items,
                    'meta' => [
                        'total' => $products->total(),
                        'per_page' => $products->perPage(),
                        'current_page' => $products->currentPage(),
                        'last_page' => $products->lastPage(),
                    ],
                    'links' => [
                        'first' => $products->url(1),
                        'last' => $products->url($products->lastPage()),
                        'prev' => $products->previousPageUrl(),
                        'next' => $products->nextPageUrl(),
                    ],
                    'message' => empty($items)
                        ? 'No available products found'
                        : 'Products retrieved successfully',
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $products,
                'message' => $products->isEmpty()
                    ? 'No available products found'
                    : 'Products retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    protected function fetchPublishedProducts(?callable $scopedQuery = null, ?Request $request = null)
    {
        $query = VendorProduct::query()
            ->select([
                'id',
                'name',
                'description',
                'categoryID',
                'categoryTitle',
                'vendorID',
                'vendorTitle',
                'price',
                'disPrice',
                'quantity',
                'publish',
                'isAvailable',
                'veg',
                'nonveg',
                'takeawayOption',
                'photo',
                'photos',
                'createdAt',
            ])
            ->where('publish', 1)
            ->where('isAvailable', 1)
            ->orderBy('name');

        if ($scopedQuery) {
            $scopedQuery($query);
        }

        $transform = function (VendorProduct $item) {
            return $this->mapBasicProduct($item);
        };

        if ($request) {
            $perPage = (int) $request->query('per_page', 50);
            if ($perPage <= 0) {
                $perPage = 50;
            }
            $perPage = min($perPage, 200);

            $paginator = $query->paginate($perPage);
            $paginator->getCollection()->transform($transform);

            return $paginator;
        }

        return $query->get()->map($transform);
    }

    protected function mapBasicProduct(VendorProduct $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'vendor_id' => $item->vendorID,
            'vendor_title' => $item->vendorTitle,
            'category_id' => $item->categoryID,
            'category_title' => $item->categoryTitle,
            'is_available' => $this->coerceBoolean($item->isAvailable) ?? false,
            'publish' => $this->coerceBoolean($item->publish) ?? false,
            'veg' => $this->coerceBoolean($item->veg),
            'nonveg' => $this->coerceBoolean($item->nonveg),
            'quantity' => $item->quantity,
            'price' => $item->price,
            'discount_price' => $item->disPrice,
            'takeaway_option' => $this->coerceBoolean($item->takeawayOption),
            'photo' => $item->photo,
            'photos' => $this->safeDecode($item->photos),
            'created_at' => $this->safeDecode($item->createdAt),
        ];
    }

    protected function buildCategorySummaries(Collection $products, Collection $categories): Collection
    {
        $categoryCounts = $products
            ->groupBy('category_id')
            ->map(fn ($items) => $items->count());

        $categoryIds = $categoryCounts->keys()
            ->filter()
            ->values();

        if ($categoryIds->isEmpty() || $categories->isEmpty()) {
            return collect();
        }

        return $categoryCounts->map(function ($count, $categoryId) use ($categories, $products) {
            $category = $categories->get($categoryId);
            $productForCategory = $products->firstWhere('category_id', $categoryId);

            return [
                'id' => $categoryId,
                'title' => $category->title ?? ($productForCategory['category_title'] ?? null),
                'description' => $category->description ?? null,
                'photo' => $category->photo ?? null,
                'product_count' => $count,
            ];
        })->values();
    }

    protected function nullableBool($value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $bool;
    }

    protected function stringOrNull($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function safeDecode($value)
    {
        if (empty($value) || !is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    protected function numericString($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return $cleaned === '' ? null : $cleaned;
    }

    protected function isValidDiscount(?string $original, ?string $discount): bool
    {
        if ($original === null || $discount === null) {
            return false;
        }

        $originalValue = (float) $original;
        $discountValue = (float) $discount;

        return $discountValue > 0 && $discountValue < $originalValue;
    }

    protected function formatDateTime($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function coerceBoolean($value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) (int) $value;
        }

        $lower = strtolower((string) $value);

        if (in_array($lower, ['true', '1', 'yes'], true)) {
            return true;
        }

        if (in_array($lower, ['false', '0', 'no'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Generate a unique cache key for product feed based on vendor and filters
     *
     * @param string $vendorId
     * @param array $filters
     * @return string
     */
    protected function generateProductFeedCacheKey(string $vendorId, array $filters): string
    {
        // Normalize filters for consistent cache keys
        $filterHash = md5(json_encode([
            'search' => $filters['search'] ?? null,
            'is_veg' => $filters['is_veg'] ?? null,
            'is_nonveg' => $filters['is_nonveg'] ?? null,
            'offer_only' => $filters['offer_only'] ?? null,
        ]));

        return "product_feed_vendor_{$vendorId}_filters_{$filterHash}";
    }

    /**
     * Clear cache for a specific vendor's product feed
     * Note: For immediate refresh, use ?refresh=true parameter in the API call.
     *
     * @param string $vendorId
     * @param array|null $filters Optional filters to clear specific cache entry
     * @return bool
     */
    public function clearProductFeedCache(string $vendorId, ?array $filters = null): bool
    {
        try {
            if ($filters !== null) {
                // Clear specific cache entry
                $cacheKey = $this->generateProductFeedCacheKey($vendorId, $filters);
                Cache::forget($cacheKey);
                Log::info('Product feed cache cleared for specific filters', [
                    'vendor_id' => $vendorId,
                    'cache_key' => $cacheKey,
                ]);
                return true;
            }

            // Clear ALL cache entries for this vendor using pattern matching
            $cacheDriver = config('cache.default');
            $cleared = 0;
            $cachePrefix = config('cache.prefix', '');
            $pattern = "product_feed_vendor_{$vendorId}_%";

            if ($cacheDriver === 'database') {
                // For database cache, delete all entries matching the pattern
                try {
                    $deleted = DB::table('cache')
                        ->where('key', 'like', $pattern)
                        ->delete();
                    $cleared = $deleted;
                    Log::info('Product feed cache cleared for vendor (database)', [
                        'vendor_id' => $vendorId,
                        'keys_cleared' => $cleared,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to clear vendor cache from database', [
                        'vendor_id' => $vendorId,
                        'error' => $e->getMessage(),
                    ]);
                }
            } elseif ($cacheDriver === 'file') {
                // For file cache, we need to iterate through cache files
                try {
                    $cachePath = storage_path('framework/cache/data');
                    if (is_dir($cachePath)) {
                        $files = glob($cachePath . '/*');
                        foreach ($files as $file) {
                            if (is_file($file)) {
                                $content = file_get_contents($file);
                                // Check if file contains the vendor cache key pattern
                                if (strpos($content, "product_feed_vendor_{$vendorId}_") !== false) {
                                    unlink($file);
                                    $cleared++;
                                }
                            }
                        }
                    }
                    Log::info('Product feed cache cleared for vendor (file)', [
                        'vendor_id' => $vendorId,
                        'keys_cleared' => $cleared,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to clear vendor cache from files', [
                        'vendor_id' => $vendorId,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                // For Redis/Memcached, try to use pattern matching if available
                // Fallback: Clear common filter combinations
                $commonFilters = [
                    ['search' => null, 'is_veg' => null, 'is_nonveg' => null, 'offer_only' => null],
                    ['search' => null, 'is_veg' => true, 'is_nonveg' => null, 'offer_only' => null],
                    ['search' => null, 'is_veg' => null, 'is_nonveg' => true, 'offer_only' => null],
                    ['search' => null, 'is_veg' => null, 'is_nonveg' => null, 'offer_only' => true],
                ];

                foreach ($commonFilters as $filterSet) {
                    $cacheKey = $this->generateProductFeedCacheKey($vendorId, $filterSet);
                    if (Cache::forget($cacheKey)) {
                        $cleared++;
                    }
                }

                Log::info('Product feed cache cleared for vendor (common filters)', [
                    'vendor_id' => $vendorId,
                    'keys_cleared' => $cleared,
                    'note' => 'Only common filter combinations cleared. Use ?refresh=true in API call for immediate refresh',
                ]);
            }

            return $cleared > 0;
        } catch (\Throwable $e) {
            Log::error('Error clearing product feed cache', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
