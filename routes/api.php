<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\Api\ChatadminController;
use App\Http\Controllers\Api\ChatDriverContoller;
use App\Http\Controllers\Api\ChatRestaurantController;
use App\Http\Controllers\Api\DriverControllerLogin;
use App\Http\Controllers\Api\DriverUserController;
use App\Http\Controllers\Api\DriverSqlBridgeController;
use App\Http\Controllers\Api\MobileSqlBridgeController;
use App\Http\Controllers\Api\OrderSupportController;
use App\Http\Controllers\Api\productcontroller;
use App\Http\Controllers\Api\FirestoreBridgeController;
use App\Http\Controllers\Api\RestaurantAppSettingController;
use App\Http\Controllers\Api\restaurantControllerLogin;
use App\Http\Controllers\Api\restaurantUserController;
use App\Http\Controllers\Api\restaurentrestpassword;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettingsApiController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\Vendor_Reviews;
use App\Http\Controllers\Api\WalletTransactionController;
use App\Http\Controllers\Api\WalletApiController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\SettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ZoneController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\MenuItemBannerController;
use App\Http\Controllers\Api\StoryController;
use App\Http\Controllers\Api\CouponApiController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\ShippingAddressController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\OrderApiController;
use App\Http\Controllers\Api\MartItemController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\Api\SwiggySearchController;
use App\Http\Controllers\Api\FirestoreUtilsController;
use App\Http\Controllers\Api\CacheController;




Route::get('/settings/ringtone', [SettingsController::class, 'getRingtone']);
Route::get('/orders/latest-id', [OrderController::class, 'getLatestOrderId']);
Route::get('/orders/get/{id}', [OrderController::class, 'getOrder']);

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

//Route::post('/login', [AuthController::class, 'login']);
//Route::middleware('auth:sanctum')->group(function () {
//    Route::get('/profile', [AuthController::class, 'profile']);
//    Route::post('/logout', [AuthController::class, 'logout']);
//});
//
//Route::middleware(['throttle:5,1'])->group(function () {
//    Route::get('/firebase/users', [FirebaseUserController::class, 'index']);
//    Route::get('/firebase/orders', [FirebaseOrderController::class, 'index']);
//
//    // Live tracking endpoints
//    Route::get('/firebase/live-tracking', [FirebaseLiveTrackingController::class, 'index']);
//    Route::get('/firebase/drivers/{driverId}/location', [FirebaseLiveTrackingController::class, 'getDriverLocation']);
//    Route::post('/firebase/drivers/locations', [FirebaseLiveTrackingController::class, 'batchDriverLocations']);
//});
//
// SQL users listing (replaces client-side Firebase usage on Users page)
//Route::get('/app-users', [AppUserController::class, 'index']);
//Route::post('/app-users', [AppUserController::class, 'store']);
//Route::delete('/app-users/{id}', [AppUserController::class, 'destroy']);
//Route::patch('/app-users/{id}/active', [AppUserController::class, 'setActive']);
// SQL users listing (replaces client-side Firebase usage on Users page)
Route::get('/app-users', [AdminUserController::class, 'index']);
Route::post('/app-users', [AdminUserController::class, 'store']);
Route::delete('/app-users/{id}', [AdminUserController::class, 'destroy']);
Route::patch('/app-users/{id}/active', [AdminUserController::class, 'setActive']);
Route::get('/app-users/export', [App\Http\Controllers\AdminUserController::class, 'export']);


Route::get('/settings/mobile', [SettingsApiController::class, 'mobileSettings'])
    ->withoutMiddleware(['throttle:api']);  // REMOVE default throttle
Route::get('/settings/delivery-charge', [SettingsApiController::class, 'getDeliveryChargeSettings'])
    ->withoutMiddleware(['throttle:api']);  // REMOVE default throttle

Route::get('/settings/tax', [App\Http\Controllers\Api\TaxApiController::class, 'gettaxSettings'])
    ->withoutMiddleware(['throttle:api']);  // REMOVE default throttle



Route::post('/send-otp', [App\Http\Controllers\Api\OTPController::class, 'sendOtp']);
Route::post('/verify-otp', [App\Http\Controllers\Api\OTPController::class, 'verifyOtp']);
Route::post('/resend-otp', [App\Http\Controllers\Api\OTPController::class, 'resendOtp']);
Route::post('/signup', [App\Http\Controllers\Api\OTPController::class, 'signUp']);

Route::post('/sms-delivery-status', [App\Http\Controllers\Api\OTPController::class, 'smsDeliveryStatus']);

// Debug route - remove in production
Route::get('/debug-otp/{phone}', [App\Http\Controllers\Api\OTPController::class, 'debugOtp']);




// Zone detection routes
Route::get('/zones/current', [ZoneController::class, 'getCurrentZone'])
    ->withoutMiddleware(['throttle:api']);
Route::get('/zones/detect-id', [ZoneController::class, 'detectZoneId'])
    ->withoutMiddleware(['throttle:api']);
