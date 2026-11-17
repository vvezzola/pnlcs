<?php

use App\Models\Currency;
use App\Models\HomepageSection;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Setting;
use App\Services\ThemeManager;

/*
 * Esalogica's theme replaces the Panelica demo copy on the public pages: the
 * home page says what Esalogica sells, at its prices, with the domain included
 * only on yearly billing, and the mail guide sends customers to
 * mail.<their domain> on the Plesk servers instead of one shared host name.
 */

beforeEach(function () {
    Setting::set('active_theme_slug', 'esalogica');
    app(ThemeManager::class)->applyViewPaths();
    app()->setLocale('it');

    // The sections production shows, in its order (the test database holds
    // only the app showcase).
    foreach (['hero', 'domain-search', 'promo-cards', 'hosting-plans', 'infrastructure', 'stats', 'vps-plans', 'testimonials', 'faq', 'cta-banner'] as $order => $slug) {
        HomepageSection::updateOrCreate(['slug' => $slug], ['title' => $slug, 'sort_order' => $order + 1, 'is_enabled' => true]);
    }
});

test('the home page shows the hosting plans with monthly and yearly prices and none of the demo copy', function () {
    $group = ProductGroup::factory()->create(['name' => 'Hosting Shared']);
    $plan = Product::factory()->create(['group_id' => $group->id, 'type' => 'hosting', 'name' => 'EsaBusiness20', 'hidden' => false, 'retired' => false]);
    Pricing::where('type', 'product')->where('rel_id', $plan->id)->delete();
    Pricing::create(['type' => 'product', 'rel_id' => $plan->id, 'currency_id' => Currency::firstOrCreate(['code' => 'EUR'], ['prefix' => '€', 'suffix' => '', 'rate' => 1, 'is_default' => true])->id, 'monthly' => 10, 'annually' => 120]);

    $this->get('/?lang=it')->assertOk()
        ->assertSee('EsaBusiness20')
        ->assertSee('/mese')
        ->assertSee('€120.00 all\'anno, dominio incluso')
        ->assertSee('Pannello di controllo Plesk')
        ->assertDontSee('Panelica')
        ->assertDontSee('James Mitchell')
        ->assertDontSee('BTC')
        ->assertDontSee('VDS');
});

test('the mail guide names the customer\'s own domain as the mail server', function () {
    Setting::set('SystemURL', 'https://my.esalogica.com');

    $this->get(route('pages.mail-setup').'?lang=it')->assertOk()
        ->assertSee('mail.tuodominio.it')
        ->assertSee('webmail.tuodominio.it')
        ->assertDontSee('mail.my.esalogica.com');
});
