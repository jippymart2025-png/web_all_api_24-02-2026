<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Zone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ZoneController extends Controller
{
    /**
     * Get current zone by coordinates
     */
    public function getCurrentZone(Request $request)
    {
        try {
            // Validate request parameters
            $validated = $request->validate([
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180'
            ]);

            $latitude = $validated['latitude'];
            $longitude = $validated['longitude'];

            Log::info("[DEBUG] getZone() API called - User location: $latitude, $longitude");

            // Get current zone
            $result = Zone::getCurrentZone($latitude, $longitude);

            if ($result['zone']) {
                return response()->json([
                    'success' => true,
                    'zone' => [
                        'id' => $result['zone']->id,
                        'name' => $result['zone']->name,
                        'latitude' => $result['zone']->latitude,
                        'longitude' => $result['zone']->longitude,
                        'publish' => $result['zone']->publish,
                        'area' => $result['zone']->area
                    ],
                    'is_zone_available' => $result['is_zone_available'],
                    'message' => $result['is_zone_available'] ? 'Zone detected successfully' : 'Using fallback zone - outside service area'
                ]);
            }

            return response()->json([
                'success' => true,
                'zone' => null,
                'is_zone_available' => false,
                'message' => 'No zone found for the given coordinates'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $error) {
            Log::error("[DEBUG] Error in getCurrentZone API: " . $error->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to detect zone',
                'error' => $error->getMessage()
            ], 500);
        }
    }

    /**
     * Detect zone ID only for coordinates
     */
    public function detectZoneId(Request $request)
    {
        try {
            $validated = $request->validate([
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180'
            ]);

            $latitude = $validated['latitude'];
            $longitude = $validated['longitude'];

            $zoneId = Zone::detectZoneIdForCoordinates($latitude, $longitude);

            return response()->json([
                'success' => true,
                'zone_id' => $zoneId,
                'is_zone_available' => !is_null($zoneId),
                'message' => $zoneId ? 'Zone ID detected successfully' : 'No zone found for coordinates'
            ]);

        } catch (\Exception $error) {
            Log::error("[DEBUG] Error in detectZoneId API: " . $error->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to detect zone ID',
                'error' => $error->getMessage()
            ], 500);
        }
    }

    /**
     * Check if location is in service area
     */
    public function checkServiceArea(Request $request)
    {
        try {
            $validated = $request->validate([
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180'
            ]);

            $latitude = $validated['latitude'];
            $longitude = $validated['longitude'];

            $isInServiceArea = Zone::isLocationInServiceArea($latitude, $longitude);
            $zoneId = Zone::detectZoneIdForCoordinates($latitude, $longitude);

            return response()->json([
                'success' => true,
                'is_in_service_area' => $isInServiceArea,
                'zone_id' => $zoneId,
                'message' => $isInServiceArea ? 'Location is in service area' : 'Location is not in service area'
            ]);

        } catch (\Exception $error) {
            Log::error("[LOCATION_SERVICE] Error in checkServiceArea API: " . $error->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to check service area',
                'error' => $error->getMessage()
            ], 500);
        }
    }

    /**
     * Get all published zones
     */
    public function getAllZones()
    {
        try {
            $zones = Zone::where('publish', 1)->get();

            return response()->json([
                'success' => true,
                'zones' => $zones,
                'count' => $zones->count(),
                'message' => 'Zones retrieved successfully'
            ]);

        } catch (\Exception $error) {
            Log::error("[DEBUG] Error in getAllZones API: " . $error->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve zones',
                'error' => $error->getMessage()
            ], 500);
        }
    }

    /**
     * Debug endpoint - Check database connection and zones table
     */
    public function debugZones()
    {
        try {
            // Check if table exists
            $tableExists = DB::select("SHOW TABLES LIKE 'zone'");

            // Get zone count
            $zoneCount = Zone::count();

            // Get published zones count
            $publishedCount = Zone::where('publish', 1)->count();

            // Get sample zones
            $sampleZones = Zone::limit(5)->get(['id', 'name', 'publish', 'latitude', 'longitude']);

            return response()->json([
                'success' => true,
                'debug_info' => [
                    'table_exists' => !empty($tableExists),
                    'total_zones' => $zoneCount,
                    'published_zones' => $publishedCount,
                    'sample_zones' => $sampleZones,
                    'database' => config('database.default'),
                    'connection' => DB::connection()->getDatabaseName()
                ]
            ]);

        } catch (\Exception $error) {
            return response()->json([
                'success' => false,
                'message' => 'Debug failed',
                'error' => $error->getMessage()
            ], 500);
        }
    }
}