Route::get('/zones/check-service-area', [ZoneController::class, 'checkServiceArea'])
    ->withoutMiddleware(['throttle:api']);
Route::get('/zones/all', [ZoneController::class, 'getAllZones'])
    ->withoutMiddleware(['throttle:api']);
Route::get('/zones/debug-zone-detection', [ZoneController::class, 'debugZoneDetection']) // Add this
 ->withoutMiddleware(['throttle:api']);


    Route::get('/mobile/brands/{brandId}', [MobileSqlBridgeController::class, 'fetchBrand']);
    Route::get('/mobile/surge-rules', [MobileSqlBridgeController::class, 'getSurgeRules']);
    Route::get('/mobile/surge-rules/admin-fee', [MobileSqlBridgeController::class, 'getAdminSurgeFee']);
    Route::get('/mobile/settings/mart-delivery-charge', [MobileSqlBridgeController::class, 'getMartDeliveryChargeSettings']);
    Route::get('/mobile/coupons/used', [MobileSqlBridgeController::class, 'getUsedCoupons']);
    Route::post('/mobile/coupons/{couponId}/used', [MobileSqlBridgeController::class, 'markCouponAsUsed']);
    Route::post('/mobile/orders', [MobileSqlBridgeController::class, 'createOrder']);
    Route::post('/mobile/orders/rollback', [MobileSqlBridgeController::class, 'rollbackFailedOrder']);
    Route::get('/mobile/orders/{orderId}/surge-fee', [MobileSqlBridgeController::class, 'getOrderSurgeFee']);
    Route::post('/mobile/orders/rollback-failed', [OrderSupportController::class, 'rollbackFailedOrder']);
    Route::post('/mobile/orders/place-basic', [OrderSupportController::class, 'placeOrder']);
    Route::post('/order-billing', [OrderSupportController::class, 'createOrderBilling']);
    Route::get('/mobile/app/version', [MobileSqlBridgeController::class, 'getLatestVersionInfo']);
    Route::post('/mobile/chat/restaurant/messages', [MobileSqlBridgeController::class, 'addRestaurantChat']);
    Route::post('/mobile/chat/restaurant/inbox', [MobileSqlBridgeController::class, 'addRestaurantInbox']);
    Route::post('/mobile/chat/driver/inbox', [MobileSqlBridgeController::class, 'addDriverInbox']);
    Route::post('/mobile/chat/driver/messages', [MobileSqlBridgeController::class, 'addDriverChat']);


Route::get('/mobile/orders/{orderId}/billing/surge-fee', [OrderSupportController::class, 'fetchOrderSurgeFee']);
Route::get('/mobile/orders/{orderId}/billing/to-pay', [OrderSupportController::class, 'fetchOrderToPay']);


// Restaurant/Vendor API routes
Route::get('/restaurants/nearest', [RestaurantController::class, 'nearest']);
Route::get('/restaurants/bestrestaurants', [RestaurantController::class, 'bestrestaurants']);
Route::get('/restaurants/search', [RestaurantController::class, 'search']);
Route::get('/restaurants/by-zone/{zone_id}', [RestaurantController::class, 'byZone']);
Route::get('/restaurants/{id}', [RestaurantController::class, 'show'])
    ->withoutMiddleware(['throttle:api']);


