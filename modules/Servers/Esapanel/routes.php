<?php

use Illuminate\Support\Facades\Route;
use Modules\Servers\Esapanel\Http\WfpController;

// Named client.services.*, so a sub-user needs "manage products" for them.
Route::middleware(['web', 'banned.ip', 'auth', '2fa', 'client.permission', 'throttle:10,1'])
    ->prefix('client/services/{service}/esapanel/wfp')
    ->name('client.services.esapanel.wfp.')
    ->controller(WfpController::class)
    ->group(function () {
        Route::post('accounts', 'store')->name('accounts.store');
        Route::post('accounts/{account}/{action}', 'act')
            ->whereNumber('account')->whereIn('action', ['password', 'suspend', 'resume'])->name('accounts.act');
    });
