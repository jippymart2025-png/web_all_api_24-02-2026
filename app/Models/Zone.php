<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Zone extends Model
{
    use HasFactory;
    // Specify the actual table name
    protected $table = 'zone';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'id',
        'latitude',
        'longitude',
        'area',
        'name',
        'publish'
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'area' => 'array',
        'publish' => 'boolean'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    /**
     * Check if point is inside polygon (Ray Casting algorithm)
     */
    public static function isPointInPolygon($point, $polygon)
    {
        $x = $point['longitude'];
        $y = $point['latitude'];
        $inside = false;

        if (!is_array($polygon) || empty($polygon)) {
            return false;
        }

        $n = count($polygon);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            // Check if polygon points have the expected structure
            if (!isset($polygon[$i]['latitude']) || !isset($polygon[$i]['longitude']) ||
                !isset($polygon[$j]['latitude']) || !isset($polygon[$j]['longitude'])) {
                continue;
            }

            $xi = (float) $polygon[$i]['longitude'];
            $yi = (float) $polygon[$i]['latitude'];
            $xj = (float) $polygon[$j]['longitude'];
            $yj = (float) $polygon[$j]['latitude'];

            $intersect = (($yi > $y) != ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);

            if ($intersect) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * Get current zone by coordinates
     */
    public static function getCurrentZone($latitude, $longitude)
    {
        \Log::info("[DEBUG] getCurrentZone() called - User location: $latitude, $longitude");

        $zones = self::where('publish', 1)->get();
        $detectedZone = null;

        if ($zones->isNotEmpty()) {
            \Log::info("[DEBUG] Found {$zones->count()} published zones");

            foreach ($zones as $zone) {
                if (!empty($zone->area) && is_array($zone->area)) {
                    $point = [
                        'latitude' => (float) $latitude,
                        'longitude' => (float) $longitude
                    ];

                    \Log::info("[DEBUG] Checking zone: {$zone->name} ({$zone->id})");
                    \Log::info("[DEBUG] Zone area points: " . count($zone->area));

                    if (self::isPointInPolygon($point, $zone->area)) {
                        $detectedZone = $zone;
                        \Log::info("[DEBUG] ✅ Point is INSIDE zone: {$zone->name}");
                        break;
                    } else {
                        \Log::info("[DEBUG] ❌ Point is OUTSIDE zone: {$zone->name}");
                    }
                } else {
                    \Log::info("[DEBUG] Zone {$zone->name} has no valid area data");
                }
            }

            if ($detectedZone) {
                \Log::info("[DEBUG] Zone detected: {$detectedZone->name} ({$detectedZone->id})");

                return [
                    'zone' => $detectedZone,
                    'is_zone_available' => true
                ];
            } else {
                \Log::info("[DEBUG] User is outside all zones, getting fallback zone");

                // Get fallback zone
                $fallbackZone = self::where('publish', 1)->first();

                return [
                    'zone' => $fallbackZone,
                    'is_zone_available' => false
                ];
            }
        }

        \Log::info("[DEBUG] No published zones available in database");
        return [
            'zone' => null,
            'is_zone_available' => false
        ];
    }

    /**
     * Detect zone ID for coordinates
     */
    public static function detectZoneIdForCoordinates($latitude, $longitude)
    {
        try {
            \Log::info("[DEBUG] Starting zone detection for coordinates: $latitude, $longitude");

            $zones = self::where('publish', 1)->get();

            if ($zones->isEmpty()) {
                \Log::info("[DEBUG] No published zones available in database");
                return null;
            }

            \Log::info("[DEBUG] Found {$zones->count()} zones to check");

            foreach ($zones as $zone) {
                if (!empty($zone->area) && is_array($zone->area)) {
                    \Log::info("[DEBUG] Checking zone: {$zone->name} ({$zone->id})");

                    $point = [
                        'latitude' => (float) $latitude,
                        'longitude' => (float) $longitude
                    ];

                    if (self::isPointInPolygon($point, $zone->area)) {
                        \Log::info("[DEBUG] ✅ Zone detected: {$zone->name} ({$zone->id})");
                        return $zone->id;
                    }
                }
            }

            \Log::info("[DEBUG] ❌ Coordinates not within any service zone");
            return null;

        } catch (\Exception $e) {
            \Log::error("[DEBUG] Error detecting zone: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if location is in service area
     */
    public static function isLocationInServiceArea($latitude, $longitude)
    {
        try {
            $zoneId = self::detectZoneIdForCoordinates($latitude, $longitude);
            return !is_null($zoneId);
        } catch (\Exception $e) {
            \Log::error("[LOCATION_SERVICE] Error checking service area: " . $e->getMessage());
            return false;
        }
    }
}
//
//namespace App\Models;
//
//use Illuminate\Database\Eloquent\Factories\HasFactory;
//use Illuminate\Database\Eloquent\Model;
//
//class Zone extends Model
//{
//    use HasFactory;
//
//    protected $table = 'zone';
//    protected $primaryKey = 'id';
//    public $incrementing = false;
//    protected $keyType = 'string';
//    public $timestamps = false;
//
//    protected $fillable = [
//        'id',
//        'name',
//        'latitude',
//        'longitude',
//        'area',
//        'publish'
//    ];
//
//    protected $casts = [
//        'latitude' => 'float',
//        'longitude' => 'float'
//    ];
//}
//