// Category API routes (Public - no auth required)
Route::get('/categories/home', [CategoryController::class, 'home'])
    ->withoutMiddleware(['throttle:api']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{id}', [CategoryController::class, 'show']);


// Banner API routes (Public - no auth required)
Route::get('/banners/top', [BannerController::class, 'top']);
Route::get('/banners', [BannerController::class, 'index']);
Route::get('/banners/{id}', [BannerController::class, 'show']);
// Menu Item Banner API routes (Public - no auth required)
Route::get('/menu-items/banners/top', [MenuItemBannerController::class, 'top']);
Route::get('/menu-items/banners/middle', [MenuItemBannerController::class, 'middle']);
Route::get('/menu-items/banners/bottom', [MenuItemBannerController::class, 'bottom']);
Route::get('/menu-items/banners/deals', [MenuItemBannerController::class, 'deals']);

//Route::get('/menu-items/banners', [MenuItemBannerController::class, 'index']);
Route::get('/menu-items/banners/{id}', [MenuItemBannerController::class, 'show']);

// Test route for debugging (REMOVE AFTER FIXING)
Route::get('/test-server', function () {
    return response()->json([
        'status' => 'SUCCESS - Laravel is working on HTTPS!',
        'timestamp' => now()->toDateTimeString(),
        'request' => [
            'is_https' => request()->secure(),
            'scheme' => request()->getScheme(),
            'host' => request()->getHost(),
        ],
    ]);
});

// Stories API routes (Public - no auth required)
Route::get('/stories', [StoryController::class, 'index']);


    Route::get('/users/{userId}/shipping-address', [ShippingAddressController::class, 'show']);
    Route::get('/users/shipping-address', [ShippingAddressController::class, 'show']);
    Route::match(['put', 'post'], '/users/{userId}/shipping-address', [ShippingAddressController::class, 'update']);
    Route::post('/users/shipping-address', [ShippingAddressController::class, 'update']);
    Route::delete('users/{userId}/shipping-address/{addressId}', [ShippingAddressController::class, 'delete']);

// Cache Management API routes
Route::post('/cache/flush/products', [CacheController::class, 'flushProductCache']);
Route::post('/cache/flush/restaurants', [CacheController::class, 'flushRestaurantCache']);
Route::post('/cache/flush/settings', [CacheController::class, 'flushSettingsCache']);
Route::post('/cache/flush/categories', [CacheController::class, 'flushCategoryCache']);
Route::post('/cache/flush/menu-items', [CacheController::class, 'flushMenuItemsCache']);
Route::post('/cache/flush/mart-items', [CacheController::class, 'flushMartItemsCache']);
Route::post('/menu-items/banners/flush-cache', [MenuItemBannerController::class, 'flushCache']);
Route::post('/cache/flush/all', [CacheController::class, 'flushAllCache']);
Route::get('/cache/stats', [CacheController::class, 'getCacheStats']);

// Coupons API routes (Public - no auth required)
    Route::prefix('coupons')->group(function () {
        Route::get('/{type}', [CouponApiController::class, 'byType']);
    });

// User Profile API routes (Customers only)

    Route::get('/users/profile/{firebase_id}', [UserProfileController::class, 'show'])
        ->withoutMiddleware(['throttle:api']);  // REMOVE default throttle
//        ->middleware('throttle:200,1');         // ADD custom throttle
    // Route::get('/users/profile/{firebase_id}', [UserProfileController::class, 'show']); // Public - get customer by firebase_id
    Route::get('/user/profile', [UserProfileController::class, 'me']) // Get current customer profile
    ->withoutMiddleware(['throttle:api']);  // REMOVE default throttle

    Route::post('/user/profile', [UserProfileController::class, 'update']) // Update current customer profile
    ->withoutMiddleware(['throttle:api']);  // REMOVE default throttle

    Route::delete('/users/profile/{firebase_id}', [UserProfileController::class, 'destroy']) // Delete user and related data from database
    ->withoutMiddleware(['throttle:api']);  // REMOVE default throttle

    // Route::delete('/user/profile/{firebase_id}', [UserProfileController::class, 'destroy']); // Backward compatibility



//restaurants


    Route::prefix('favorites')->group(function () {

        Route::get('restaurants/{firebase_id}', [FavoriteController::class, 'getFavoriteRestaurants'])
            ->withoutMiddleware(['throttle:api']);  // Disable default throttle

        Route::post('restaurants', [FavoriteController::class, 'addFavoriteRestaurant'])
            ->withoutMiddleware(['throttle:api']);

        Route::delete('restaurants', [FavoriteController::class, 'removeFavoriteRestaurant'])
            ->withoutMiddleware(['throttle:api']);

        Route::get('items/{firebase_id}', [FavoriteController::class, 'getFavoriteItems'])
            ->withoutMiddleware(['throttle:api']);

        Route::post('items', [FavoriteController::class, 'addFavoriteItem'])
            ->withoutMiddleware(['throttle:api']);

        Route::delete('items', [FavoriteController::class, 'removeFavoriteItem'])
            ->withoutMiddleware(['throttle:api']);


    // Route::prefix('favorites')->group(function () {
    //     // Restaurants
    //     Route::get('restaurants/{firebase_id}', [FavoriteController::class, 'getFavoriteRestaurants']);
    //     Route::post('restaurants', [FavoriteController::class, 'addFavoriteRestaurant']);
    //     Route::delete('restaurants', [FavoriteController::class, 'removeFavoriteRestaurant']);

    //     // Items
    //     Route::get('items/{firebase_id}', [FavoriteController::class, 'getFavoriteItems']);
    //     Route::post('items', [FavoriteController::class, 'addFavoriteItem']);
    //     Route::delete('items', [FavoriteController::class, 'removeFavoriteItem']);
    // });
});


// vendor


    Route::prefix('vendors')->group(function () {
        Route::get('{vendorId}/products', [\App\Http\Controllers\Api\ProductController::class, 'getProductsByVendorId']);
        Route::get('{vendorId}/offers', [VendorController::class, 'getOffersByVendorId']);
        Route::get('{categoryId}/category', [VendorController::class, 'getNearestRestaurantByCategory']);
    });
    Route::get('vendor-categories/{id}', [VendorController::class, 'getVendorCategoryById']);
    Route::get('products/{id}', [VendorController::class, 'getProductById']);


Route::get('/mart-vendor/default', [VendorController::class, 'getDefaultMartVendor']);
Route::get('/mart-vendor/zone/{zoneId}', [VendorController::class, 'getMartVendorsByZone'])
    ->withoutMiddleware(['throttle:api']);
Route::get('/mart-vendor/{vendorId}', [VendorController::class, 'getMartVendorById']);

//wallet

    Route::post('/update-wallet', [WalletController::class, 'updateWallet']);




    Route::get(
        '/restaurants/{vendorId}/product-feed{extra?}',
        [\App\Http\Controllers\Api\ProductController::class, 'getRestaurantProductFeed']
    )->where('extra', '.*')
        ->withoutMiddleware(['throttle:api']);



    Route::get('/orders', [OrderApiController::class, 'index']);
    Route::get('/orders/{orderId}', [OrderApiController::class, 'show']);
    Route::get('/orders/{orderId}/billing', [OrderApiController::class, 'billing']);



// mart all apis
Route::get('/mart-items/trending', [MartItemController::class, 'getTrendingItems']);
Route::get('/mart-items/featured', [MartItemController::class, 'getFeaturedItems']);
Route::get('/mart-items/on-sale', [MartItemController::class, 'getItemsOnSale']);
Route::get('/mart-items/search', [MartItemController::class, 'searchItems']);
Route::get('/mart-items/by-category', [MartItemController::class, 'getItemsByCategory']);
Route::get('/mart-items/by-category-only', [MartItemController::class, 'getItemsByCategoryOnly']);
Route::get('/mart-items/by-vendor', [MartItemController::class, 'getItemsByVendor']);
Route::get('/mart-items/by-section', [MartItemController::class, 'getItemsBySection']);
Route::get('/mart-items/all', [MartItemController::class, 'getMartItems'])
    ->withoutMiddleware(['throttle:api']);
Route::get('/mart-items/by-brand', [MartItemController::class, 'getItemsByBrand']);
Route::get('/mart-items/sections', [MartItemController::class, 'getUniqueSections']);
Route::get('/mart-items/getmartcategory', [MartItemController::class, 'getmartcategory']);
Route::get('/mart-items/categoryhome', [MartItemController::class, 'getcategoryhome']);
Route::get('/mart-items/sub_category', [MartItemController::class, 'getSubcategoriesByParent']);
Route::get('/mart-items/sub_category_home', [MartItemController::class, 'getSubcategories_home']);
Route::get('/mart-items/searchSubcategories', [MartItemController::class, 'searchSubcategories']);
Route::get('/mart-items/getItemById', [MartItemController::class, 'getItemById']);
Route::get('/mart-items/searchCategories', [MartItemController::class, 'searchCategories']);
Route::get('/mart-items/getFeaturedCategories', [MartItemController::class, 'getFeaturedCategories']);
Route::get('/mart-items/getSimilarProducts', [MartItemController::class, 'getSimilarProducts']);
Route::get('/mart-items/getItemsBySectionName', [MartItemController::class, 'getItemsBySectionName']);
Route::get('/mart-items/getMartVendors', [MartItemController::class, 'getMartVendors']);




    Route::get('/vendor/attributes', [SettingsApiController::class, 'getVendorAttributes']);



    Route::get('/vendor/{vendorId}/reviews', [Vendor_Reviews::class, 'getVendorReviews'])
        ->withoutMiddleware(['throttle:api']);
    Route::get('/reviews/order', [Vendor_Reviews::class, 'getOrderReviewById']);
    Route::get('/review-attributes/{id}', [Vendor_Reviews::class, 'getReviewAttributeById']);





    Route::prefix('firestore')->group(function () {
        Route::get('/settings/razorpay', [FirestoreBridgeController::class, 'getRazorpaySettings']);
        Route::get('/settings/cod', [FirestoreBridgeController::class, 'getCodSettings']);
        Route::post('/products', [FirestoreBridgeController::class, 'setProduct']);
        Route::get('/orders', [FirestoreBridgeController::class, 'getAllOrders']);
        Route::get('/email-templates/{type}', [FirestoreBridgeController::class, 'getEmailTemplates']);
        // Route::get('/notifications/{type}', [FirestoreBridgeController::class, 'getNotificationContent']);
//    Route::post('/chat/driver/inbox', [FirestoreBridgeController::class, 'addDriverInbox']);
//    Route::post('/chat/driver/messages', [FirestoreBridgeController::class, 'addDriverChat']);
//    Route::post('/chat/restaurant/inbox', [FirestoreBridgeController::class, 'addRestaurantInbox']);
//    Route::post('/chat/restaurant/messages', [FirestoreBridgeController::class, 'addRestaurantChat']);
//    Route::post('/chat/upload-image', [FirestoreBridgeController::class, 'uploadChatImageToStorage']);
//    Route::post('/chat/upload-video', [FirestoreBridgeController::class, 'uploadChatVideoToStorage']);
        Route::get('/vendor-categories/{id}', [FirestoreBridgeController::class, 'getVendorCategoryByCategoryId']);
        Route::post('/ratings', [FirestoreBridgeController::class, 'setRatingModel']);
        Route::put('/vendors/{vendorId}', [FirestoreBridgeController::class, 'updateVendor']);
        Route::get('/advertisements/active', [FirestoreBridgeController::class, 'getAllAdvertisement']);
        Route::get('/promotions/active', [FirestoreBridgeController::class, 'fetchActivePromotions']);
        Route::get('/promotions/by-product', [FirestoreBridgeController::class, 'getActivePromotionForProduct'])
            ->withoutMiddleware(['throttle:api']);
        Route::get('/search/products', [FirestoreBridgeController::class, 'getAllProductsInZone']);
        Route::get('/search/vendors', [FirestoreBridgeController::class, 'getAllVendors']);
        Route::get('/getLatestOrderInRange', [FirestoreBridgeController::class, 'getLatestOrderInRange']);
    });

Route::get('/firestore/notifications/{type}', [FirestoreBridgeController::class, 'getNotificationContent']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/products', [ProductController::class, 'getAllPublishedProducts']);
    Route::get('/order/{id}/tracking', [OrderApiController::class, 'track']);

});

