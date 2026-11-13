<?php

namespace Modules\Addons\SimplyFatt;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Loaded only while the addon is active (AddonServiceProvider). */
class SimplyFattServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'admin.auth', 'admin.2fa', 'admin.permission:manage_invoices'])
            ->post('admin/simplyfatt/export', [SimplyFattController::class, 'export'])
            ->name('admin.simplyfatt.export');

        Route::middleware(['web', 'admin.auth', 'admin.2fa', 'admin.permission:create_clients'])
            ->prefix('admin/simplyfatt/clients')
            ->name('admin.simplyfatt.clients.')
            ->group(function () {
                Route::post('preview', [SimplyFattController::class, 'previewClients'])->name('preview');
                Route::post('import', [SimplyFattController::class, 'importClients'])->name('import');
                Route::post('cancel', [SimplyFattController::class, 'cancelClients'])->name('cancel');
            });
    }
}
