<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Kreait\Firebase\Factory;

class FirebaseLiveTrackingController extends Controller
{
    protected $firestore;

    public function __construct()
    {
        $factory = (new Factory)
            ->withServiceAccount(base_path(env('FIREBASE_CREDENTIALS')));

        $this->firestore = $factory->createFirestore()->database();
    }

    /**
     * Get live tracking data (in-transit orders + available drivers)
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $cacheKey = "firebase_live_tracking_v1";

        // Cache for 10 seconds (since locations update frequently)
        $data = Cache::remember($cacheKey, 10, function () {
            $inTransitOrders = $this->getInTransitOrders();
            $availableDrivers = $this->getAvailableDrivers($inTransitOrders);

            return [
                'in_transit' => $inTransitOrders,
                'available_drivers' => $availableDrivers,
            ];
        });

        return response()->json([
            'status' => true,
            'message' => 'Live tracking data fetched successfully',
            'meta' => [
                'in_transit_count' => count($data['in_transit']),
                'available_drivers_count' => count($data['available_drivers']),
                'total_count' => count($data['in_transit']) + count($data['available_drivers']),
                'cache_ttl_seconds' => 10,
            ],
            'data' => [
                'in_transit_orders' => $data['in_transit'],
                'available_drivers' => $data['available_drivers'],
            ],
        ]);
    }

    /**
     * Get driver location by ID (for real-time updates)
     * 
     * @param string $driverId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDriverLocation($driverId)
    {
        try {
            $driver = $this->firestore
                ->collection('users')
                ->document($driverId)
                ->snapshot();

            if (!$driver->exists()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Driver not found',
                ], 404);
            }

            $driverData = $driver->data();

            if (!isset($driverData['location']) || 
                !isset($driverData['location']['latitude']) || 
                !isset($driverData['location']['longitude'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Driver location not available',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Driver location fetched successfully',
                'data' => [
                    'id' => $driverId,
                    'location' => [
                        'latitude' => $driverData['location']['latitude'],
                        'longitude' => $driverData['location']['longitude'],
                    ],
                    'name' => trim(($driverData['firstName'] ?? '') . ' ' . ($driverData['lastName'] ?? '')),
                    'phone' => ($driverData['countryCode'] ?? '') . ($driverData['phoneNumber'] ?? ''),
                    'is_active' => $driverData['isActive'] ?? false,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error fetching driver location: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get multiple driver locations by IDs (batch update)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchDriverLocations(Request $request)
    {
        $driverIds = $request->input('driver_ids', []);

        if (empty($driverIds) || !is_array($driverIds)) {
            return response()->json([
                'status' => false,
                'message' => 'driver_ids array is required',
            ], 400);
        }

        $drivers = [];

        foreach ($driverIds as $driverId) {
            try {
                $driver = $this->firestore
                    ->collection('users')
                    ->document($driverId)
                    ->snapshot();

                if ($driver->exists()) {
                    $driverData = $driver->data();
                    
                    if (isset($driverData['location']['latitude']) && 
                        isset($driverData['location']['longitude'])) {
                        $drivers[] = [
                            'id' => $driverId,
                            'location' => [
                                'latitude' => $driverData['location']['latitude'],
                                'longitude' => $driverData['location']['longitude'],
                            ],
                            'name' => trim(($driverData['firstName'] ?? '') . ' ' . ($driverData['lastName'] ?? '')),
                            'phone' => ($driverData['countryCode'] ?? '') . ($driverData['phoneNumber'] ?? ''),
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Skip this driver if there's an error
                continue;
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Driver locations fetched successfully',
            'meta' => [
                'requested' => count($driverIds),
                'found' => count($drivers),
            ],
            'data' => $drivers,
        ]);
    }

    /**
     * Get in-transit orders with driver and customer details
     * 
     * @return array
     */
    private function getInTransitOrders()
    {
        $orders = [];

        try {
            $snapshot = $this->firestore
                ->collection('restaurant_orders')
                ->where('status', '==', 'In Transit')
                ->documents();

            foreach ($snapshot as $doc) {
                $orderData = $doc->data();
                $orderId = $doc->id();

                // Get driver details if available
                $driver = null;
                if (isset($orderData['driverID']) && !empty($orderData['driverID'])) {
                    $driver = $this->getActiveDriverById($orderData['driverID']);
                }

                // Skip if no driver or driver has no location
                if (!$driver || !isset($driver['location'])) {
                    continue;
                }

                // Build pickup location
                $pickupLocation = 'Pickup location not available';
                if (isset($orderData['vendor']['location'])) {
                    $pickupLocation = $orderData['vendor']['location'];
                }

                // Build destination address
                $destination = $this->buildDestinationAddress($orderData);

                // Determine order type
                $orderType = isset($orderData['takeAway']) && $orderData['takeAway'] === true ? 'Takeaway' : 'Delivery';

                $orders[] = [
                    'order_id' => $orderData['id'] ?? $orderId,
                    'flag' => 'in_transit',
                    'driver' => [
                        'id' => $driver['id'],
                        'name' => $driver['name'],
                        'phone' => $driver['phone'],
                        'location' => $driver['location'],
                    ],
                    'customer' => [
                        'id' => $orderData['authorID'] ?? '',
                        'name' => trim(($orderData['author']['firstName'] ?? '') . ' ' . ($orderData['author']['lastName'] ?? '')),
                        'phone' => ($orderData['author']['countryCode'] ?? '') . ($orderData['author']['phoneNumber'] ?? ''),
                    ],
                    'restaurant' => [
                        'id' => $orderData['vendorID'] ?? '',
                        'name' => $orderData['vendor']['title'] ?? 'N/A',
                    ],
                    'pickup_location' => $pickupLocation,
                    'destination' => $destination,
                    'order_type' => $orderType,
                    'status' => 'In Transit',
                ];
            }
        } catch (\Exception $e) {
            // Log error but return empty array
            \Log::error('Error fetching in-transit orders: ' . $e->getMessage());
        }

        return $orders;
    }