Route::prefix('search')->group(function () {
    // Category search endpoints
    Route::get('/categories', [SearchController::class, 'searchCategories']);
    Route::get('/categories/published', [SearchController::class, 'getPublishedCategories']);

    // Mart items search endpoints
    Route::get('/items', [SearchController::class, 'searchMartItems']);
    Route::get('/items/featured', [SearchController::class, 'getFeaturedMartItems']);

    // Food items search endpoints
//    Route::get('/food', [App\Http\Controllers\FoodSearchController::class, 'searchFoodItems']);
//
//    // Health check endpoint
//    Route::get('/health', [App\Http\Controllers\SearchController::class, 'healthCheck']);
});

// Chat API routes
    // Chat inbox (list all chats)
    Route::get('/chat/inbox', [ChatController::class, 'getInbox']);
    // Get chat messages by order ID
    Route::get('/chat/{orderId}/messages', [ChatController::class, 'getMessages']);
    // Get single chat with messages
//    Route::post('/chat/{orderId}', [ChatController::class, 'getChat']);
    // Send message
    Route::post('/chat/{orderId}/send', [ChatController::class, 'sendMessage']);
    // Delete message
    Route::delete('/chat/message/{messageId}', [ChatController::class, 'deleteMessage']);
    // Delete chat
    Route::delete('/chat/{orderId}', [ChatController::class, 'deleteChat']);
    // Upload image
    Route::post('/chat/upload/image', [ChatController::class, 'uploadImage']);
    // Upload video
    Route::post('/chat/upload/video', [ChatController::class, 'uploadVideo']);

