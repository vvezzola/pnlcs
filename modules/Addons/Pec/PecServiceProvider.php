<?php

namespace Modules\Addons\Pec;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Loaded only while the addon is active (AddonServiceProvider). */
class PecServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'admin.auth', 'admin.2fa', 'admin.permission:edit_clients'])
            ->prefix('admin/pec')
            ->name('admin.pec.')
            ->group(function () {
                Route::post('send', [PecController::class, 'send'])->name('send');
                Route::post('receipts', [PecController::class, 'refresh'])->name('refresh');
                Route::get('messages/{message}/receipts/{index}', [PecController::class, 'receipt'])->whereNumber('index')->name('receipt');
            });

        if ($this->app->runningInConsole()) {
            $this->commands([PecReceiptsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('pnlcs:pec-receipts')->everyTenMinutes()->withoutOverlapping();
        });
    }
}
