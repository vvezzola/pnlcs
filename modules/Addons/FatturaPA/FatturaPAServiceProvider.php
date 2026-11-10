<?php

namespace Modules\Addons\FatturaPA;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Loaded only while the addon is active (AddonServiceProvider). */
class FatturaPAServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'admin.auth', 'admin.2fa', 'admin.permission:manage_invoices'])
            ->prefix('admin/fatturapa')
            ->name('admin.fatturapa.')
            ->group(function () {
                Route::get('invoice', [FatturaPAController::class, 'single'])->name('single');
                Route::post('export', [FatturaPAController::class, 'export'])->name('export');
            });
    }
}