///NEW FILEDS
Route::get('/unified-search', [SwiggySearchController::class, 'unifiedSearch']);
Route::post('/chat/{orderId}', [ChatController::class, 'getChat']);


Route::post('/mobile/orders/place-basic', [OrderSupportController::class, 'placeOrder']);


//drivers apis


Route::post('/driver/login', [DriverControllerLogin::class, 'driverLogin']);
Route::post('/drivers/signup',  [DriverControllerLogin::class, 'driverSignup']);
Route::get('/users/{firebase_id}', [DriverUserController::class, 'getUserProfile']);




//restaurant apis

Route::post('/restaurant/login', [restaurantControllerLogin::class, 'restaurantLogin']);
Route::post('/restaurant/signup', [restaurantControllerLogin::class, 'restaurantSignup']);
Route::get('/restaurant/users/{firebase_id}', [restaurantUserController::class, 'getUserProfile'])
    ->withoutMiddleware(['throttle:api']);
Route::post('/restaurant/update-user-wallet', [WalletTransactionController::class, 'updateUserWallet']);
Route::post('/restaurant/updateUser', [restaurantUserController::class, 'updateUser']);
// Route::post('/restaurant/updateUser', [restaurantUserController::class, 'updateDriverUser']);
Route::post('/restaurant/withdraw', [WalletTransactionController::class, 'withdrawWalletAmount']);
Route::get('/onboarding/{type}', [RestaurantAppSettingController::class, 'getOnBoardingList']);
Route::post('/restaurant/wallet/transaction', [WalletTransactionController::class, 'setWalletTransaction']);
Route::get('/settings/document-verification', [RestaurantAppSettingController::class, 'getDocumentVerification']);
Route::get('/settings/getActiveCurrency', [MobileSqlBridgeController::class, 'getActiveCurrency']);
Route::get('/settings/getStorySettings', [MobileSqlBridgeController::class, 'getStorySettings']);


// ===========================================================
// FirestoreUtils API Routes (SQL-based replacements)
// All routes for restaurant/vendor app functionality
// ===========================================================


// Referrals
Route::post('/restaurant/referral/check-code', [FirestoreUtilsController::class, 'checkReferralCodeValidOrNot']);
Route::post('/restaurant/referral/get-by-code', [FirestoreUtilsController::class, 'getReferralUserByCode']);
Route::post('/restaurant/referral/add', [FirestoreUtilsController::class, 'referralAdd']);

