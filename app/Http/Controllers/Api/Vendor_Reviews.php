<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Vendor_Reviews extends Controller
{
    public function getVendorReviews($vendorId)
    {
        try {
            $reviews = DB::table('foods_review')
                ->where('VendorId', $vendorId)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $reviews,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching vendor reviews: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch vendor reviews.',
            ], 500);
        }
    }


    public function getOrderReviewById(Request $request)
    {
        try {
            $orderId = $request->query('orderid');
            $productId = $request->query('productId');

            if (!$orderId || !$productId) {
                return response()->json([
                    'success' => false,
                    'message' => 'orderid and productId are required.'
                ], 400);
            }

            $review = DB::table('foods_review')
                ->where('orderid', $orderId)
                ->where('productId', $productId)
                ->first();

            return response()->json([
                'success' => true,
                'data' => $review,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching review: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch review details.',
            ], 500);
        }
    }
    public function getReviewAttributeById($id)
    {
        try {
            $attribute = DB::table('review_attributes')->where('id', $id)->first();

            if (!$attribute) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review attribute not found.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $attribute,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching review attribute: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch review attribute.',
            ], 500);
        }
    }

}
