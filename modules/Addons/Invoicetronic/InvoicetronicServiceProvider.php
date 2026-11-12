<?php

namespace Modules\Addons\Invoicetronic;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Loaded only while the addon is active (AddonServiceProvider). */
class InvoicetronicServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'admin.auth', 'admin.2fa', 'admin.permission:manage_invoices'])
            ->prefix('admin/invoicetronic')
            ->name('admin.invoicetronic.')
            ->group(function () {
                Route::post('sync', [InvoicetronicController::class, 'sync'])->middleware('throttle:10,1')->name('sync');
                Route::post('send', [InvoicetronicController::class, 'send'])->middleware('throttle:30,1')->name('send');
            });

        if ($this->app->runningInConsole()) {
            $this->commands([SdiSyncCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('pnlcs:sdi-sync')->everyFifteenMinutes()->withoutOverlapping();
        });
    }
}