// Orders
Route::get('/restaurant/orders/{orderId}', [FirestoreUtilsController::class, 'getOrderByOrderId'])
    ->withoutMiddleware(['throttle:api']);

Route::get('/restaurant/orders', [FirestoreUtilsController::class, 'getAllOrder'])
    ->withoutMiddleware(['throttle:api']);

Route::post('/restaurant/orders', [FirestoreUtilsController::class, 'setOrder']);
Route::post('/restaurant/orders/{orderId}', [FirestoreUtilsController::class, 'updateOrder']);
Route::post('/restaurant/orders/wallet-credit', [FirestoreUtilsController::class, 'restaurantVendorWalletSet']);

// Reviews
Route::get('/restaurant/reviews/order', [FirestoreUtilsController::class, 'getOrderReviewsByID']);
Route::get('/restaurant/reviews/vendor/{vendorId}', [FirestoreUtilsController::class, 'getOrderReviewsByVenderId']);

// Products
Route::get('/restaurant/products', [FirestoreUtilsController::class, 'getProduct']);
Route::get('/restaurant/products/{productId}', [FirestoreUtilsController::class, 'RestaurantGetProductById']);
Route::post('/restaurant/products', [FirestoreUtilsController::class, 'setProduct']);
Route::put('/restaurant/products/{productId}', [FirestoreUtilsController::class, 'updateProduct']);
Route::delete('/restaurant/products/{productId}', [FirestoreUtilsController::class, 'deleteProduct']);
Route::put('/restaurant/products/{productId}/availability', [FirestoreUtilsController::class, 'updateProductIsAvailable']);
Route::put('/restaurant/categories/{categoryId}/products-availability', [FirestoreUtilsController::class, 'setAllProductsAvailabilityForCategory']);

// Advertisements
Route::get('/advertisements', [FirestoreUtilsController::class, 'getAdvertisement']);
Route::get('/advertisements/{advertisementId}', [FirestoreUtilsController::class, 'getAdvertisementById']);
Route::post('/advertisements', [FirestoreUtilsController::class, 'firebaseCreateAdvertisement']);
Route::delete('/advertisements/{advertisementId}', [FirestoreUtilsController::class, 'removeAdvertisement']);
Route::put('/advertisements/{advertisementId}/pause-resume', [FirestoreUtilsController::class, 'pauseAndResumeAdvertisement']);

// Wallet
Route::get('/restaurant/wallet/transactions', [FirestoreUtilsController::class, 'getWalletTransaction']);
Route::post('/restaurant/wallet/transactions/filtered', [FirestoreUtilsController::class, 'getFilterWalletTransaction']);
Route::get('/restaurant/wallet/withdraw-history', [FirestoreUtilsController::class, 'getWithdrawHistory']);
Route::get('/restaurant/wallet/withdraw-method', [FirestoreUtilsController::class, 'getWithdrawMethod']);
Route::post('/restaurant/wallet/withdraw-method', [FirestoreUtilsController::class, 'setWithdrawMethod']);

// Payment Settings
Route::get('/settings/payment', [FirestoreUtilsController::class, 'getPaymentSettingsData']);

// Vendors
Route::get('/restaurant/vendors/{vendorId}', [FirestoreUtilsController::class, 'getVendorById'])
    ->withoutMiddleware(['throttle:api']);

Route::any('/restaurant/vendors', [FirestoreUtilsController::class, 'firebaseCreateNewVendor']);
Route::put('/restaurant/vendors/{vendorId}', [FirestoreUtilsController::class, 'updateVendor'])
    ->withoutMiddleware(['throttle:api']);

// Categories & Attributes
Route::get('/restaurant/vendor-categories', [FirestoreUtilsController::class, 'getVendorCategoryById']);
Route::get('/restaurant/vendor-categories/{categoryId}', [FirestoreUtilsController::class, 'getVendorCategoryByCategoryId']);
Route::put('/restaurant/vendor-categories/{categoryId}/active', [FirestoreUtilsController::class, 'updateCategoryIsActive']);
Route::get('/restaurant/review-attributes/{attributeId}', [FirestoreUtilsController::class, 'getVendorReviewAttribute']);
Route::get('/restaurant/attributes', [FirestoreUtilsController::class, 'getAttributes']);

// Delivery & Zones
Route::get('/restaurant/delivery-charge', [FirestoreUtilsController::class, 'getDeliveryCharge']);
Route::get('/restaurant/GetDriverNearBy', [FirestoreUtilsController::class, 'GetDriverNearBy']);
Route::get('/restaurant/zones', [FirestoreUtilsController::class, 'getZone']);

// Dine-in Bookings
Route::get('/bookings/dine-in', [FirestoreUtilsController::class, 'getDineInBooking']);
Route::post('/bookings/dine-in', [FirestoreUtilsController::class, 'setBookedOrder']);

