<?php

namespace Modules\Servers\Esapanel;

use Illuminate\Support\ServiceProvider;

/**
 * The customer's own actions on an Esapanel service (the WFP accounts), which
 * take what the customer types and so need their own routes.
 */
class EsapanelServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'esapanel');
        $this->loadTranslationsFrom(__DIR__.'/lang', 'esapanel');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
