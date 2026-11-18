<?php

namespace Modules\Servers\EsapanelLicense;

use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Modules\Servers\AbstractServerModule;

/**
 * Esapanel licences, sold through the Esapanel License Server (LSP).
 * Esalogica's own; kept out of upstream PNLCS.
 *
 * The License Server is a server record (Setup -> Servers) of this type: its
 * hostname, port 9943 unless another is given, and the key of its API account
 * in the API key field. A product's package is the License Server plan it
 * sells, so one product is one plan: a level with its modules.
 *
 * Since the 2027 price list a plan is a level (LSP Company, Integrator 30,
 * 60, 100, Provider) and each module is an add-on of the licence. The product
 * sells the level; its configurable options named "addon|..." sell the
 * modules, each choice naming the add-on by its License Server name ("LSP CSP
 * Company 25") or id. The licence is created with them (addon_ids) and kept
 * in step when the options or the level change. A product with no such
 * option leaves a licence's add-ons alone, as the plans sold before need.
 *
 * A licence is a subscription of the customer on that plan. The customer and
 * the licence carry this site's ids (pnlcs:client:<id>, pnlcs:service:<id>),
 * so what was created is found again instead of created twice. The License
 * Server neither bills nor renews: each paid period moves the licence's last
 * day (renew()). Terminating disables the subscription and deletes nothing.
 */
class EsapanelLicenseModule extends AbstractServerModule
{
    public const NAME = 'esapanellicense';

    private const DEFAULT_PORT = 9943;

    /** The service that carries the licence in a License Server plan. */
    private const LICENSE_SERVICE = 'lsp.license';

    /** A licence is read back once a day by the hourly usage run, a few at a time. */
    private const REFRESH_AFTER_HOURS = 20;

    private const REFRESH_PER_RUN = 50;

    /** The key of the configurable options that sell a module ("addon|Central Syslog"). */
    private const ADDON_OPTION = 'addon';

    /** A choice that sells no add-on: "none|No" in a module's drop-down. */
    private const NO_ADDON = ['', 'none', '0', '-'];

    /**
     * The module's screens and words live beside its code. They are named here
     * and not by a service provider, so the module adds nothing to what the
     * site boots.
     */
    public function __construct()
    {
        view()->addNamespace('esapanel-license', __DIR__.'/views');
        app('translator')->addNamespace('esapanel-license', __DIR__.'/lang');
    }

    public function getModuleName(): string
    {
        return self::NAME;
    }