// Coupons
Route::get('/coupons/vendor/{vendorId}', [FirestoreUtilsController::class, 'getAllVendorCoupons']);
Route::get('/offers/vendor/{vendorId}', [FirestoreUtilsController::class, 'getOffer']);
Route::post('/coupons', [FirestoreUtilsController::class, 'setCoupon']);
Route::delete('/coupons/{couponId}', [FirestoreUtilsController::class, 'deleteCoupon']);

// restaurant app version
Route::get('/restaurant/version', [MobileSqlBridgeController::class, 'getLatestrestVersionInfo']);


// Documents
Route::get('/documents', [FirestoreUtilsController::class, 'getDocumentList']);
Route::post('/documents/driver', [FirestoreUtilsController::class, 'getDocumentOfDriver']);
Route::post('/documents/driver/upload', [FirestoreUtilsController::class, 'uploadDriverDocument']);

// Email & Notifications
Route::get('/restaurant/email-templates/{type}', [FirestoreUtilsController::class, 'getEmailTemplates']);
Route::get('/restaurant/notifications/{type}', [FirestoreUtilsController::class, 'getNotificationContent']);

// Stories
Route::get('/restaurant/stories/{vendorId}', [FirestoreUtilsController::class, 'getStory']);
Route::post('/restaurant/stories', [FirestoreUtilsController::class, 'addOrUpdateStory']);
Route::delete('/restaurant/stories/{vendorId}', [FirestoreUtilsController::class, 'removeStory']);

// Subscriptions
Route::get('/subscriptions/plans', [FirestoreUtilsController::class, 'getAllSubscriptionPlans']);
Route::get('/subscriptions/plans/{planId}', [FirestoreUtilsController::class, 'getSubscriptionPlanById']);
Route::post('/subscriptions/plans', [FirestoreUtilsController::class, 'setSubscriptionPlan']);
Route::post('/subscriptions/transactions', [FirestoreUtilsController::class, 'setSubscriptionTransaction']);
Route::get('/subscriptions/history', [FirestoreUtilsController::class, 'getSubscriptionHistory']);

// Drivers
Route::get('/drivers/available', [FirestoreUtilsController::class, 'getAvalibleDrivers']);
Route::get('/drivers/all', [FirestoreUtilsController::class, 'getAllDrivers']);

Route::get('/restaurant/exists/{uid}', [restaurantControllerLogin::class, 'checkUserExists']);
Route::delete('/restaurant/user_delete', [restaurantControllerLogin::class, 'deleteUserById']);

Route::prefix('chat-restaurant')->group(function () {
    Route::post('inbox', [ChatRestaurantController::class, 'addInbox']); // Add/Update inbox
    Route::post('thread', [ChatRestaurantController::class, 'addThread']); // Add chat message
    Route::get('inbox/{id}', [ChatRestaurantController::class, 'getInbox']); // Get inbox + threads
    Route::get('threads/{chatId}', [ChatRestaurantController::class, 'getThreads']); // Get threads only
});

Route::prefix('chat-admin')->group(function () {
    Route::post('inbox', [ChatadminController::class, 'addInbox']); // Add/Update inbox
    Route::post('thread', [ChatadminController::class, 'addThread']); // Add chat message
    Route::get('inbox/{id}', [ChatadminController::class, 'getInbox']); // Get inbox + threads
    Route::get('threads/{chatId}', [ChatadminController::class, 'getThreads']); // Get threads only
});

Route::get('/restaurant/chat/restaurant', [ChatController::class, 'getRestaurantChats']);
Route::get('/restaurant/chat/admin', [ChatController::class, 'getAdminChats']);

Route::get('/settings/languages', [MobileSqlBridgeController::class, 'getLanguages']);

Route::get('/orders/vendor/{vendorId}', [restaurantControllerLogin::class, 'getVendorOrders']);

// routes/api.php
Route::get('/restaurant/settings/restaurant', [MobileSqlBridgeController::class, 'getRestaurantSettings']);



Route::post('/restaurant/forgot-password', [restaurentrestpassword::class, 'sendResetLink']);
Route::post('/restaurant/reset-password',  [restaurentrestpassword::class, 'resetPassword']);