    /**
     * Get available drivers (not on a trip, with location)
     * 
     * @param array $inTransitOrders
     * @return array
     */
    private function getAvailableDrivers($inTransitOrders)
    {
        $drivers = [];
        $busyDriverIds = [];

        // Collect driver IDs that are busy
        foreach ($inTransitOrders as $order) {
            if (isset($order['driver']['id'])) {
                $busyDriverIds[] = $order['driver']['id'];
            }
        }

        try {
            $snapshot = $this->firestore
                ->collection('users')
                ->where('role', '==', 'driver')
                ->documents();

            foreach ($snapshot as $doc) {
                $driverData = $doc->data();
                $driverId = $driverData['id'] ?? $doc->id();

                // Skip if driver is busy
                if (in_array($driverId, $busyDriverIds)) {
                    continue;
                }

                // Check if driver has valid location
                if (!isset($driverData['location']['latitude']) || 
                    !isset($driverData['location']['longitude']) ||
                    is_null($driverData['location']['latitude']) ||
                    is_null($driverData['location']['longitude'])) {
                    continue;
                }

                $drivers[] = [
                    'id' => $driverId,
                    'flag' => 'available',
                    'name' => trim(($driverData['firstName'] ?? '') . ' ' . ($driverData['lastName'] ?? '')),
                    'phone' => ($driverData['countryCode'] ?? '') . ($driverData['phoneNumber'] ?? ''),
                    'location' => [
                        'latitude' => $driverData['location']['latitude'],
                        'longitude' => $driverData['location']['longitude'],
                    ],
                    'is_active' => $driverData['isActive'] ?? false,
                    'online' => !empty($driverData['fcmToken'] ?? ''),
                ];
            }
        } catch (\Exception $e) {
            \Log::error('Error fetching available drivers: ' . $e->getMessage());
        }

        return $drivers;
    }

    /**
     * Get active driver by ID
     * 
     * @param string $driverId
     * @return array|null
     */
    private function getActiveDriverById($driverId)
    {
        try {
            $snapshot = $this->firestore
                ->collection('users')
                ->where('isActive', '==', true)
                ->where('id', '==', $driverId)
                ->limit(1)
                ->documents();

            foreach ($snapshot as $doc) {
                $driverData = $doc->data();
                
                return [
                    'id' => $driverData['id'] ?? $doc->id(),
                    'name' => trim(($driverData['firstName'] ?? '') . ' ' . ($driverData['lastName'] ?? '')),
                    'phone' => ($driverData['countryCode'] ?? '') . ($driverData['phoneNumber'] ?? ''),
                    'location' => $driverData['location'] ?? null,
                ];
            }
        } catch (\Exception $e) {
            \Log::error('Error fetching driver: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Build destination address from order data
     * 
     * @param array $orderData
     * @return string
     */
    private function buildDestinationAddress($orderData)
    {
        // Check if it's a takeaway order
        if (isset($orderData['takeAway']) && $orderData['takeAway'] === true) {
            return 'Customer pickup at restaurant';
        }

        $destinationParts = [];

        if (isset($orderData['author']['shippingAddress'])) {
            $address = $orderData['author']['shippingAddress'];

            if (!empty($address['line1']) && $address['line1'] !== 'null') {
                $destinationParts[] = $address['line1'];
            }
            if (!empty($address['line2']) && $address['line2'] !== 'null') {
                $destinationParts[] = $address['line2'];
            }
            if (!empty($address['city']) && $address['city'] !== 'null') {
                $destinationParts[] = $address['city'];
            }
            if (!empty($address['country']) && $address['country'] !== 'null') {
                $destinationParts[] = $address['country'];
            }
        }

        if (!empty($destinationParts)) {
            return implode(', ', $destinationParts);
        }

        return 'Destination address not available';
    }
}

