<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MartItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TaxApiController extends Controller
{
    public function getTaxSettings()
    {
        try {
            // Fetch all tax records from the "tax" table
            $taxes = DB::table('tax')->get();

            return response()->json([
                'success' => true,
                'data' => $taxes,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching tax settings: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch tax settings.',
            ], 500);
        }
    }

}
