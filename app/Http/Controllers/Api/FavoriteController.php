<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FavoriteController extends Controller
{
    /**
     * Get all favorite restaurants for a user
     * GET /api/favorites/restaurants/{firebase_id}
     */
    public function getFavoriteRestaurants($firebaseId)
    {
        $user = User::where('firebase_id', $firebaseId)->first();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $userIdValues = array_unique(array_filter([$user->firebase_id, $user->id]));

        $favoriteRestaurantIds = DB::table('favorite_restaurant')
            ->whereIn('user_id', $userIdValues)
            ->pluck('restaurant_id');

        if ($favoriteRestaurantIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $favorites = Vendor::query()
            ->whereIn('id', $favoriteRestaurantIds)
            ->get()
            ->map(function ($item) {
                // ✅ Helper closure to safely decode JSON
                $safeDecode = function ($value) {
                    if (empty($value) || !is_string($value)) return $value;
                    $decoded = json_decode($value, true);
                    return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
                };

                // ✅ Decode relevant fields
                $item->restaurantMenuPhotos = $safeDecode($item->restaurantMenuPhotos);
                $item->photos = $safeDecode($item->photos);
                $item->workingHours = $safeDecode($item->workingHours);
                $item->filters = $safeDecode($item->filters);
                $item->coordinates = $safeDecode($item->coordinates);
                $item->lastAutoScheduleUpdate = $safeDecode($item->lastAutoScheduleUpdate);


                // ✅ (optional) decode more fields if you have them
                $item->categoryID = $safeDecode($item->categoryID);
                $item->categoryTitle = $safeDecode($item->categoryTitle);
                $item->specialDiscount = $safeDecode($item->specialDiscount);
                $item->adminCommission = $safeDecode($item->adminCommission);
                $item->g = $safeDecode($item->g);

                return $item;
            });

        return response()->json([
            'success' => true,
            'data' => $favorites,
        ]);
    }

    /**
     * Add a restaurant to favorites
     * POST /api/favorites/restaurants
     * Body: { "firebase_id": "...", "restaurant_id": 1 }
     */
    public function addFavoriteRestaurant(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firebase_id' => 'required|string',
            'restaurant_id' => 'required|string|exists:vendors,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $user = User::where('firebase_id', $request->firebase_id)->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not found'], 404);
        }

        DB::table('favorite_restaurant')->updateOrInsert([
            'user_id' => $user->firebase_id,
            'restaurant_id' => $request->restaurant_id,
        ]);

        return response()->json(['success' => true, 'message' => 'Restaurant added to favorites']);
    }

    /**
     * Remove a restaurant from favorites
     * DELETE /api/favorites/restaurants
     * Body: { "firebase_id": "...", "restaurant_id": 1 }
     */
    public function removeFavoriteRestaurant(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firebase_id' => 'required|string',
            'restaurant_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $user = User::where('firebase_id', $request->firebase_id)->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not found'], 404);
        }

        $userIdValues = array_unique(array_filter([$user->firebase_id, $user->id]));

        DB::table('favorite_restaurant')
            ->whereIn('user_id', $userIdValues)
            ->where('restaurant_id', $request->restaurant_id)
            ->delete();

        return response()->json(['success' => true, 'message' => 'Restaurant removed from favorites']);
    }

    /**
     * Get favorite items for a user
     * GET /api/favorites/items/{firebase_id}
     */
    public function getFavoriteItems($firebaseId)
    {
        $user = User::where('firebase_id', $firebaseId)->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not found'], 404);
        }

        $userIdValues = array_unique(array_filter([$user->firebase_id, $user->id]));

        $favoriteProductIds = DB::table('favorite_item')
            ->whereIn('user_id', $userIdValues)
            ->pluck('product_id');

        if ($favoriteProductIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $favorites = DB::table('vendor_products')
            ->whereIn('id', $favoriteProductIds)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $favorites,
        ]);
    }


    /**
     * Add favorite item
     * POST /api/favorites/items
     * Body: { "firebase_id": "...", "product_id": 1 }
     */
    public function addFavoriteItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firebase_id' => 'required|string',
            'product_id' => 'required|string|exists:vendor_products,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Get user by Firebase ID
        $user = User::where('firebase_id', $request->firebase_id)->first();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Get product details from mart_items
        $martItem = DB::table('vendor_products')->where('id', $request->product_id)->first();
        if (!$martItem) {
            return response()->json([
                'success' => false,
                'message' => 'Item not found in mart_items'
            ], 404);
        }

        // Insert or update favorite record including vendorID
        DB::table('favorite_item')->updateOrInsert(
            [
                'user_id' => $user->firebase_id, // use internal DB user id
                'product_id' => $request->product_id,
            ],
            [
                'store_id' => $martItem->vendorID, // pulled from mart_items
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Item added to favorites successfully',
        ]);
    }

    /**
     * Remove favorite item
     * DELETE /api/favorites/items
     * Body: { "firebase_id": "...", "product_id": 1 }
     */
    public function removeFavoriteItem(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firebase_id' => 'required|string',
            'product_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        // Use firebase_id only
        $userId = $request->firebase_id;

        DB::table('favorite_item')
            ->where('user_id', $userId)          // ✅ string only
            ->where('product_id', $request->product_id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Item removed from favorites'
        ]);
    }
}
