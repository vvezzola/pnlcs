<?php

namespace Modules\Servers\EsapanelLicense\Http;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\ProductGroup;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Servers\EsapanelLicense\EsapanelCatalog;
use Modules\Servers\EsapanelLicense\EsapanelLicenseModule;

/**
 * The Esapanel catalogue page: the seller picks its License Server and a
 * product group, writes its prices for each module and level, looks at what
 * would change, then makes or updates the products (EsapanelCatalog).
 */
class CatalogController extends Controller
{
    public function index(): View
    {
        $currency = Currency::getDefault();

        return view('esapanel-license::catalog', [
            'servers' => Server::where('type', EsapanelLicenseModule::NAME)->orderBy('name')->get(),
            'groups' => ProductGroup::orderBy('sort_order')->orderBy('name')->get(),
            'currency' => $currency,
            'prices' => session()->getOldInput('prices') ?? ($currency ? EsapanelCatalog::currentPrices($currency) : []),
            'result' => session('esapanel_catalog'),
        ]);
    }

    public function store(Request $request, EsapanelCatalog $catalog): RedirectResponse
    {
        $data = $request->validate([
            'server_id' => ['required', Rule::exists('servers', 'id')->where('type', EsapanelLicenseModule::NAME)],
            'group_id' => ['required', 'exists:product_groups,id'],
            'prices' => ['nullable', 'array'],
            'prices.*' => ['nullable', 'array'],
            'prices.*.*' => ['nullable', 'array'],
            'prices.*.*.*' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'visible' => ['nullable', 'boolean'],
            'action' => ['required', Rule::in(['preview', 'write'])],
        ]);

        $currency = Currency::getDefault();
        if (! $currency) {
            return back()->withInput()->with('error', __('esapanel-license::messages.page.no_currency'));
        }

        $prices = array_intersect_key((array) ($data['prices'] ?? []), EsapanelCatalog::MODULES);
        $write = $data['action'] === 'write';

        $result = $catalog->sync(
            Server::findOrFail($data['server_id']),
            ProductGroup::findOrFail($data['group_id']),
            $currency,
            $prices,
            (bool) ($data['visible'] ?? false),
            $write,
        );

        return back()->withInput()->with('esapanel_catalog', $result + ['written' => $write]);
    }
}
