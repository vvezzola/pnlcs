<?php

namespace Modules\Addons\OpenApiCompany;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Loaded only while the addon is active (AddonServiceProvider). */
class OpenApiCompanyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'admin.auth', 'admin.2fa', 'admin.permission:view_clients'])
            ->prefix('admin/openapi-company')
            ->name('admin.openapi-company.')
            ->group(function () {
                Route::post('lookup', [OpenApiCompanyController::class, 'lookup'])->middleware('throttle:20,1')->name('lookup');
            });
    }
}
