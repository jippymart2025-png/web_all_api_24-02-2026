<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class restaurant_orders extends Model
{
    use HasFactory;
    public $timestamps = false;
    public $incrementing = false; // very important for non-auto-incrementing IDs
    protected $table = 'restaurant_orders';
    protected $guarded = [];

    /**
     * Build the DataTables dataset using raw SQL/joins here (not in controller).
     * Returns [rows => Collection, recordsFiltered => int].
     */
    public static function fetchForDatatable(array $params): array
    {
        $vendorId = (string) ($params['vendor_id'] ?? '');
        $userId = (string) ($params['user_id'] ?? '');
        $driverId = (string) ($params['driver_id'] ?? '');
        $status = (string) ($params['status'] ?? '');
        $zoneId = (string) ($params['zone_id'] ?? '');
        $orderType = (string) ($params['order_type'] ?? '');
        $dateFrom = (string) ($params['date_from'] ?? '');
        $dateTo = (string) ($params['date_to'] ?? '');
        $searchValue = strtolower((string) ($params['search'] ?? ''));
        $orderBy = (string) ($params['order_by'] ?? 'ro.id');
        $orderDir = (string) ($params['order_dir'] ?? 'desc');
        $start = (int) ($params['start'] ?? 0);
        $length = (int) ($params['length'] ?? 10);
        $paymentType = strtolower(trim((string) ($params['payment_type'] ?? '')));

        $query = DB::table('restaurant_orders as ro')
            ->leftJoin('vendors as v', 'v.id', '=', 'ro.vendorID')
            ->leftJoin('users as u_client', 'u_client.id', '=', 'ro.authorID')   // 👤 client
            ->leftJoin('users as u_driver', 'u_driver.id', '=', 'ro.driverID')   // 🚗 driver
            ->select(
                'ro.id',
                'ro.status',
                'ro.takeAway',
                'ro.createdAt',
                'ro.toPayAmount',
                'ro.products',
                'ro.discount',
                'ro.deliveryCharge',
                'ro.tip_amount',
                'ro.payment_method',
                'ro.specialDiscount',
                'ro.author',
                'ro.authorID',
                'ro.driver',
                'ro.driverID',
                'ro.promotion',
                'ro.refund_transaction_id',
                'v.id as vendor_id',
                'v.title as vendor_title',
                'v.vType as vendor_type',
                'v.zoneId as vendor_zone_id',
                'u_client.firstName as client_first_name',
                'u_client.lastName as client_last_name',
                'u_client.phoneNumber as client_phone',
                'u_client.email as client_email',
                'u_driver.firstName as driver_first_name',
                'u_driver.lastName as driver_last_name',
                'u_driver.phoneNumber as driver_phone',
                'u_driver.email as driver_email'
            );

        // ✅ Filters
        if ($vendorId !== '') $query->where('ro.vendorID', $vendorId);
        if ($userId !== '') $query->where('ro.authorID', $userId);
        if ($driverId !== '') $query->where('ro.driverID', $driverId);
        if ($status !== '' && strtolower($status) !== 'all') $query->where('ro.status', $status);
        if ($zoneId !== '') $query->where('v.zoneId', $zoneId);

        // ✅ Payment Type Filter
        if ($paymentType !== '') {
            $query->whereRaw('LOWER(ro.payment_method) = ?', [$paymentType]);
        }

        // ✅ Order Type
        if ($orderType === 'takeaway') {
            $query->where('ro.takeAway', '1');
        } elseif ($orderType === 'delivery') {
            $query->where(function ($q) {
                $q->whereNull('ro.takeAway')->orWhere('ro.takeAway', '0');
            });
        }

        // 📌 Date Filter (supports Today, Last 24h, Last Week, Last Month, Custom, All Orders)
        // Check for "all_orders" flag first
        if ($dateFrom === 'all_orders' && $dateTo === 'all_orders') {
            // Show all orders - skip date filtering entirely
            // Do nothing - no date filter applied
        } elseif ($dateFrom !== '' && $dateTo !== '') {
            // Date range provided - apply filter
            try {
                // Parse the dates - they come as "YYYY-MM-DD HH:mm:ss" from controller
                $from = Carbon::parse($dateFrom);
                $to = Carbon::parse($dateTo);

                // Use whereBetween with exact timestamps (controller already sets correct boundaries)
                $query->whereBetween('ro.createdAt', [$from, $to]);
            } catch (\Throwable $e) {
                \Log::error('❌ Date parsing failed', [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'error' => $e->getMessage()
                ]);
                // Fallback to today if parsing fails
                $query->whereDate('ro.createdAt', Carbon::today());
            }
        } else {
            // No date range selected - default to today only
            $query->whereDate('ro.createdAt', Carbon::today());
        }

        // ✅ Universal Search
        if ($searchValue !== '') {
            $query->where(function ($q) use ($searchValue) {
                $q->orWhereRaw('LOWER(ro.id) LIKE ?', ["%{$searchValue}%"])
                    ->orWhereRaw('LOWER(v.title) LIKE ?', ["%{$searchValue}%"])
                    ->orWhereRaw('LOWER(ro.status) LIKE ?', ["%{$searchValue}%"])

                    // 👤 Client Search
                    ->orWhereRaw('LOWER(CONCAT(u_client.firstName, " ", u_client.lastName)) LIKE ?', ["%{$searchValue}%"])
                    ->orWhereRaw('LOWER(u_client.firstName) LIKE ?', ["%{$searchValue}%"])
                    ->orWhereRaw('LOWER(u_client.lastName) LIKE ?', ["%{$searchValue}%"])
                    ->orWhereRaw('LOWER(u_client.email) LIKE ?', ["%{$searchValue}%"])
                    ->orWhereRaw('u_client.phoneNumber LIKE ?', ["%{$searchValue}%"])

                    // 🚗 Driver Search
                    ->orWhereRaw('LOWER(CONCAT(u_driver.firstName, " ", u_driver.lastName)) LIKE ?', ["%{$searchValue}%"])
                    ->orWhereRaw('LOWER(u_driver.firstName) LIKE ?', ["%{$searchValue}%"])
                    ->orWhereRaw('LOWER(u_driver.lastName) LIKE ?', ["%{$searchValue}%"])
                    ->orWhereRaw('LOWER(u_driver.email) LIKE ?', ["%{$searchValue}%"])
                    ->orWhereRaw('u_driver.phoneNumber LIKE ?', ["%{$searchValue}%"]);
            });
        }

        // ✅ Count & Paginate
        $recordsFiltered = (clone $query)->count();
        $rows = $query->orderBy($orderBy, $orderDir)
            ->skip($start)
            ->take($length)
            ->get();

        return [
            'rows' => $rows,
            'recordsFiltered' => $recordsFiltered,
        ];
    }

}
