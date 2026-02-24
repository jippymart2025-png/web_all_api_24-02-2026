<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use \Illuminate\Http\JsonResponse;

class SettingsApiController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth')->except([
            'mobileSettings',
            'getDeliveryChargeSettings',
            'getVendorAttributes',
        ]);
    }

    /**
     * Get all settings needed for the layout
     */
    public function getAllSettings()
    {
        try {
            $settings = [
                'globalSettings' => $this->getGlobalSettings(),
                'distanceSettings' => $this->getDistanceSettings(),
                'languages' => $this->getLanguages(),
                'version' => $this->getVersion(),
                'mapSettings' => $this->getMapSettings(),
                'notificationSettings' => $this->getNotificationSettings(),
                'currency' => $this->getCurrencySettings(),
            ];

            return response()->json([
                'success' => true,
                'data' => $settings
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching all settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching settings'
            ], 500);
        }
    }

    /**
     * Get global settings
     */
    public function getGlobalSettings()
    {
        try {
            // Settings table structure: id (auto-increment), document_name (unique), fields (JSON)
            $setting = DB::table('settings')
                ->where('document_name', 'globalSettings')
                ->first();

            if ($setting && !empty($setting->fields)) {
                $data = json_decode($setting->fields, true);
                return $data ?? [];
            }

            // Return defaults if not found
            return [
                'appLogo' => '',
                'meta_title' => 'Jippy Mart',
                'applicationName' => 'Jippy Mart',
                'web_panel_color' => '#FF683A',
                'order_ringtone_url' => ''
            ];

        } catch (\Exception $e) {
            Log::error('Error fetching global settings: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get distance settings
     */
    public function getDistanceSettings()
    {
        try {
            $setting = DB::table('settings')
                ->where('document_name', 'RestaurantNearBy')
                ->first();

            if ($setting && !empty($setting->fields)) {
                $data = json_decode($setting->fields, true);
                return $data ?? [];
            }

            return [
                'distanceType' => 'km',
                'radios' => '15'
            ];

        } catch (\Exception $e) {
            Log::error('Error fetching distance settings: ' . $e->getMessage());
            return ['distanceType' => 'km'];
        }
    }

    /**
     * Get languages
     */
    public function getLanguages()
    {
        try {
            $setting = DB::table('settings')
                ->where('document_name', 'languages')
                ->first();

            if ($setting && !empty($setting->fields)) {
                $data = json_decode($setting->fields, true);
                if (isset($data['list'])) {
                    return $data['list'];
                }
            }

            // Return default English if not found
            return [[
                'title' => 'English',
                'slug' => 'en',
                'isActive' => true,
                'is_rtl' => false
            ]];

        } catch (\Exception $e) {
            Log::error('Error fetching languages: ' . $e->getMessage());
            return [[
                'title' => 'English',
                'slug' => 'en',
                'isActive' => true
            ]];
        }
    }

    /**
     * Get version information
     */
    public function getVersion()
    {
        try {
            $setting = DB::table('settings')
                ->where('document_name', 'Version')
                ->first();

            if ($setting && !empty($setting->fields)) {
                $data = json_decode($setting->fields, true);
                return $data ?? [];
            }

            return [
                'web_version' => '2.5.0',
                'app_version' => '2.5.0'
            ];

        } catch (\Exception $e) {
            Log::error('Error fetching version: ' . $e->getMessage());
            return ['web_version' => '2.5.0'];
        }
    }

    /**
     * Get map settings
     */
    public function getMapSettings()
    {
        try {
            $driverNearBy = DB::table('settings')
                ->where('document_name', 'DriverNearBy')
                ->first();

            $googleMapKey = DB::table('settings')
                ->where('document_name', 'googleMapKey')
                ->first();

            $data = [];

            if ($driverNearBy && !empty($driverNearBy->fields)) {
                $driverData = json_decode($driverNearBy->fields, true);
                $data['selectedMapType'] = $driverData['selectedMapType'] ?? 'google';
            }

            if ($googleMapKey && !empty($googleMapKey->fields)) {
                $keyData = json_decode($googleMapKey->fields, true);
                $data['googleMapKey'] = $keyData['key'] ?? '';
            }

            return $data;

        } catch (\Exception $e) {
            Log::error('Error fetching map settings: ' . $e->getMessage());
            return ['selectedMapType' => 'google'];
        }
    }

    /**
     * Get notification settings
     */
    public function getNotificationSettings()
    {
        try {
            $setting = DB::table('settings')
                ->where('document_name', 'notification_setting')
                ->first();

            if ($setting && !empty($setting->fields)) {
                $data = json_decode($setting->fields, true);
                return $data ?? [];
            }

            return [];

        } catch (\Exception $e) {
            Log::error('Error fetching notification settings: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get currency settings
     */
    public function getCurrencySettings()
    {
        try {
            $currency = DB::table('currencies')
                ->where('isActive', 1)
                ->first();

            if ($currency) {
                return response()->json([
                    'symbol' => $currency->symbol ?? '₹',
                    'code' => $currency->code ?? 'INR',
                    'name' => $currency->name ?? 'Indian Rupee',
                    'symbolAtRight' => $currency->symbolAtRight ?? false,
                    'decimal_degits' => $currency->decimal_degits ?? 2,
                ]);
            }

            return response()->json([
                'symbol' => '₹',
                'code' => 'INR',
                'symbolAtRight' => false,
                'decimal_degits' => 2
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching currency settings: ' . $e->getMessage());
            return response()->json(['symbol' => '₹']);
        }
    }

    /**
     * Get restaurant nearby settings
     */
    public function getRestaurantSettings()
    {
        try {
            $setting = DB::table('settings')
                ->where('document_name', 'RestaurantNearBy')
                ->first();

            if ($setting && !empty($setting->fields)) {
                $data = json_decode($setting->fields, true);
                return response()->json($data ?? []);
            }

            return response()->json([
                'distanceType' => 'km',
                'radios' => '15',
                'driverRadios' => '5'
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching restaurant settings: ' . $e->getMessage());
            return response()->json(['distanceType' => 'km']);
        }
    }

    /**
     * Get admin commission settings
     */
    public function getAdminCommission()
    {
        try {
            $setting = DB::table('settings')
                ->where('document_name', 'AdminCommission')
                ->first();

            if ($setting && !empty($setting->fields)) {
                $data = json_decode($setting->fields, true);
                return response()->json($data ?? []);
            }

            return response()->json([
                'isEnabled' => false,
                'commissionType' => 'Percent',
                'fix_commission' => 0
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching admin commission: ' . $e->getMessage());
            return response()->json(['isEnabled' => false]);
        }
    }

    /**
     * Get driver nearby settings
     */
    public function getDriverSettings()
    {
        try {
            $setting = DB::table('settings')
                ->where('document_name', 'DriverNearBy')
                ->first();

            if ($setting && !empty($setting->fields)) {
                $data = json_decode($setting->fields, true);
                return response()->json($data ?? []);
            }

            return response()->json([
                'driverRadios' => '5',
                'mapType' => 'inappmap',
                'selectedMapType' => 'google'
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching driver settings: ' . $e->getMessage());
            return response()->json([]);
        }
    }

    /**
     * Unified settings payload for mobile clients (replaces Firestore listeners)
     * OPTIMIZED: Cached for 24 hours for fast loading
     */
    public function mobileSettings(Request $request = null)
    {
        try {
            /** ---------------------------------------
             * CACHE: Check cache FIRST - before any DB operations
             * This ensures zero database hits when cache exists
             * ------------------------------------- */
            $cacheKey = 'mobile_settings_v1';
            $cacheTTL = 86400; // 24 hours (86400 seconds)

            // Check if force refresh is requested
            $forceRefresh = $request && $request->boolean('refresh', false);

            // CRITICAL: Check cache BEFORE any database operations
            // This ensures zero DB queries when cache exists
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    // Return cached response immediately - NO database queries executed
                    return response()->json($cachedResponse);
                }
            }

            /** ---------------------------------------
             * Only execute DB queries if cache miss or force refresh
             * ------------------------------------- */
            // List of required documents
            $documents = [
                'restaurant', 'RestaurantNearBy', 'DriverNearBy', 'globalSettings', 'googleMapKey',
                'notification_setting', 'privacyPolicy', 'termsAndConditions', 'walletSettings',
                'WalletSetting', 'Version', 'story', 'referral_amount', 'placeHolderImage',
                'emailSetting', 'specialDiscountOffer', 'DineinForRestaurant', 'AdminCommission',
                'DeliveryCharge', 'martDeliveryCharge', 'PriceSettings', 'payment', 'languages',
                'digitalProduct', 'driver_total_charges', 'CODSettings'
            ];

            // Fetch once — optimized
            $settingsRows = DB::table('settings')
                ->whereIn('document_name', $documents)
                ->get();

            // Build final key-value config map (FAST)
            $settings = $settingsRows
                ->keyBy('document_name')
                ->map(fn ($row) => $this->decodeSetting($row->fields))
                ->toArray();

            // Shortcut variables ↓ (avoids repeated array lookups)
            $restaurant        = $settings['restaurant']        ?? [];
            $nearBy            = $settings['RestaurantNearBy']  ?? [];
            $driverNearBy      = $settings['DriverNearBy']      ?? [];
            $global            = $settings['globalSettings']    ?? [];
            $googleMapKey      = $settings['googleMapKey']      ?? [];
            $notification      = $settings['notification_setting'] ?? [];
            $privacyPolicy     = $settings['privacyPolicy']     ?? [];
            $terms             = $settings['termsAndConditions'] ?? [];
            $version           = $settings['Version']           ?? [];
            $story             = $settings['story']             ?? [];
            $referral          = $settings['referral_amount']   ?? [];
            $placeHolder       = $settings['placeHolderImage']  ?? [];
            $special           = $settings['specialDiscountOffer'] ?? [];
            $dinein            = $settings['DineinForRestaurant'] ?? [];
            $wallet1           = $settings['walletSettings'] ?? [];
            $wallet2           = $settings['WalletSetting']  ?? [];

            // Resolve currency only once
            $currency = $this->resolveCurrency();

            // Derived settings – optimized for speed
            $derived = [
                'isSubscriptionModelApplied' => (bool)($restaurant['subscription_model'] ?? false),
                'autoApproveRestaurant'      => (bool)($restaurant['auto_approve_restaurant'] ?? false),

                'radius'        => $nearBy['radios'] ?? null,
                'driverRadios'  => $driverNearBy['driverRadios'] ?? null,
                'distanceType'  => $nearBy['distanceType'] ?? null,

                'isEnableAdsFeature'    => (bool)($global['isEnableAdsFeature'] ?? false),
                'isSelfDeliveryFeature' => (bool)($global['isSelfDelivery'] ?? false),

                'themeColors' => [
                    'app_customer_color'   => $global['app_customer_color'] ?? null,
                    'app_driver_color'     => $global['app_driver_color'] ?? null,
                    'app_restaurant_color' => $global['app_restaurant_color'] ?? null,
                ],

                'mapAPIKey' => $googleMapKey['key'] ?? '',
                'placeHolderImage' => $googleMapKey['placeHolderImage']
                    ?? ($placeHolder['image'] ?? ''),

                'senderId' => $notification['projectId'] ?? '',
                'jsonNotificationFileURL' => $notification['serviceJson'] ?? '',

                'selectedMapType' => $driverNearBy['selectedMapType'] ?? null,
                'mapType'         => $driverNearBy['mapType'] ?? null,

                'privacyPolicy'     => $privacyPolicy['privacy_policy'] ?? '',
                'termsAndConditions'=> $terms['termsAndConditions'] ?? '',

                'walletEnabled' => (bool)(
                    $wallet1['isEnabled'] ??
                    $wallet2['isEnabled'] ??
                    false
                ),

                'googlePlayLink' => $version['googlePlayLink'] ?? '',
                'appStoreLink'   => $version['appStoreLink'] ?? '',
                'appVersion'     => $version['app_version'] ?? '',
                'websiteUrl'     => $version['websiteUrl'] ?? '',

                'storyEnable' => (bool)($story['isEnabled'] ?? false),

                'referralAmount' => $referral['referralAmount'] ?? '0',
                'placeholderImage' => $placeHolder['image'] ?? '',

                'specialDiscountOffer' => (bool)($special['isEnable'] ?? false),
                'isEnabledForCustomer' => (bool)($dinein['isEnabledForCustomer'] ?? false),

                'adminCommission' => $settings['AdminCommission'] ?? [],
                'mailSettings'    => $settings['emailSetting']   ?? [],

                'currency' => $currency,
            ];

            /** ---------------------------------------
             * RESPONSE: Build and cache response
             * ------------------------------------- */
            $response = [
                'success' => true,
                'data' => [
                    'documents' => $settings,
                    'derived'   => $derived,
                ]
            ];

            // Cache the response
            try {
                Cache::put($cacheKey, $response, $cacheTTL);
            } catch (\Throwable $cacheError) {
                Log::warning('Failed to cache mobile settings response', [
                    'cache_key' => $cacheKey,
                    'error' => $cacheError->getMessage(),
                ]);
                // Continue without caching if cache fails
            }

            return response()->json($response);

        } catch (\Throwable $e) {
            Log::error('Error in mobileSettings: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch settings right now.'
            ], 500);
        }
    }

    /**
     * Decode a JSON settings payload safely.
     */
    protected function decodeSetting(?string $payload): array
    {
        if (empty($payload)) {
            return [];
        }

        $decoded = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * Resolve active currency information.
     */
    protected function resolveCurrency(): array
    {
        $currency = DB::table('currencies')
            ->where('isActive', 1)
            ->first();

        if ($currency) {
            return [
                'symbol' => $currency->symbol ?? '₹',
                'code' => $currency->code ?? 'INR',
                'name' => $currency->name ?? 'Indian Rupee',
                'symbolAtRight' => (bool)($currency->symbolAtRight ?? false),
                'decimal_digits' => (int)($currency->decimal_degits ?? 2),
            ];
        }

        return [
            'symbol' => '₹',
            'code' => 'INR',
            'name' => 'Indian Rupee',
            'symbolAtRight' => false,
            'decimal_digits' => 2,
        ];
    }

    /**
     * Fetch delivery charge settings only.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDeliveryChargeSettings(Request $request = null)
    {
        try {
            /** ---------------------------------------
             * CACHE: Check cache FIRST - before any DB operations
             * ------------------------------------- */
            $cacheKey = 'delivery_charge_settings_v1';
            $cacheTTL = 86400; // 24 hours

            // Check if force refresh is requested
            $forceRefresh = $request && $request->boolean('refresh', false);

            // CRITICAL: Check cache BEFORE any database operations
            if (!$forceRefresh) {
                $cachedResponse = Cache::get($cacheKey);
                if ($cachedResponse !== null) {
                    // Return cached response immediately - NO database queries executed
                    return response()->json($cachedResponse);
                }
            }

            /** ---------------------------------------
             * Only execute DB queries if cache miss or force refresh
             * ------------------------------------- */
            // Fetch record from DB
            $setting = DB::table('settings')
                ->where('document_name', 'DeliveryCharge')
                ->first();

            $data = [];

            if ($setting && !empty($setting->fields)) {
                $decoded = json_decode($setting->fields, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $data = $decoded;
                } else {
                    Log::warning('Invalid JSON format in DeliveryCharge settings, using empty array.');
                }
            } else {
                Log::info('No DeliveryCharge settings found, using empty array.');
            }

            /** ---------------------------------------
             * RESPONSE: Build and cache response
             * ------------------------------------- */
            $response = [
                'success' => true,
                'data' => $data,
            ];

            // Cache the response
            try {
                Cache::put($cacheKey, $response, $cacheTTL);
            } catch (\Throwable $cacheError) {
                Log::warning('Failed to cache delivery charge settings response', [
                    'cache_key' => $cacheKey,
                    'error' => $cacheError->getMessage(),
                ]);
                // Continue without caching if cache fails
            }

            return response()->json($response);
        } catch (\Throwable $e) {
            Log::error('Error fetching delivery charge settings: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch delivery charge settings.',
            ], 500);
        }
    }



    public function getVendorAttributes()
    {
        try {
            $attributes = DB::table('vendor_attributes')->get();

            return response()->json([
                'success' => true,
                'data' => $attributes,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error fetching vendor attributes: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch vendor attributes.',
            ], 500);
        }
    }


}

