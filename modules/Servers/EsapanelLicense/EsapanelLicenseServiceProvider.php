<?php

namespace Modules\Servers\EsapanelLicense;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * The module's own page: the Esapanel catalogue (Extensions menu, Esapanel
 * licences), where a seller makes the licence products at its prices.
 */
class EsapanelLicenseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'esapanel-license');
        $this->loadTranslationsFrom(__DIR__.'/lang', 'esapanel-license');
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        // The page in the admin's Extensions menu, beside the addons' own
        // entries, so the module's folder is all a seller installs. It runs
        // after the core's composer, which fills the list.
        View::composer('admin.layouts.app', function ($view) {
            if (! auth('admin')->user()?->hasPermission('manage_products')) {
                return;
            }
            $items = (array) ($view->getData()['addonMenuItems'] ?? []);
            $items[] = [
                'label' => __('esapanel-license::messages.page.menu'),
                'url' => route('admin.esapanel-catalog.index'),
                'children' => [],
            ];
            $view->with('addonMenuItems', $items);
        });
    }
}
