<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Story;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class StoryController extends Controller
{
    /**
     * Get Stories for Restaurants in the Current Zone
     * GET /api/stories
     *
     * Query Parameters:
     * - zone_id (required): Current zone ID for the customer
     * - vendor_ids (optional): Comma-separated list of vendor IDs to filter
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'zone_id' => 'required|string',
            'vendor_ids' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $zoneId = $request->input('zone_id');
        $vendorIds = collect();

        if ($request->filled('vendor_ids')) {
            $vendorIds = collect(explode(',', $request->input('vendor_ids')))
                ->map(fn ($id) => trim($id))
                ->filter()
                ->unique();
        }

        try {
            $vendorQuery = Vendor::query()
                ->where('zoneId', $zoneId)
                ->where(function ($q) {
                    $q->where('publish', true)
                        ->orWhereNull('publish');
                })
                ->where(function ($q) {
                    $q->whereNull('vType')
                        ->orWhereIn('vType', ['restaurant', 'food']);
                });

            if ($vendorIds->isNotEmpty()) {
                $vendorQuery->whereIn('id', $vendorIds);
            }

            $vendorIdList = $vendorQuery->pluck('id');

            if ($vendorIdList->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }

            $stories = Story::query()
                ->whereIn('vendor_id', $vendorIdList)
                ->orderByDesc('created_at')
                ->get();

            $data = $stories->map(function (Story $story) {
                return [
                    'id' => $story->firestore_id ?? (string) $story->id,
                    'vendorID' => $story->vendor_id,
                    'videoThumbnail' => $story->video_thumbnail,
                    'videoUrl' => $story->video_url,
                    'createdAt' => $story->created_at ? $story->created_at->toIso8601String() : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Get Stories Error: ' . $e->getMessage(), [
                'zone_id' => $zoneId,
                'vendor_ids' => $vendorIds->values()->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch stories',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}


