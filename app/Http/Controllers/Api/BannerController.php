<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MartBanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class BannerController extends Controller
{
    /**
     * Get Top Banners
     * GET /api/banners/top
     *
     * Purpose: Get top position banners for home page
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
            // Base query: published banners with position = "top"
            $query = MartBanner::where('is_publish', true)
                ->where('position', 'top');

            // Apply zone filtering logic
            if ($zoneId) {
                // If user has a zone, show:
                // 1. Banners with matching zoneId
                // 2. Banners with no zoneId (null or empty) - shown to all zones
                $query->where(function($q) use ($zoneId) {
                    $q->where('zoneId', $zoneId)
                      ->orWhereNull('zoneId')
                      ->orWhere('zoneId', '');
                });
            }
            // If no zone_id provided, show all (fallback)

            // Order by set_order
            $query->orderBy('set_order', 'asc');

            // Get banners
            $banners = $query->get();

            // Format response
            $data = $banners->map(function ($banner) {
                return [
                    'id' => $banner->id,
                    'title' => $banner->title ?? '',
                    'photo' => $banner->photo ?? '',
                    'position' => $banner->position ?? 'top',
                    'is_publish' => (bool) $banner->is_publish,
                    'set_order' => (int) ($banner->set_order ?? 0),
                    'zoneId' => $banner->zoneId ?? null,
                    'redirect_type' => $banner->redirect_type ?? null,
                    'redirect_id' => $this->getRedirectId($banner),
                    'description' => $banner->description ?? '',
                    'text' => $banner->text ?? '',
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            Log::error('Get Top Banners Error: ' . $e->getMessage(), [
                'zone_id' => $zoneId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch top banners',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get redirect_id based on redirect_type
     */
    private function getRedirectId($banner)
    {
        $redirectType = $banner->redirect_type;

        switch ($redirectType) {
            case 'store':
                return $banner->storeId ?? null;
            case 'product':
                return $banner->productId ?? null;
            case 'external_link':
                return $banner->external_link ?? $banner->ads_link ?? null;
            default:
                return null;
        }
    }

    /**
     * Get All Banners
     * GET /api/banners
     */
    public function index(Request $request)
    {
        try {
            $zoneId = $request->input('zone_id');
            $position = $request->input('position'); // optional filter by position

            $query = MartBanner::where('is_publish', true);

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
                    'redirect_type' => $banner->redirect_type ?? null,
                    'redirect_id' => $this->getRedirectId($banner),
                    'description' => $banner->description ?? '',
                    'text' => $banner->text ?? '',
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'count' => $data->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Get Banners Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch banners',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get Single Banner
     * GET /api/banners/{id}
     */
    public function show($id)
    {
        try {
            $banner = MartBanner::find($id);

            if (!$banner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Banner not found'
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
                    'redirect_type' => $banner->redirect_type ?? null,
                    'redirect_id' => $this->getRedirectId($banner),
                    'description' => $banner->description ?? '',
                    'text' => $banner->text ?? '',
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Get Banner Error: ' . $e->getMessage(), ['id' => $id]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch banner',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}

