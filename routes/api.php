<?php

use App\Http\Controllers\StockGroupController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CourierController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PersonalController;
use App\Http\Controllers\QuickOrderController;
use App\Http\Controllers\RestaurantController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\TableGroupController;
use App\Http\Middleware\CheckTokenExpiration;
use App\Models\Table;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::post('login', [AuthController::class, 'login']);



Route::middleware('auth:sanctum')->group(function () {
// Route::middleware(['auth:sanctum', CheckTokenExpiration::class])->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
        return response()->json($request->user()->load('roles', 'permissions'));
    });

    // Super-admin routes
    Route::middleware('role:super-admin')->group(function () {
        Route::apiResource('users', UserController::class);
        // Route::apiResource('restaurants', RestaurantController::class);
        Route::apiResource('admin-restaurants', SuperAdminController::class);
    });

    Route::middleware('has.restaurant')->group(function () {
        // Admin routes
        Route::middleware('role:admin')->group(function () {
            // Route::post('users/{user}/make-inactive', [UserController::class, 'makeInactive']);
            // Route::post('users/{user}/change-password', [UserController::class, 'changePassword']);
            // Route::apiResource('users', UserController::class)->except(['store', 'update', 'destroy']);
            Route::apiResource('personal', PersonalController::class);
        });

        // // User routes
        // Route::middleware('permission:manage-users')->group(function () {
        //     Route::post('users/{user}/make-inactive', [UserController::class, 'makeInactive']);
        //     Route::post('users/{user}/change-password', [UserController::class, 'changePassword']);
        //     Route::apiResource('users', UserController::class)->only(['index', 'store', 'update', 'destroy']);
        // });

        // Manage restoran settings
        Route::get('own-restaurants', [RestaurantController::class, 'getOwnRestaurant']);

        Route::middleware('permission:manage-restaurants')->group(function () {
            Route::put('own-restaurants', [RestaurantController::class, 'updateOwnRestaurant']);
        });

        Route::middleware('permission:manage-tanimlar')->group(function () {
            Route::apiResource('stock-groups', StockGroupController::class);
            Route::apiResource('stocks', StockController::class);
            Route::apiResource('couriers', CourierController::class);
            Route::apiResource('table-groups', TableGroupController::class);
            Route::apiResource('tables', TableController::class);
        });

        Route::middleware('permission:access-payments')->group(function () {
            Route::get('payments', [PaymentController::class, 'index']);
            Route::put('restaurant/times', [RestaurantController::class, 'updateTimes']);
            Route::middleware('permission:manage-payments')->group(function () {
                Route::delete('order/{orderId}/payments', [PaymentController::class, 'destroyByOrderId']);
            });
        });

        // Manage customers
        Route::middleware('permission:manage-customers')->group(function () {
            Route::apiResource('customers', CustomerController::class);
            Route::post('customers/{id}/transaction', [CustomerController::class, 'storeTransaction']);
            Route::put('customers/transaction/{id}', [CustomerController::class, 'updateTransaction']);
            Route::delete('customers/transaction/{id}', [CustomerController::class, 'destroyTransaction']);
        });

        Route::apiResource('stock-groups', StockGroupController::class)->only('index');
        Route::apiResource('stocks', StockController::class)->only('index');
        Route::apiResource('couriers', CourierController::class)->only('index');
        Route::apiResource('table-groups', TableGroupController::class)->only('index');
        Route::apiResource('tables', TableController::class)->only('index');
        Route::apiResource('customers', CustomerController::class)->only('index');


        // Manage stock groups
        // Route::middleware('permission:manage-stock-groups')->group(function () {
        //     Route::apiResource('stock-groups', StockGroupController::class);
        // });

        // Manage stocks
        // Route::middleware('permission:manage-stocks')->group(function () {
        //     Route::apiResource('stocks', StockController::class);
        // });

        // Manage couriers
        // Route::middleware('permission:manage-couriers')->group(function () {
        //     Route::apiResource('couriers', CourierController::class);
        // });

        // Manage table groups
        // Route::middleware('permission:manage-table-groups')->group(function () {
        //     Route::apiResource('table-groups', TableGroupController::class);
        // });




        Route::middleware('permission:manage-tables')->group(function () {
            Route::post('/tables', [TableController::class, 'store']);
            Route::put('/tables/{id}', [TableController::class, 'update']);
            Route::delete('/tables/{id}', [TableController::class, 'destroy']);
            Route::post('tables/{id}/change-table', [TableController::class, 'changeTables']);
            Route::post('tables/{id}/merge-table', [TableController::class, 'mergeTables']);
            Route::post('tables/{id}/add-stock', [TableController::class, 'addStockToOrder']);
            Route::post('tables/{id}/subtract-stock', [TableController::class, 'subtractStockFromOrder']);
            Route::delete('tables/{id}/cancel-order', [TableController::class, 'cancelOrder']);
            // Route::post('order/{id}/prepayments', [OrderController::class, 'storePrepayments']);
            // Route::delete('order/{orderId}/prepayments/{prepaymentId}', [OrderController::class, 'destroyPrepayment']);
            // Route::post('order/{id}/payments', [PaymentController::class, 'store']);
            Route::post('qr/generate/{id}', [TableController::class, 'generateQrCode']);
            Route::delete('qr/orders/{tableOrderId}', [TableController::class, 'cancelPendingOrder']);
            Route::post('qr/orders/{tableOrderId}/approve', [TableController::class, 'approvePendingOrder']);
        });


        Route::get('table-groups', [TableGroupController::class, 'index']);

        Route::get('/tables', [TableController::class, 'index']);
        Route::get('/tables/{id}', [TableController::class, 'show']);
        Route::get('/tables/{id}/order', [TableController::class, 'getTableWithApprovedOrders']);


        Route::middleware('permission:table-order,manage-tables')->group(function () {
            Route::post('tables/{id}/subtract-stock', [TableController::class, 'subtractStockFromOrder']);
            Route::post('tables/{id}/add-stock', [TableController::class, 'addStockToOrder']);
        });

        // Manage quick orders
        
        Route::middleware('permission:manage-quick-orders')->group(function () {

            Route::apiResource('quick-orders', QuickOrderController::class);
            Route::post('quick-orders/{id}/add-stock', [QuickOrderController::class, 'addStock']);
            Route::post('quick-orders/{id}/subtract-stock', [QuickOrderController::class, 'subtractStock']);
        });

        Route::middleware('permission:manage-quick-orders,manage-tables')->group(function () {
            Route::post('order/{id}/prepayments', [OrderController::class, 'storePrepayments']);
            Route::delete('order/{orderId}/prepayments/{prepaymentId}', [OrderController::class, 'destroyPrepayment']);
            Route::post('order/{id}/payments', [PaymentController::class, 'store']);
        });

        // Manage orders
        Route::get('order/{id}/prepayments', [OrderController::class, 'getPrepayments']);


        Route::get('qr/{id}', [TableController::class, 'getQrCode']);

        Route::get('qr/orders/all', [TableController::class, 'getPendingApprovalOrders']);
    });
});

Route::get('qr/{id}/table', [TableController::class, 'getTableByQrCode']);
Route::get('qr/{id}/menu', [TableController::class, 'getQrMenu']);
Route::post('qr/{id}/order', [TableController::class, 'createQrOrder']);