    public function getConfigFields(): array
    {
        return [];
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    public function create(Service $service): array
    {
        $data = $this->getModuleData($service);

        if (! empty($data['subscription_id']) && empty($data['ended'])) {
            return $this->buildResult(true, __('esapanel-license::messages.result.already_licensed'));
        }

        $api = $this->api($service);
        if (! $api) {
            return $this->buildResult(false, __('esapanel-license::messages.error.no_server'));
        }

        $planId = $this->planId($service);
        if ($planId === null) {
            return $this->buildResult(false, __('esapanel-license::messages.error.no_plan'));
        }

        // A licence this service already has there: one ended here before, or
        // one whose creation answered after this side had stopped listening.
        $existing = empty($data['subscription_id'])
            ? $this->findLicense($api, $service)
            : $api->get('subscriptions/'.$data['subscription_id'].'/license');

        if (! $existing->ok && $existing->status !== 404) {
            return $this->failed($service, $existing->error);
        }

        if ($existing->ok && $existing->data !== null) {
            return $this->reactivate($api, $service, $existing->data, $planId);
        }

        $addonIds = $this->wantedAddonIds($api, $service);
        if (is_string($addonIds)) {
            return $this->failed($service, $addonIds);
        }

        $customer = $this->customerId($api, $service);
        if (! is_int($customer)) {
            return $this->failed($service, $customer);
        }

        $created = $api->post('lsp/licenses', array_filter([
            'customer_id' => $customer,
            'service_plan_id' => $planId,
            'valid_until' => $this->validUntil($service),
            'label' => 'PNLCS service #'.$service->id,
            'external_ref' => self::serviceRef($service),
            'addon_ids' => $addonIds ?: null,
        ], fn ($value) => $value !== null), $this->idempotencyKey($service, 'create'));

        if (! $created->ok || $created->data === null) {
            return $this->failed($service, $created->error);
        }

        $this->storeLicense($service, $created->data, ['ended' => false]);

        $result = $this->buildResult(true, __('esapanel-license::messages.result.licensed'));
        $this->logAction($service, 'create', $result);

        return $result;
    }

    public function suspend(Service $service, string $reason = ''): array
    {
        return $this->setStatus($service, 'suspended', 'suspend', __('esapanel-license::messages.result.suspended'));
    }

    public function unsuspend(Service $service): array
    {
        if (! empty($this->getModuleData($service)['ended'])) {
            return $this->buildResult(false, __('esapanel-license::messages.error.ended'));
        }

        return $this->setStatus($service, 'active', 'unsuspend', __('esapanel-license::messages.result.active'));
    }

    /**
     * Disable the subscription: the licence is revoked at the installation's
     * next check. Nothing is deleted, so the history stays and the same
     * licence, with its code, comes back if the service is created again.
     */
    public function terminate(Service $service): array
    {
        $result = $this->setStatus($service, 'disabled', 'terminate', __('esapanel-license::messages.result.ended'));

        if ($result['success']) {
            $this->setModuleData($service, ['ended' => true]);
        }

        return $result;
    }

    public function changePassword(Service $service, string $newPassword): array
    {
        return $this->buildResult(false, __('esapanel-license::messages.error.no_password'));
    }

    /**
     * Move the licence to the new product's plan. The plan brings its own
     * modules, level and caps; the code and the installation stay. The last
     * day is sent again after it, which is what turns a Trial into a paid
     * period.
     */
    public function changePackage(Service $service, array $newPackage): array
    {
        $config = is_string($newPackage['config_options'] ?? null)
            ? json_decode($newPackage['config_options'], true)
            : ($newPackage['config_options'] ?? []);
        $planId = self::planNumber((string) ($config['package_name'] ?? ''));

        if ($planId === null) {
            return $this->buildResult(false, __('esapanel-license::messages.error.no_plan'));
        }

        $data = $this->getModuleData($service);
        if (empty($data['subscription_id'])) {
            return $this->buildResult(true, __('esapanel-license::messages.result.nothing_yet'));
        }

        $api = $this->api($service);
        if (! $api) {
            return $this->buildResult(false, __('esapanel-license::messages.error.no_server'));
        }

        $changed = $api->put('subscriptions/'.$data['subscription_id'].'/plan', ['service_plan_id' => $planId]);
        if (! $changed->ok) {
            return $this->failed($service, __('esapanel-license::messages.error.plan_change', ['error' => $changed->error]));
        }

        $synced = $this->syncAddons($api, $service, (int) $data['subscription_id']);
        if ($synced !== null) {
            return $this->failed($service, __('esapanel-license::messages.error.addons', ['error' => $synced]));
        }

        $until = $this->validUntil($service);
        if ($until !== null) {
            $moved = $api->put('subscriptions/'.$data['subscription_id'].'/license', ['valid_until' => $until]);
            if (! $moved->ok) {
                return $this->failed($service, $moved->error);
            }
        }

        $this->refreshLicense($service);

        $result = $this->buildResult(true, __('esapanel-license::messages.result.plan_changed'));
        $this->logAction($service, 'changePackage', $result);

        return $result;
    }

    /**
     * Move the licence's last day to the end of the period just paid. Setting
     * a date twice sets it once, so the retry queue cannot lengthen a licence.
     */
    public function renew(Service $service): array
    {
        $data = $this->getModuleData($service);

        if (! empty($data['ended'])) {
            return $this->buildResult(true, __('esapanel-license::messages.result.ended'));
        }

        if (empty($data['subscription_id'])) {
            return $this->buildResult(true, __('esapanel-license::messages.result.nothing_yet'));
        }

        $until = $this->validUntil($service->fresh() ?? $service);
        if ($until === null) {
            return $this->buildResult(true, __('esapanel-license::messages.result.no_new_date'));
        }

        $api = $this->api($service);
        if (! $api) {
            return $this->buildResult(false, __('esapanel-license::messages.error.no_server'));
        }

        $renewed = $api->put('subscriptions/'.$data['subscription_id'].'/license', ['valid_until' => $until]);
        if (! $renewed->ok || $renewed->data === null) {
            return $this->failed($service, $renewed->error);
        }

        $this->storeLicense($service, $renewed->data);

        $result = $this->buildResult(true, __('esapanel-license::messages.result.renewed', ['date' => $until]));
        $this->logAction($service, 'renew', $result);

        return $result;
    }

    // ------------------------------------------------------------------
    // Reading back, and the installation the licence is bound to
    // ------------------------------------------------------------------

    /** Read the licence back from the License Server. */
    public function refreshLicense(Service $service): array
    {
        $data = $this->getModuleData($service);
        $api = $this->api($service);

        if (empty($data['subscription_id']) || ! $api) {
            return $this->buildResult(false, __('esapanel-license::messages.error.no_license'));
        }

        $license = $api->get('subscriptions/'.$data['subscription_id'].'/license');
        if (! $license->ok || $license->data === null) {
            return $this->failed($service, $license->error);
        }

        $this->storeLicense($service, $license->data);

        return $this->buildResult(true, __('esapanel-license::messages.result.refreshed'));
    }

    public function checkNow(Service $service): array
    {
        return $this->refreshLicense($service);
    }

    /**
     * Free the licence from the installation it is bound to: the customer has
     * moved to another server. The next server that activates the code takes
     * the licence, and the old one is told it is revoked at its next check.
     */
    public function unbindServer(Service $service): array
    {
        $data = $this->getModuleData($service);
        $api = $this->api($service);

        if (empty($data['subscription_id']) || ! $api) {
            return $this->buildResult(false, __('esapanel-license::messages.error.no_license'));
        }

        $reset = $api->post(
            'subscriptions/'.$data['subscription_id'].'/license/reset',
            [],
            $this->idempotencyKey($service, 'reset-'.now()->format('YmdHi'))
        );

        if (! $reset->ok) {
            return $this->failed($service, $reset->error);
        }

        $this->refreshLicense($service);

        $result = $this->buildResult(true, __('esapanel-license::messages.result.unbound'));
        $this->logAction($service, 'unbindServer', $result);

        return $result;
    }

    /**
     * The hourly usage run: read back the licences of this License Server that
     * have not been read for most of a day, a few at a time.
     */
    public function usageUpdate(Server $server): array
    {
        $stale = now()->subHours(self::REFRESH_AFTER_HOURS)->toIso8601String();

        $services = Service::with('product', 'server')
            ->where('server_id', $server->id)
            ->whereIn('status', ['active', 'suspended'])
            ->whereNotNull('module_data->subscription_id')
            ->where(fn ($q) => $q->whereNull('module_data->checked_at')->orWhere('module_data->checked_at', '<', $stale))
            ->orderBy('id')
            ->limit(self::REFRESH_PER_RUN)
            ->get();

        $done = ['updated' => 0, 'errors' => 0];
        foreach ($services as $service) {
            $this->refreshLicense($service)['success'] ? $done['updated']++ : $done['errors']++;
        }

        return $done;
    }

    // ------------------------------------------------------------------
    // Screens, mail and buttons (see App\Services\Module\ModuleScreens)
    // ------------------------------------------------------------------

    /** @return list<string> */
    public function servicePageHides(): array
    {
        return ['server_details', 'change_password'];
    }

    public function isProvisioned(Service $service): bool
    {
        $data = $this->getModuleData($service);

        return ! empty($data['subscription_id']);
    }

    /** @return array<string, string> staff buttons, method => label */
    public function customFunctions(?Service $service = null): array
    {
        $functions = ['checkNow' => __('esapanel-license::messages.action.check_now')];

        if (! $service || $this->isBound($service)) {
            $functions['unbindServer'] = __('esapanel-license::messages.action.unbind');
        }

        return $functions;
    }

    /** @return array<string, string> customer buttons, method => label */
    public function clientFunctions(?Service $service = null): array
    {
        if ($service && strtolower((string) $service->status) !== 'active') {
            return [];
        }

        return $this->customFunctions($service);
    }

    /** @return array<string, string> */
    public function emailMergeFields(Service $service): array
    {
        return [
            'service_license_key' => (string) ($this->getModuleData($service)['code'] ?? ''),
            'service_install_command' => $this->installCommand($service) ?? '',
        ];
    }

    public function clientAreaPanel(Service $service): ?Htmlable
    {
        return view('esapanel-license::client', $this->screenData($service));
    }

    public function adminAreaPanel(Service $service): ?Htmlable
    {
        return view('esapanel-license::admin', $this->screenData($service));
    }

    /**
     * What both screens show.
     *
     * @return array<string, mixed>
     */
    public function screenData(Service $service): array
    {
        $data = $this->getModuleData($service);

        $state = match (true) {
            ! empty($data['ended']) => 'ended',
            empty($data['subscription_id']) => 'none',
            ($data['license_status'] ?? '') === 'suspended' => 'suspended',
            ($data['license_status'] ?? '') === 'revoked' => 'ended',
            ! empty($data['expired']) => 'expired',
            default => 'active',
        };

        return [
            'service' => $service,
            'state' => $state,
            'data' => $data,
            'installation' => is_array($data['installation'] ?? null) ? $data['installation'] : null,
            'command' => $state === 'active' ? $this->installCommand($service) : null,
        ];
    }

    public function installCommand(Service $service): ?string
    {
        $code = $this->getModuleData($service)['code'] ?? null;

        return is_string($code) && $code !== ''
            ? 'wget -qO install.sh https://repo.esalogica.com/install.sh && bash install.sh --license='.$code
            : null;
    }

    // ------------------------------------------------------------------
    // The License Server (a server record of this type)
    // ------------------------------------------------------------------

    /**
     * The plans that sell a licence, for the product form.
     *
     * @return list<array{id: string, name: string}>
     */
    public function listPackages(?Server $server = null): array
    {
        $api = $server ? $this->apiFor($server) : null;
        if (! $api) {
            throw new \RuntimeException(__('esapanel-license::messages.error.no_server'));
        }

        $plans = $api->get('plans', ['type' => 'client', 'per_page' => 100]);
        if (! $plans->ok) {
            throw new \RuntimeException($plans->error);
        }

        $packages = [];
        foreach ((array) $plans->data as $plan) {
            $license = is_array($plan) ? self::licenseService($plan) : null;
            if ($license === null || empty($plan['id'])) {
                continue;
            }

            $packages[] = [
                'id' => (string) $plan['id'],
                'name' => (string) ($plan['label'] ?? $plan['id']).(self::isTrial($license) ? ' (Trial)' : ''),
            ];
        }

        return $packages;
    }

    /**
     * The module add-ons the License Server sells, by name => id, for the
     * products' "addon|..." options.
     *
     * @return array<string, int>
     */
    public function listAddons(Server $server): array
    {
        $api = $this->apiFor($server);
        if (! $api) {
            throw new \RuntimeException(__('esapanel-license::messages.error.no_server'));
        }

        $plans = $api->get('plans', ['type' => 'client_addon', 'per_page' => 100]);
        if (! $plans->ok) {
            throw new \RuntimeException($plans->error);
        }

        $addons = [];
        foreach ((array) $plans->data as $plan) {
            if (is_array($plan) && ! empty($plan['id']) && ($plan['label'] ?? '') !== '') {
                $addons[(string) $plan['label']] = (int) $plan['id'];
            }
        }

        return $addons;
    }

    public function testConnection(Server $server): bool
    {
        return (bool) $this->apiFor($server)?->get('plans', ['per_page' => 1])->ok;
    }

    // ------------------------------------------------------------------

    private function api(Service $service): ?LicenseServerClient
    {
        $server = $this->getServer($service);

        return $server ? $this->apiFor($server) : null;
    }

    private function apiFor(Server $server): ?LicenseServerClient
    {
        $key = trim((string) $server->access_hash);
        $host = $this->serverHost($server);

        if ($key === '' || $host === '') {
            return null;
        }

        return new LicenseServerClient(
            'https://'.$host.':'.((int) $server->port ?: self::DEFAULT_PORT).'/api/v1',
            $key,
            // The development License Server has a self-signed certificate.
            verifyTls: ! app()->environment('local', 'testing'),
        );
    }

    /** One PUT of the subscription's status; a service with no licence has nothing to change. */
    private function setStatus(Service $service, string $status, string $action, string $done): array
    {
        $data = $this->getModuleData($service);

        if (empty($data['subscription_id'])) {
            return $this->buildResult(true, __('esapanel-license::messages.result.nothing_yet'));
        }

        $api = $this->api($service);
        if (! $api) {
            return $this->buildResult(false, __('esapanel-license::messages.error.no_server'));
        }

        $response = $api->put('subscriptions/'.$data['subscription_id'].'/status', ['status' => $status]);
        if (! $response->ok) {
            return $this->failed($service, $response->error);
        }

        $this->refreshLicense($service);

        $result = $this->buildResult(true, $done);
        $this->logAction($service, $action, $result);

        return $result;
    }

    /**
     * Take back a licence the service already has at the License Server: make
     * it active again, on the product's plan, valid to the end of the period.
     *
     * @param  array<string, mixed>  $license
     */
    private function reactivate(LicenseServerClient $api, Service $service, array $license, int $planId): array
    {
        $subscription = (int) ($license['subscription_id'] ?? 0);
        if ($subscription === 0) {
            return $this->failed($service, __('esapanel-license::messages.error.no_license'));
        }

        if (($license['status'] ?? '') !== 'active') {
            $active = $api->put('subscriptions/'.$subscription.'/status', ['status' => 'active']);
            if (! $active->ok) {
                return $this->failed($service, $active->error);
            }
        }

        // The product may have changed while the licence was away.
        $plan = $api->put('subscriptions/'.$subscription.'/plan', ['service_plan_id' => $planId]);
        if (! $plan->ok) {
            return $this->failed($service, __('esapanel-license::messages.error.plan_change', ['error' => $plan->error]));
        }

        $synced = $this->syncAddons($api, $service, $subscription);
        if ($synced !== null) {
            return $this->failed($service, __('esapanel-license::messages.error.addons', ['error' => $synced]));
        }

        $until = $this->validUntil($service);
        if ($until !== null) {
            $moved = $api->put('subscriptions/'.$subscription.'/license', ['valid_until' => $until]);
            if (! $moved->ok) {
                return $this->failed($service, $moved->error);
            }
        }

        $this->storeLicense($service, $license, ['ended' => false]);
        $this->refreshLicense($service);

        $result = $this->buildResult(true, __('esapanel-license::messages.result.licensed'));
        $this->logAction($service, 'create', $result);

        return $result;
    }

    /**
     * The add-ons the service's options sell, by their License Server id; an
     * empty list for a product that sells none. A string says why they could
     * not be told: an add-on name the License Server does not know.
     *
     * @return list<int>|string
     */
    private function wantedAddonIds(LicenseServerClient $api, Service $service): array|string
    {
        $refs = $this->chosenAddons($service);
        if ($refs === null || $refs === []) {
            return [];
        }

        $byName = null;
        $ids = [];
        foreach ($refs as $ref) {
            if (ctype_digit($ref) && (int) $ref > 0) {
                $ids[] = (int) $ref;

                continue;
            }

            if ($byName === null) {
                $plans = $api->get('plans', ['type' => 'client_addon', 'per_page' => 100]);
                if (! $plans->ok) {
                    return $plans->error;
                }
                $byName = [];
                foreach ((array) $plans->data as $plan) {
                    if (is_array($plan) && ! empty($plan['id'])) {
                        $byName[self::addonKey((string) ($plan['label'] ?? ''))] = (int) $plan['id'];
                    }
                }
            }

            $id = $byName[self::addonKey($ref)] ?? null;
            if ($id === null) {
                return __('esapanel-license::messages.error.addon_unknown', ['addon' => $ref]);
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * What the service sells: the product's fixed add-ons, then what its
     * "addon|..." options chose (the value before the bar of each chosen
     * sub-option). Null when the product has neither, so a licence sold on a
     * plan with its modules keeps its add-ons.
     *
     * @return list<string>|null
     */
    private function chosenAddons(Service $service): ?array
    {
        // Add-ons the product always sells (a Trial with every module), in
        // its configuration: "addons": ["LSP CSP", ...].
        $fixed = array_values(array_filter(array_map(
            fn ($addon) => trim((string) $addon),
            (array) ($service->product?->config_options['addons'] ?? [])
        ), fn (string $addon) => $addon !== ''));

        $hasOption = $service->product?->configOptionGroups()
            ->whereHas('options', fn ($q) => $q->where('option_name', 'like', self::ADDON_OPTION.'|%'))
            ->exists();
        if (! $hasOption) {
            return $fixed !== [] ? $fixed : null;
        }

        $chosen = $fixed;
        foreach ($service->configOptions()->with('option', 'sub')->get() as $row) {
            $key = strtolower(trim(explode('|', (string) $row->option?->option_name, 2)[0]));
            if ($key !== self::ADDON_OPTION || ! $row->sub || (int) $row->qty < 1) {
                continue;
            }

            $value = trim(explode('|', (string) $row->sub->option_name, 2)[0]);
            if (! in_array(strtolower($value), self::NO_ADDON, true)) {
                $chosen[] = $value;
            }
        }

        return $chosen;
    }

    /**
     * Bring the licence's add-ons to what the service's options sell: the
     * missing ones first, so a module never goes without a size while one is
     * swapped for another, then the ones no longer sold. A product with no
     * add-on option changes nothing. Null when done, else why not.
     */
    private function syncAddons(LicenseServerClient $api, Service $service, int $subscription): ?string
    {
        if ($this->chosenAddons($service) === null) {
            return null;
        }

        $wanted = $this->wantedAddonIds($api, $service);
        if (is_string($wanted)) {
            return $wanted;
        }

        $current = $api->get('subscriptions/'.$subscription);
        if (! $current->ok) {
            return $current->error;
        }

        $have = [];
        foreach ((array) ($current->data['addons'] ?? []) as $addon) {
            if (is_array($addon) && ! empty($addon['id'])) {
                $have[(int) $addon['id']] = (int) ($addon['service_plan_id'] ?? 0);
            }
        }

        foreach (array_diff($wanted, $have) as $planId) {
            $added = $api->post('subscriptions/'.$subscription.'/addons', ['service_plan_id' => $planId]);
            if (! $added->ok) {
                return $added->error;
            }
        }

        foreach ($have as $addonId => $planId) {
            if (! in_array($planId, $wanted, true)) {
                $removed = $api->delete('subscriptions/'.$subscription.'/addons/'.$addonId);
                if (! $removed->ok) {
                    return $removed->error;
                }
            }
        }

        return null;
    }

    private static function addonKey(string $name): string
    {
        return strtolower((string) preg_replace('/\s+/', ' ', trim($name)));
    }

    private function findLicense(LicenseServerClient $api, Service $service): LicenseServerResponse
    {
        // The site's own ref, then the one used before refs named the site.
        foreach ([self::serviceRef($service), self::legacyRef('service', $service->id)] as $ref) {
            $found = $api->get('lsp/licenses', ['external_ref' => $ref]);
            if (! $found->ok) {
                return $found;
            }
            if (is_array($found->data[0] ?? null)) {
                return new LicenseServerResponse(true, 200, $found->data[0]);
            }
        }

        return new LicenseServerResponse(true, 200, null);
    }

    /**
     * The customer's id at the License Server: the one created for them
     * before, else one with their VAT number or their email, else a new one.
     *
     * @return int|string the id, or why there is none
     */
    private function customerId(LicenseServerClient $api, Service $service): int|string
    {
        $client = $service->client;
        if (! $client instanceof Client) {
            return __('esapanel-license::messages.error.no_client');
        }

        // A reseller's account sees only its own customers, so the VAT and
        // email searches never find another partner's.
        $searches = array_filter([
            ['external_ref', self::clientRef($client)],
            ['external_ref', self::legacyRef('client', $client->id)],
            ['vat_number', trim((string) $client->tax_id)],
            ['email', trim((string) $client->email)],
        ], fn (array $search) => $search[1] !== '');

        foreach ($searches as [$field, $value]) {
            $found = $api->get('customers', [$field => $value, 'per_page' => 1]);
            if (! $found->ok) {
                return $found->error;
            }
            if (! empty($found->data[0]['id'])) {
                return (int) $found->data[0]['id'];
            }
        }

        $person = trim($client->first_name.' '.$client->last_name);
        $name = trim((string) $client->company_name) ?: $person;

        $body = fn (string $ownerEmail) => array_filter([
            'name' => $name,
            'company_name' => trim((string) $client->company_name),
            'vat_number' => trim((string) $client->tax_id),
            'email' => (string) $client->email,
            'country' => (string) $client->country,
            'owner_name' => $person ?: $name,
            // Users are unique across the whole License Server, every
            // partner's included: the site's mark keeps two sites' client
            // #5 apart.
            'owner_username' => 'pnlcs-'.self::siteMark().'-'.$client->id,
            'owner_email' => $ownerEmail,
            // The owner never needs the License Server's panel: a password
            // nobody is told, and no mail asking them to choose one. It is
            // worked out, not drawn: a retry must send the same request, or
            // the Idempotency-Key is refused as used for another one.
            'password_mode' => 'password',
            'password' => $password = self::ownerPassword($client),
            'password_confirmation' => $password,
            'notes' => 'PNLCS client #'.$client->id,
            'external_ref' => self::clientRef($client),
        ], fn ($value) => $value !== '');
        $key = 'pnlcs-'.self::site().'-client-'.$client->id.'-create';

        $created = $api->post('customers', $body((string) $client->email), $key);

        // The same person bought from another partner first: their email is
        // already an owner there. The owner never signs in, so an address
        // of theirs marked for this site does.
        if (! $created->ok && $created->status === 422 && str_contains($created->error, 'owner_email')) {
            $created = $api->post('customers', $body(self::ownerAlias((string) $client->email, $client)), $key);
        }

        if (! $created->ok || empty($created->data['id'])) {
            return $created->error !== '' ? $created->error : __('esapanel-license::messages.error.no_client');
        }

        return (int) $created->data['id'];
    }

    /**
     * The licence's last day: the day the service is next due. Null for a
     * service with no due date (a Trial, a free product), which leaves the
     * License Server's own length for the plan.
     */
    private function validUntil(Service $service): ?string
    {
        $due = $service->next_due_date;

        return $due && $due->copy()->endOfDay()->isFuture() ? $due->toDateString() : null;
    }

    private function planId(Service $service): ?int
    {
        return self::planNumber((string) $this->getRemotePackage($service));
    }

    private static function planNumber(string $plan): ?int
    {
        return ctype_digit($plan) && (int) $plan > 0 ? (int) $plan : null;
    }

    private function isBound(Service $service): bool
    {
        return is_array($this->getModuleData($service)['installation'] ?? null);
    }

    /**
     * The refs carry the site: they are unique across the whole License
     * Server, so two partners' PNLCS client #5 must not share one.
     */
    private static function serviceRef(Service $service): string
    {
        return 'pnlcs:'.self::site().':service:'.$service->id;
    }

    private static function clientRef(Client $client): string
    {
        return 'pnlcs:'.self::site().':client:'.$client->id;
    }

    /** The refs written before they carried the site, still found. */
    private static function legacyRef(string $kind, int $id): string
    {
        return 'pnlcs:'.$kind.':'.$id;
    }

    /** A short mark of this site, for the names that cannot hold its host. */
    private static function siteMark(): string
    {
        return substr(hash('sha256', self::site()), 0, 6);
    }

    /** name@example.it -> name+pnlcs-1a2b3c-5@example.it */
    private static function ownerAlias(string $email, Client $client): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return $local.'+pnlcs-'.self::siteMark().'-'.$client->id.'@'.$domain;
    }

    private static function ownerPassword(Client $client): string
    {
        return 'Pn1-'.substr(hash_hmac('sha256', 'esapanel-license-owner-'.$client->id, (string) config('app.key')), 0, 40);
    }

    private static function site(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'pnlcs';
    }

    private function idempotencyKey(Service $service, string $what): string
    {
        return Str::limit('pnlcs-'.self::site().'-svc-'.$service->id.'-'.$what, 250, '');
    }

    /**
     * The service of a plan that carries a licence, or null for a plan that
     * sells none.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>|null
     */
    private static function licenseService(array $plan): ?array
    {
        foreach ((array) ($plan['services'] ?? []) as $service) {
            if (is_array($service) && ($service['code'] ?? '') === self::LICENSE_SERVICE) {
                return $service;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $licenseService */
    private static function isTrial(array $licenseService): bool
    {
        foreach ((array) ($licenseService['limits'] ?? []) as $limit) {
            if (is_array($limit) && ($limit['code'] ?? '') === self::LICENSE_SERVICE.'.trial') {
                // The License Server writes the flag as the word, not a boolean.
                return ($limit['value'] ?? '') === 'true';
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $license
     * @param  array<string, mixed>  $extra
     */
    private function storeLicense(Service $service, array $license, array $extra = []): void
    {
        $this->setModuleData($service, [
            'license_id' => $license['id'] ?? null,
            'subscription_id' => $license['subscription_id'] ?? null,
            'customer_id' => $license['customer_id'] ?? null,
            'code' => $license['code'] ?? null,
            'license_status' => $license['status'] ?? null,
            'modules' => array_values((array) ($license['modules'] ?? [])),
            'tier' => $license['tier'] ?? null,
            'trial' => (bool) ($license['trial'] ?? false),
            'valid_until' => $license['valid_until'] ?? null,
            'expired' => (bool) ($license['expired'] ?? false),
            'installation' => is_array($license['installation'] ?? null) ? $license['installation'] : null,
            'pending_transfer' => ! empty($license['pending_transfer']),
            'configuration_error' => $license['configuration_error'] ?? null,
            'checked_at' => now()->toIso8601String(),
            'last_error' => null,
        ] + $extra);
    }

    /** Remember a problem for the staff screen. */
    private function failed(Service $service, string $message): array
    {
        $this->setModuleData($service, ['last_error' => $message, 'last_error_at' => now()->toIso8601String()]);

        return $this->buildResult(false, $message);
    }
}
