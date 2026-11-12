<?php

namespace Modules\Addons\BankReconciliation;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Loaded only while the addon is active (AddonServiceProvider). */
class BankReconciliationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'admin.auth', 'admin.2fa', 'admin.permission:manage_invoices'])
            ->prefix('admin/bank-reconciliation')
            ->name('admin.bank_reconciliation.')
            ->group(function () {
                Route::post('sync', [BankReconciliationController::class, 'sync'])->middleware('throttle:10,1')->name('sync');
                Route::post('{bankTransaction}/confirm', [BankReconciliationController::class, 'confirm'])->whereNumber('bankTransaction')->name('confirm');
                Route::post('{bankTransaction}/ignore', [BankReconciliationController::class, 'ignore'])->whereNumber('bankTransaction')->name('ignore');
            });

        if ($this->app->runningInConsole()) {
            $this->commands([BankSyncCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('pnlcs:bank-sync')->everyFifteenMinutes()->withoutOverlapping();
        });
    }
}
