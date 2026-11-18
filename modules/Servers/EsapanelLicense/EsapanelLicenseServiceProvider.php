<?php

namespace Modules\Servers\EsapanelLicense;

use Illuminate\Support\ServiceProvider;

/**
 * The module's own page: the Esapanel catalogue (Products, Esapanel
 * licences), where a seller makes the licence products at its prices.
 */
class EsapanelLicenseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'esapanel-license');
        $this->loadTranslationsFrom(__DIR__.'/lang', 'esapanel-license');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
