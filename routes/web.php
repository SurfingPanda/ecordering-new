<?php

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ConsolidatedOrderController;
use App\Http\Controllers\Admin\StoreController;
use App\Http\Controllers\Admin\SubmissionSettingsController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    // forgot password: a one-time link is emailed; every step is rate-limited
    Route::get('/forgot-password', [\App\Http\Controllers\Auth\PasswordResetController::class, 'showRequest'])->name('password.request');
    Route::post('/forgot-password', [\App\Http\Controllers\Auth\PasswordResetController::class, 'sendLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [\App\Http\Controllers\Auth\PasswordResetController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [\App\Http\Controllers\Auth\PasswordResetController::class, 'reset'])->middleware('throttle:10,1')->name('password.update');

    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:60,1'); // generous per-IP cap; the real guess limit is per email + IP in LoginController
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/dashboard');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/account', [AccountController::class, 'show'])->name('account');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->middleware('throttle:10,1')->name('account.password');

    // Product catalog: everyone can browse it; only admins can change it.
    Route::get('/retails', [ItemController::class, 'index'])->name('retails.index');
    Route::get('/retails/snapshot', [ItemController::class, 'snapshot'])->name('retails.snapshot');

    // Orders: both roles can list and open orders, but every query is scoped to what the user may see
    // (a store account only ever gets its own store's orders; see Order::scopeVisibleTo / resolveRouteBinding).
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');

    // Placing, posting: store accounts only.
    Route::middleware('role:store')->group(function () {
        Route::post('/orders/draft', [OrderController::class, 'draft'])->name('orders.draft');
        Route::put('/orders/draft', [OrderController::class, 'updateDraft'])->name('orders.draft.update');
        Route::put('/orders/draft/lines', [OrderController::class, 'saveDraft'])->name('orders.draft.save');
        Route::get('/orders/last', [OrderController::class, 'last'])->name('orders.last');
        Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
        Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
        Route::post('/orders/{order}/post', [OrderController::class, 'post'])->whereNumber('order')->name('orders.post');
        Route::post('/orders/{order}/reset', [OrderController::class, 'reset'])->whereNumber('order')->name('orders.reset');
    });

    Route::get('/orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
    Route::get('/orders/{order}/ecpos-status', [OrderController::class, 'ecposStatus'])->whereNumber('order')->name('orders.ecpos.status');
    Route::post('/orders/{order}/ecpos-send', [OrderController::class, 'sendToEcpos'])->whereNumber('order')->name('orders.ecpos.send');
    // Cancel: admins only, and only a POSTED TR. Stores reset a pending order instead (see orders.reset).
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->whereNumber('order')->name('orders.cancel');

    // Admins and area managers: the order reports. An area manager only ever gets the stores an admin gave them
    // (every one of these reads User::visibleStoreIds()).
    Route::middleware('role:admin,area_manager')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/today', \App\Http\Controllers\Admin\TodayController::class)->name('today');
        Route::get('/trends', \App\Http\Controllers\Admin\TrendsController::class)->name('trends');
        Route::get('/consolidated', [ConsolidatedOrderController::class, 'index'])->name('consolidated');
        Route::get('/consolidated/export', [ConsolidatedOrderController::class, 'export'])->name('consolidated.export');
    });

    // Admin only.
    Route::middleware('role:admin')->group(function () {
        // Items are archived and restored, never deleted (there is deliberately no delete route).
        Route::get('/retails/export', [ItemController::class, 'export'])->name('retails.export');
        Route::get('/retails/barcode', [ItemController::class, 'barcode'])->name('retails.barcode');
        Route::get('/retails/duplicate/check', [ItemController::class, 'checkDuplicate'])->name('retails.duplicate.check');
        Route::get('/retails/barcode/check', [ItemController::class, 'checkBarcode'])->name('retails.barcode.check');
        Route::post('/retails', [ItemController::class, 'store'])->name('retails.store');
        Route::put('/retails/{item}', [ItemController::class, 'update'])->whereNumber('item')->name('retails.update');
        Route::post('/retails/archive', [ItemController::class, 'archive'])->name('retails.archive');
        Route::post('/retails/restore', [ItemController::class, 'restore'])->name('retails.restore');

        Route::post('/admin/stores/ecpos-sync', \App\Http\Controllers\Admin\EcposStoreSyncController::class)->name('admin.stores.ecpos.sync');
        Route::post('/retails/ecpos-sync', \App\Http\Controllers\Admin\EcposSyncController::class)->name('retails.ecpos.sync');

        Route::prefix('admin')->name('admin.')->group(function () {
            Route::get('/audit-log', \App\Http\Controllers\Admin\AuditLogController::class)->name('audit-log');
            Route::get('/area-managers', [\App\Http\Controllers\Admin\AreaManagerController::class, 'index'])->name('area-managers');
            Route::post('/area-managers', [\App\Http\Controllers\Admin\AreaManagerController::class, 'store'])->name('area-managers.store');
            Route::put('/area-managers/{manager}', [\App\Http\Controllers\Admin\AreaManagerController::class, 'update'])->whereNumber('manager')->name('area-managers.update');
            Route::put('/area-managers/{manager}/status', [\App\Http\Controllers\Admin\AreaManagerController::class, 'status'])->whereNumber('manager')->name('area-managers.status');
            Route::put('/area-managers/{manager}/password', [\App\Http\Controllers\Admin\AreaManagerController::class, 'password'])->whereNumber('manager')->name('area-managers.password');
            Route::delete('/area-managers/{manager}', [\App\Http\Controllers\Admin\AreaManagerController::class, 'destroy'])->whereNumber('manager')->name('area-managers.destroy');

            Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
            Route::post('/stores', [StoreController::class, 'store'])->name('stores.store');
            Route::get('/stores/{store}', [StoreController::class, 'show'])->name('stores.show');
            Route::put('/stores/{store}', [StoreController::class, 'update'])->name('stores.update');
            Route::patch('/stores/{store}/status', [StoreController::class, 'toggle'])->name('stores.toggle');
            Route::post('/stores/{store}/users', [StoreController::class, 'addUser'])->name('stores.users.store');
            Route::put('/stores/{store}/users/{user}/status', [StoreController::class, 'setUserActive'])->whereNumber(['store', 'user'])->name('stores.users.status');
            Route::put('/stores/{store}/users/{user}/password', [StoreController::class, 'resetPassword'])->name('stores.users.password');

            Route::get('/archived-products', [ItemController::class, 'archived'])->name('archived');

            Route::get('/sync-rules', [\App\Http\Controllers\Admin\CatalogRuleController::class, 'index'])->name('sync-rules');
            Route::post('/sync-rules', [\App\Http\Controllers\Admin\CatalogRuleController::class, 'store'])->name('sync-rules.store');
            Route::post('/sync-rules/apply', [\App\Http\Controllers\Admin\CatalogRuleController::class, 'apply'])->name('sync-rules.apply');
            Route::put('/sync-rules/{rule}', [\App\Http\Controllers\Admin\CatalogRuleController::class, 'update'])->whereNumber('rule')->name('sync-rules.update');
            Route::delete('/sync-rules/{rule}', [\App\Http\Controllers\Admin\CatalogRuleController::class, 'destroy'])->whereNumber('rule')->name('sync-rules.destroy');

            Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
            Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
            Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
            Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

            Route::get('/settings', [SubmissionSettingsController::class, 'show'])->name('settings');
            Route::put('/settings/default', [SubmissionSettingsController::class, 'updateDefault'])->name('settings.default');
            Route::put('/settings/order-rule', [SubmissionSettingsController::class, 'updateOrderRule'])->name('settings.rule');
            Route::post('/settings/store-deadlines', [SubmissionSettingsController::class, 'saveStoreDeadline'])->name('settings.deadlines.save');
            Route::delete('/settings/store-deadlines/{deadline}', [SubmissionSettingsController::class, 'deleteStoreDeadline'])->name('settings.deadlines.delete');

        });
    });
});
