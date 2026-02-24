<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShippingAddressController extends Controller
{
    /**
     * Fetch user's shipping addresses.
     *
     * Routes examples:
     * GET /api/users/{userId}/shipping-address
     * GET /api/users/shipping-address?phone=9999999999
     */
    public function show(Request $request, $userId = null)
    {
        try {
            // 🔹 Step 1: Identify the user
            if ($userId) {
                $user = User::where('id', $userId)
                    ->orWhere('firebase_id', $userId)
                    ->first();
            } elseif ($request->query('phone')) {
                $user = User::where('phone', $request->query('phone'))->first();
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing user identifier. Provide route userId, firebase_id, or ?phone=.',
                ], 400);
            }
            // 🔹 Step 2: Handle not found
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            // 🔹 Step 3: Get and parse the shipping addresses
            $addresses = $this->parseShippingAddress($user->shippingAddress);

            // 🔹 Step 4: Send standardized response
            return response()->json([
                'success' => true,
                'data' => array_values($addresses),
            ]);

        } catch (\Throwable $e) {
            Log::error('Failed fetching shipping address', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'userId' => $userId ?? $request->query('phone'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch shipping address',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Update user's shipping addresses (replace or merge).
     *
     * Routes examples:
     * PUT /api/users/{userId}/shipping-address
     * POST /api/users/{userId}/shipping-address?merge=true
     *
     * Optionally you can call by phone: POST /api/users/shipping-address?phone=9999999999
     */
    public function update(Request $request, $userId = null)
    {
        try {
            // Identify user by route param `userId` OR query param `phone`
            if ($userId) {
                // Try finding by numeric id OR firebase_id
                $user = User::where('id', $userId)
                            ->orWhere('firebase_id', $userId)
                            ->first();
            } elseif ($request->query('phone')) {
                $user = User::where('phone', $request->query('phone'))->first();
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing user identifier. Provide route userId, firebase_id, or ?phone=.',
                ], 400);
            }


            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            // Get raw JSON body (accept array or single object)
            $payload = $request->json()->all();
            if ($payload === null) {
                $payload = [];
            }

            if (!is_array($payload)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid payload. Expected JSON array or object.',
                ], 400);
            }

            if (Arr::isAssoc($payload)) {
                $payload = [$payload];
            }

            // Merge flag ?merge=true or POST field merge=true
            $mergeMode = $request->query('merge', $request->input('merge', false));
            $mergeMode = filter_var($mergeMode, FILTER_VALIDATE_BOOLEAN);

            // Load existing shippingAddress (if any) and make sure it's an array
            $existing = [];
            $rawExisting = $user->shippingAddress;
            if (!empty($rawExisting)) {
                if (is_string($rawExisting)) {
                    $decoded = json_decode($rawExisting, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $existing = $decoded;
                    }
                } elseif (is_array($rawExisting)) {
                    $existing = $rawExisting;
                }
            }

            // Prepare result array
            if ($mergeMode) {
                // Build associative map by id for existing addresses
                $map = [];

                foreach ($existing as $addr) {
                    if (isset($addr['id']) && $addr['id'] !== '') {
                        $map[$addr['id']] = $addr;
                    } else {
                        // keep addresses without id using generated key to avoid overwrite
                        $map[Str::uuid()->toString() . '_old'] = $addr;
                    }
                }

                // Merge incoming: incoming entries overwrite existing with same id
                foreach ($payload as $addr) {
                    if (isset($addr['id']) && $addr['id'] !== '') {
                        $map[$addr['id']] = $addr;
                    } else {
                        // assign uuid for incoming without id
                        $addr['id'] = Str::uuid()->toString();
                        $map[$addr['id']] = $addr;
                    }
                }

                // Final addresses are values of map (reset numeric keys)
                $finalAddresses = array_values($map);
            } else {
                // Replace mode: take payload as-is but ensure all items have id
                $finalAddresses = [];
                foreach ($payload as $addr) {
                    if (!isset($addr['id']) || empty($addr['id'])) {
                        $addr['id'] = Str::uuid()->toString();
                    }
                    $finalAddresses[] = $addr;
                }
            }

            // Optional: ensure only one isDefault = true (if present)
            $defaultCount = 0;
            foreach ($finalAddresses as $a) {
                if (isset($a['isDefault'])) {
                    // accept both integer and boolean
                    if ($a['isDefault'] === true || $a['isDefault'] === 1 || $a['isDefault'] === '1') {
                        $defaultCount++;
                    }
                }
            }
            if ($defaultCount > 1) {
                // If multiple defaults exist, keep the last one as default, unset others
                $seenDefault = false;
                for ($i = count($finalAddresses) - 1; $i >= 0; $i--) {
                    if (isset($finalAddresses[$i]['isDefault']) &&
                        ($finalAddresses[$i]['isDefault'] === true || $finalAddresses[$i]['isDefault'] === 1 || $finalAddresses[$i]['isDefault'] === '1')) {
                        if ($seenDefault === false) {
                            // keep this as default
                            $seenDefault = true;
                            $finalAddresses[$i]['isDefault'] = 1;
                        } else {
                            $finalAddresses[$i]['isDefault'] = 0;
                        }
                    }
                }
            }

            // Save within DB transaction
            DB::transaction(function () use ($user, $finalAddresses) {
                $user->shippingAddress = json_encode($finalAddresses, JSON_UNESCAPED_UNICODE);
                $user->save();
            });

            return response()->json([
                'success' => true,
                'message' => 'Shipping address updated.',
                'data' => $finalAddresses,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed updating shipping address', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'userId' => $userId ?? $request->query('phone'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update shipping address',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }



    private function parseShippingAddress($shippingAddress)
    {
        if (empty($shippingAddress)) {
            return [];
        }

        // Decode if it's a JSON string
        if (is_string($shippingAddress)) {
            try {
                $decoded = json_decode($shippingAddress, true);
                if (is_array($decoded)) {
                    $shippingAddress = $decoded;
                } else {
                    return [];
                }
            } catch (\Exception $e) {
                Log::error('Error parsing shipping address: ' . $e->getMessage());
                return [];
            }
        }

        // Validate array type
        if (!is_array($shippingAddress)) {
            return [];
        }

        // If it's a single address object, wrap it in an array
        if (isset($shippingAddress['address']) || isset($shippingAddress['locality'])) {
            $shippingAddress = [$shippingAddress];
        }

        // Normalize each address
        return array_map(function ($addr) {
            if (!is_array($addr)) {
                return null;
            }

            return [
                'id' => $addr['id'] ?? null,
                'label' => $addr['label'] ?? '',              // ✅ Added
                'address' => $addr['address'] ?? '',
                'addressAs' => $addr['addressAs'] ?? '',
                'landmark' => $addr['landmark'] ?? '',
                'city' => $addr['city'] ?? '',                // ✅ Added
                'pincode' => $addr['pincode'] ?? '',          // ✅ Added
                'locality' => $addr['locality'] ?? '',

                'latitude' => (float) ($addr['latitude'] ?? ($addr['location']['latitude'] ?? 0)),
                'longitude' => (float) ($addr['longitude'] ?? ($addr['location']['longitude'] ?? 0)),
                'isDefault' => (bool) ($addr['isDefault'] ?? false),
                'zoneId' => $addr['zoneId'] ?? null,
            ];
        }, array_filter($shippingAddress));
    }




    public function delete(Request $request, $userId = null, $addressId = null)
    {
        try {
            // Step 1: Validate inputs
            if (empty($addressId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing address ID.',
                ], 400);
            }

            // Step 2: Identify user (by route param or ?phone)
            if ($userId) {
                $user = User::where('id', $userId)
                    ->orWhere('firebase_id', $userId)
                    ->first();
            } elseif ($request->query('phone')) {
                $user = User::where('phone', $request->query('phone'))->first();
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing user identifier. Provide userId, firebase_id, or ?phone=.',
                ], 400);
            }

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.',
                ], 404);
            }

            // Step 3: Decode shippingAddress field
            $existing = [];
            $raw = $user->shippingAddress;

            if (!empty($raw)) {
                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $existing = $decoded;
                    }
                } elseif (is_array($raw)) {
                    $existing = $raw;
                }
            }

            // Step 4: Filter out the address by id
            $filtered = array_filter($existing, function ($addr) use ($addressId) {
                return isset($addr['id']) && $addr['id'] !== $addressId;
            });

            // Check if anything was actually removed
            if (count($filtered) === count($existing)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Address not found for deletion.',
                ], 404);
            }

            // Step 5: Save updated list
            DB::transaction(function () use ($user, $filtered) {
                $user->shippingAddress = json_encode(array_values($filtered), JSON_UNESCAPED_UNICODE);
                $user->save();
            });

            return response()->json([
                'success' => true,
                'message' => 'Shipping address deleted successfully.',
                'data' => array_values($filtered),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed deleting shipping address', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'userId' => $userId ?? $request->query('phone'),
                'addressId' => $addressId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete shipping address.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }


}
