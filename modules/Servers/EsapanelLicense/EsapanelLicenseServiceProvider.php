<?php

namespace Modules\Servers\EsapanelLicense;

use Illuminate\Support\ServiceProvider;

/** The module's own screens and words, kept beside its code (pnlcs.json "provider"). */
class EsapanelLicenseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'esapanel-license');
        $this->loadTranslationsFrom(__DIR__.'/lang', 'esapanel-license');
    }
}