// ------------------------------------------------------------------
// Driver SQL bridge routes (new Firestore -> SQL parity endpoints)
// ------------------------------------------------------------------
Route::prefix('driver-sql')->group(function () {
//    Route::post('/is-login', [DriverSqlBridgeController::class, 'isLogin']);
    Route::get('/users/{uid}/exists', [DriverSqlBridgeController::class, 'userExistOrNot']);
//    Route::get('/users/{uid}', [DriverSqlBridgeController::class, 'getDriverProfile']);
    Route::post('/users/update', [DriverSqlBridgeController::class, 'updateDriver']);
    Route::post('/wallet/update', [DriverSqlBridgeController::class, 'updateUserWallet']);
    Route::post('/delivery-amount/update', [DriverSqlBridgeController::class, 'updateUserDeliveryAmount']);
    Route::get('/onboarding', [DriverSqlBridgeController::class, 'getDriverOnBoardingList']);
    Route::get('/vendors', [DriverSqlBridgeController::class, 'getDriverZoneVendors']);
    Route::post('/wallet/records', [DriverSqlBridgeController::class, 'setDriverWalletRecord']);
    Route::get('/charges', [DriverSqlBridgeController::class, 'getDriverCharges']);
    Route::get('/settings', [DriverSqlBridgeController::class, 'getDriverSettings']);
    Route::get('/wallet/transactions', [DriverSqlBridgeController::class, 'getDriverWalletTransactions']);
    Route::get('/wallet/delivery-records', [DriverSqlBridgeController::class, 'getDriverAmountWalletTransaction']);
    Route::get('/tax', [DriverSqlBridgeController::class, 'getDriverTaxList']);
    Route::post('/wallet/auto-update', [DriverSqlBridgeController::class, 'updateWalletAmount']);
    Route::get('/vendors/{vendorId}/cuisines', [DriverSqlBridgeController::class, 'getVendorCuisines']);
    Route::delete('/users/{driver_id}', [DriverSqlBridgeController::class, 'deleteDriver']);
    Route::post('/wallet/topup-email', [DriverSqlBridgeController::class, 'sendTopUpMail']);
    Route::post('/wallet/payout-email', [DriverSqlBridgeController::class, 'sendPayoutMail']);
    Route::get('/orders/{authorID}/is-first', [DriverSqlBridgeController::class, 'getFirstOrderOrNot']);
    Route::get('/referrals/{id}', [DriverSqlBridgeController::class, 'getReferralById']);
    Route::post('/orders/assign', [DriverSqlBridgeController::class, 'assignOrderToDriverFCFS']);
    Route::post('/orders/remove-from-others', [DriverSqlBridgeController::class, 'removeOrderFromOtherDrivers']);
    Route::post('/wallet/withdraw', [DriverSqlBridgeController::class, 'addDriverPayout']);
    Route::get('/wallet/withdraw', [DriverSqlBridgeController::class, 'getDriverPayoutsByDriver']);

});

Route::prefix('chat-driver')->group(function () {
    Route::post('inbox', [ChatDriverContoller::class, 'addInbox']); // Add/Update inbox
    Route::post('thread', [ChatDriverContoller::class, 'addThread']); // Add chat message
    Route::get('inbox/{id}', [ChatDriverContoller::class, 'getInbox']); // Get inbox + threads
    Route::get('threads/{chatId}', [ChatDriverContoller::class, 'getThreads']); // Get threads only
});


Route::get('documents/driver/list', [DriverUserController::class, 'getDocumentList']);
Route::get('/driver/documents/{driver_id}', [DriverUserController::class, 'getDriverDocuments']);

// Wallet API routes
Route::post('/driver/wallet/transaction', [WalletApiController::class, 'setWalletTransaction']);
Route::get('/driver/wallet/withdraw-method', [WalletApiController::class, 'getWithdrawMethod']);
Route::post('/driver/wallet/withdraw-method', [WalletApiController::class, 'setWithdrawMethod']);
Route::post('/driver/wallet/driver/record', [WalletApiController::class, 'setDriverWalletRecord']);

Route::post('/driver/get-current-order', [DriverSqlBridgeController::class, 'getCurrentOrder']);
// routes/api.php
Route::get('/driver/get-current-reject-accept', [App\Http\Controllers\Api\DriverSqlBridgeController::class, 'getOrderCancelRejectCompleated']);

// Specific driver routes must be defined before /driver/{id} so they are not matched as {id}
Route::get('/driver/ordersList', [DriverSqlBridgeController::class, 'driverGetOrders']);


Route::get('/driver-sql/forceupdate', [DriverSqlBridgeController::class, 'getVersion']);
Route::get('/restaurant-sql/forceupdate', [DriverSqlBridgeController::class, 'getresturantVersion']);

// routes/api.php
Route::get('/driver/{id}', [DriverSqlBridgeController::class, 'getDriver']);
Route::get('/order/{id}', [DriverSqlBridgeController::class, 'refreshCurrentOrder']);
// routes/api.php
Route::get('/orders/completed/today/{driverId}', [DriverSqlBridgeController::class, 'todayCompletedOrders']);

// routes/api.php
Route::post('/order/complete/{orderId}', [DriverSqlBridgeController::class, 'completeOrder']);

Route::post('/zone/bonus/byZoneId', [DriverSqlBridgeController::class, 'getZoneBonusByZoneId']);

Route::get('/update-driver-order', [DriverSqlBridgeController::class, 'updateDriverOrder']);

Route::post('/driver/orders', [DriverSqlBridgeController::class, 'getOrders']);

Route::get('/wallet/transactions', [DriverSqlBridgeController::class, 'getWalletTransaction']);

Route::post('/driver/wallet-transactions', [DriverSqlBridgeController::class, 'getWalletsTransaction']);

Route::get('/get-chats', [DriverSqlBridgeController::class, 'getChats']);







