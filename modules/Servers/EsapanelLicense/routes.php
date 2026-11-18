<?php

use Illuminate\Support\Facades\Route;
use Modules\Servers\EsapanelLicense\Http\CatalogController;

Route::middleware(['web', 'admin.auth', 'admin.2fa', 'admin.permission:manage_products'])
    ->prefix('admin/esapanel-catalog')
    ->name('admin.esapanel-catalog.')
    ->controller(CatalogController::class)
    ->group(function () {
        Route::get('/', 'index')->name('index');
        // Every save asks the License Server for its plans and add-ons.
        Route::post('/', 'store')->middleware('throttle:20,1')->name('store');
    });
