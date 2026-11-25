<?php

namespace Modules\Servers\Esapanel;

use App\Models\Client;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Modules\Servers\AbstractServerModule;

/**
 * Esapanel services (WFP, UCP, DRP, CSP...) sold as subscriptions on an
 * Esapanel server. Esalogica's module, given to the partners who sell
 * Esapanel; not part of PNLCS itself. The licences are another module
 * (EsapanelLicense).
 *
 * The Esapanel server is a server record (Setup -> Servers) of this type: its
 * hostname, port 9943 unless another is given, and the key of an API account
 * of the seller (a reseller or the admin) in the API key field. A product's
 * package is the service plan it sells; the plan's services say which module
 * the service is (wfp.storage: WFP). Configurable options named "addon|..."
 * sell the plan's add-ons, each choice naming one by its label or id.
 *
 * The same steps hold for every module: the customer (found by this site's
 * ref, VAT number or email, else created with its owner, who is mailed a link
 * to choose a password and signs in to Esapanel), the subscription, its
 * status, plan and add-ons. What a module shows and does beyond that comes
 * with that module's own part. Terminating disables the subscription and
 * deletes nothing: the customer's data stays on the server, and creating the
 * service again takes the same subscription back.
 */
class EsapanelModule extends AbstractServerModule
{
    public const NAME = 'esapanel';

    private const DEFAULT_PORT = 9943;

    /**
     * Modules that are not sold to a customer as a subscription: the License
     * Server and the secondary servers, which serve another Esapanel.
     */
    private const SERVICE_MODULES = ['core', 'lsp', 'lsph', 'csph', 'wfph'];

    /** A subscription is read back once a day by the hourly usage run, a few at a time. */
    private const REFRESH_AFTER_HOURS = 20;

    private const REFRESH_PER_RUN = 50;

    /** The key of the configurable options that sell an add-on ("addon|Extra space"). */
    private const ADDON_OPTION = 'addon';

    /** A choice that sells no add-on: "none|No" in a drop-down. */
    private const NO_ADDON = ['', 'none', '0', '-'];

    /**
     * The module's screens and words live beside its code. They are named here
     * and not by a service provider, so the module adds nothing to what the
     * site boots.
     */
    public function __construct()
    {
        view()->addNamespace('esapanel', __DIR__.'/views');
        app('translator')->addNamespace('esapanel', __DIR__.'/lang');
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
            return $this->buildResult(true, __('esapanel::messages.result.already_created'));
        }

        $api = $this->api($service);
        if (! $api) {
            return $this->buildResult(false, __('esapanel::messages.error.no_server'));
        }

        $planId = $this->planId($service);
        if ($planId === null) {
            return $this->buildResult(false, __('esapanel::messages.error.no_plan'));
        }

        // A subscription this service already has there: one ended here
        // before, or one whose creation answered after this side had stopped
        // listening.
        $existing = empty($data['subscription_id'])
            ? $this->findSubscription($api, $service)
            : $api->get('subscriptions/'.$data['subscription_id']);

        if (! $existing->ok && $existing->status !== 404) {
            return $this->failed($service, $existing->error);
        }

        if ($existing->ok && $existing->data !== null) {
            return $this->reactivate($api, $service, $existing->data, $planId);
        }

        // Known before anything is created, so an add-on name the server does
        // not know stops the order before a subscription exists.
        $addonIds = $this->wantedAddonIds($api, $service);
        if (is_string($addonIds)) {
            return $this->failed($service, $addonIds);
        }

        $customer = $this->customerId($api, $service);
        if (! is_int($customer)) {
            return $this->failed($service, $customer);
        }

        $created = $api->post('subscriptions', [
            'customer_id' => $customer,
            'service_plan_id' => $planId,
            'label' => Str::limit('PNLCS service #'.$service->id, 100, ''),
            'external_ref' => self::serviceRef($service),
        ], $this->idempotencyKey($service, 'create'));

        if (! $created->ok || empty($created->data['id'])) {
            return $this->failed($service, $created->error);
        }

        $this->storeSubscription($service, $created->data, ['ended' => false]);

        $synced = $this->syncAddons($api, $service, (int) $created->data['id']);
        if ($synced !== null) {
            return $this->failed($service, __('esapanel::messages.error.addons', ['error' => $synced]));
        }

        $this->refreshSubscription($service);

        $result = $this->buildResult(true, __('esapanel::messages.result.created'));
        $this->logAction($service, 'create', $result);

        return $result;
    }

    public function suspend(Service $service, string $reason = ''): array
    {
        return $this->setStatus($service, 'suspended', 'suspend', __('esapanel::messages.result.suspended'));
    }

    public function unsuspend(Service $service): array
    {
        if (! empty($this->getModuleData($service)['ended'])) {
            return $this->buildResult(false, __('esapanel::messages.error.ended'));
        }

        return $this->setStatus($service, 'active', 'unsuspend', __('esapanel::messages.result.active'));
    }

    /**
     * Disable the subscription. Nothing is deleted: the customer's data stays
     * on the server until staff remove it there, and the same subscription
     * comes back if the service is created again.
     */
    public function terminate(Service $service): array
    {
        $result = $this->setStatus($service, 'disabled', 'terminate', __('esapanel::messages.result.ended'));

        if ($result['success']) {
            $this->setModuleData($service, ['ended' => true]);
        }

        return $result;
    }

    /**
     * The customer chooses their Esapanel password there, from the link it
     * mailed them or with "forgot password": this site never knows it.
     */
    public function changePassword(Service $service, string $newPassword): array
    {
        return $this->buildResult(false, __('esapanel::messages.error.no_password'));
    }

    /** Move the subscription to the new product's plan, then its add-ons. */
    public function changePackage(Service $service, array $newPackage): array
    {
        $config = is_string($newPackage['config_options'] ?? null)
            ? json_decode($newPackage['config_options'], true)
            : ($newPackage['config_options'] ?? []);
        $planId = self::planNumber((string) self::planFrom((array) $config, ['package_name']));

        if ($planId === null) {
            return $this->buildResult(false, __('esapanel::messages.error.no_plan'));
        }

        $data = $this->getModuleData($service);
        if (empty($data['subscription_id'])) {
            return $this->buildResult(true, __('esapanel::messages.result.nothing_yet'));
        }

        $api = $this->api($service);
        if (! $api) {
            return $this->buildResult(false, __('esapanel::messages.error.no_server'));
        }

        if ((int) ($data['plan_id'] ?? 0) !== $planId) {
            // Refused when the customer uses more than the new plan allows:
            // staff see the server's own words and settle it there.
            $changed = $api->put('subscriptions/'.$data['subscription_id'].'/plan', ['service_plan_id' => $planId]);
            if (! $changed->ok) {
                return $this->failed($service, __('esapanel::messages.error.plan_change', ['error' => $changed->error]));
            }
        }

        $synced = $this->syncAddons($api, $service, (int) $data['subscription_id']);
        if ($synced !== null) {
            return $this->failed($service, __('esapanel::messages.error.addons', ['error' => $synced]));
        }

        $this->refreshSubscription($service);

        $result = $this->buildResult(true, __('esapanel::messages.result.plan_changed'));
        $this->logAction($service, 'changePackage', $result);

        return $result;
    }

    /** Read the subscription back from the server. */
    public function refreshSubscription(Service $service): array
    {
        $data = $this->getModuleData($service);
        $api = $this->api($service);

        if (empty($data['subscription_id']) || ! $api) {
            return $this->buildResult(false, __('esapanel::messages.error.no_subscription'));
        }

        $subscription = $api->get('subscriptions/'.$data['subscription_id']);
        if (! $subscription->ok || $subscription->data === null) {
            return $this->failed($service, $subscription->error);
        }

        $this->storeSubscription($service, $subscription->data);

        // What the module's own part shows: for WFP, the space and accounts.
        // A new subscription may not have its space on the server yet, so a
        // failed read keeps what was read before and says why beside it.
        if (self::moduleOf((array) ($subscription->data['services'] ?? [])) === WfpPart::MODULE) {
            [$wfp, $error] = (new WfpPart($api))->read((int) $data['subscription_id']);
            $this->setModuleData($service, $wfp !== null ? ['wfp' => $wfp, 'wfp_error' => null] : ['wfp_error' => $error]);
        }

        return $this->buildResult(true, __('esapanel::messages.result.refreshed'));
    }

    /**
     * Something the customer does to the accounts of a WFP subscription:
     * "create" (name, kind, protocols, description), "password", "suspend"
     * or "resume" (account). A password made is in the result's data, once.
     *
     * @param  array<string, mixed>  $input
     */
    public function wfpAction(Service $service, string $action, array $input = []): array
    {
        $data = $this->getModuleData($service);
        $api = $this->api($service);

        if (empty($data['subscription_id']) || ! empty($data['ended']) || ($data['module'] ?? '') !== WfpPart::MODULE || ! $api) {
            return $this->buildResult(false, __('esapanel::messages.error.no_subscription'));
        }

        $subscription = (int) $data['subscription_id'];
        $wfp = new WfpPart($api);
        $account = (int) ($input['account'] ?? 0);

        if ($action !== 'create' && ($account < 1 || ! $wfp->belongs($subscription, $account))) {
            return $this->buildResult(false, __('esapanel::messages.wfp.no_account'));
        }

        $answer = match ($action) {
            'create' => $wfp->createAccount($subscription, [
                'name' => (string) ($input['name'] ?? ''),
                'kind' => (string) ($input['kind'] ?? ''),
                'protocols' => array_values((array) ($input['protocols'] ?? [])),
                'description' => $input['description'] ?? null,
            ]),
            'password' => $wfp->newPassword($account),
            'suspend' => $wfp->suspend($account),
            'resume' => $wfp->resume($account),
            default => null,
        };

        if ($answer === null) {
            return $this->buildResult(false, __('esapanel::messages.error.no_subscription'));
        }

        if (! $answer->ok) {
            return $this->buildResult(false, $answer->error);
        }

        $this->refreshSubscription($service);

        $result = $this->buildResult(true, __('esapanel::messages.wfp.done.'.$action), array_filter([
            'username' => $answer->data['username'] ?? null,
            'password' => $answer->data['password'] ?? null,
        ]));
        $this->logAction($service, 'wfp '.$action, ['success' => true, 'message' => $result['message']]);

        return $result;
    }

    /**
     * The hourly usage run: read back the subscriptions of this server that
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
            $this->refreshSubscription($service)['success'] ? $done['updated']++ : $done['errors']++;
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
        return ! empty($this->getModuleData($service)['subscription_id']);
    }

    /** @return array<string, string> staff buttons, method => label */
    public function customFunctions(?Service $service = null): array
    {
        return ['refreshSubscription' => __('esapanel::messages.action.refresh')];
    }

    /** @return array<string, string> customer buttons, method => label */
    public function clientFunctions(?Service $service = null): array
    {
        return [];
    }

    /**
     * The way into Esapanel. There is no single sign-on: the link opens its
     * sign-in page, where the customer uses the username shown here and the
     * password they chose.
     */
    public function ssoLogin(Service $service): array
    {
        $data = $this->getModuleData($service);
        $url = $this->panelUrl($service);

        if (empty($data['subscription_id']) || ! empty($data['ended']) || $url === null) {
            return $this->buildResult(false, __('esapanel::messages.error.no_subscription'));
        }

        return $this->buildResult(true, '', ['url' => $url]);
    }

    /** @return array<string, string> */
    public function emailMergeFields(Service $service): array
    {
        $data = $this->getModuleData($service);

        return [
            'service_panel_url' => $this->panelUrl($service) ?? '',
            'service_panel_username' => (string) ($data['owner_username'] ?? ''),
        ];
    }

    public function clientAreaPanel(Service $service): ?Htmlable
    {
        return view('esapanel::client', $this->screenData($service));
    }

    public function adminAreaPanel(Service $service): ?Htmlable
    {
        return view('esapanel::admin', $this->screenData($service));
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
            ! empty($data['ended']), ($data['subscription_status'] ?? '') === 'disabled' => 'ended',
            empty($data['subscription_id']) => 'none',
            ($data['subscription_status'] ?? '') === 'suspended' => 'suspended',
            default => 'active',
        };

        return [
            'service' => $service,
            'state' => $state,
            'data' => $data,
            'module' => (string) ($data['module'] ?? ''),
            'panelUrl' => $state === 'ended' ? null : $this->panelUrl($service),
            'wfp' => ($data['module'] ?? '') === WfpPart::MODULE && is_array($data['wfp'] ?? null) ? $data['wfp'] : null,
        ];
    }

    // ------------------------------------------------------------------
    // The Esapanel server (a server record of this type)
    // ------------------------------------------------------------------

    /**
     * The plans sold to customers, for the product form, each named with its
     * module ("WFP · EsaBackup 500 GB"): only the modules the server sells
     * (sellableModules()), so neither the licence plans nor the modules that
     * serve another Esapanel.
     *
     * @return list<array{id: string, name: string}>
     */
    public function listPackages(?Server $server = null): array
    {
        $sellable = $server ? $this->sellableModules($server) : [];

        $packages = [];
        foreach ($this->plans($server, 'client') as $plan) {
            $module = self::moduleOf((array) ($plan['services'] ?? []));
            if (! empty($plan['sells_license']) || $module === null || ! in_array($module, $sellable, true)) {
                continue;
            }

            $packages[] = ['id' => (string) $plan['id'], 'name' => strtoupper($module).' · '.($plan['label'] ?? $plan['id'])];
        }

        return $packages;
    }

    /**
     * The add-ons the server sells, by name => id, for the products'
     * "addon|..." options.
     *
     * @return array<string, int>
     */
    public function listAddons(Server $server): array
    {
        $addons = [];
        foreach ($this->plans($server, 'client_addon') as $plan) {
            if (($plan['label'] ?? '') !== '') {
                $addons[(string) $plan['label']] = (int) $plan['id'];
            }
        }

        return $addons;
    }

    /**
     * The modules this server sells to customers, by code: those its
     * license covers, with plans for customers, that the API key may work
     * on. Licences are the other module's (EsapanelLicense), and the
     * secondary modules serve another Esapanel. A server without the modules
     * call (before it came) is read from its plans' services.
     *
     * @return list<string>
     */
    public function sellableModules(Server $server): array
    {
        $modules = $this->serverModules($server);

        if ($modules !== null) {
            return array_values(array_map(fn (array $module) => (string) $module['code'], array_filter(
                $modules,
                fn (array $module) => ($module['role'] ?? '') === 'customer' && ! empty($module['active'])
                    && ! empty($module['sellable']) && ! empty($module['api_actions'])
                    && ! in_array((string) ($module['code'] ?? ''), self::SERVICE_MODULES, true)
            )));
        }

        $found = [];
        foreach ($this->plans($server, 'client') as $plan) {
            $module = self::moduleOf((array) ($plan['services'] ?? []));
            if ($module !== null && empty($plan['sells_license']) && ! in_array($module, self::SERVICE_MODULES, true)) {
                $found[$module] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * The server's modules as it lists them (GET /server/modules): code,
     * name, role, active, sellable, services, api_actions. Null when the
     * server has no such call yet.
     *
     * @return list<array<string, mixed>>|null
     */
    public function serverModules(Server $server): ?array
    {
        $api = $this->apiFor($server);
        if (! $api) {
            throw new \RuntimeException(__('esapanel::messages.error.no_server'));
        }

        $answer = $api->get('server/modules');
        if ($answer->status === 404) {
            return null;
        }
        if (! $answer->ok) {
            throw new \RuntimeException($answer->error);
        }

        return array_values(array_filter((array) $answer->data, fn ($module) => is_array($module) && ! empty($module['code'])));
    }

    public function testConnection(Server $server): bool
    {
        return (bool) $this->apiFor($server)?->get('me')->ok;
    }

    // ------------------------------------------------------------------

    /**
     * Every plan of one type, all pages.
     *
     * @return list<array<string, mixed>>
     */
    private function plans(?Server $server, string $type): array
    {
        $api = $server ? $this->apiFor($server) : null;
        if (! $api) {
            throw new \RuntimeException(__('esapanel::messages.error.no_server'));
        }

        $plans = [];
        for ($page = 1; $page <= 20; $page++) {
            $answer = $api->get('plans', ['type' => $type, 'per_page' => 100, 'page' => $page]);
            if (! $answer->ok) {
                throw new \RuntimeException($answer->error);
            }

            foreach ((array) $answer->data as $plan) {
                if (is_array($plan) && ! empty($plan['id'])) {
                    $plans[] = $plan;
                }
            }

            if ($page >= (int) ($answer->meta['last_page'] ?? 1)) {
                break;
            }
        }

        return $plans;
    }

    private function api(Service $service): ?EsapanelClient
    {
        $server = $this->getServer($service);

        return $server ? $this->apiFor($server) : null;
    }

    private function apiFor(Server $server): ?EsapanelClient
    {
        $key = trim((string) $server->access_hash);
        $host = $this->serverHost($server);

        if ($key === '' || $host === '') {
            return null;
        }

        return new EsapanelClient(
            'https://'.$host.':'.((int) $server->port ?: self::DEFAULT_PORT).'/api/v1',
            $key,
            // Development servers have a self-signed certificate.
            verifyTls: ! app()->environment('local', 'testing'),
        );
    }

    /**
     * Esapanel's sign-in page on the service's server, by its name: the
     * certificate is the name's, and a reseller's customers sign in on it.
     */
    private function panelUrl(Service $service): ?string
    {
        $server = $service->server;
        $host = $server ? trim((string) $server->hostname) : '';

        return $host !== '' ? 'https://'.$host.':'.((int) $server->port ?: self::DEFAULT_PORT).'/' : null;
    }

    /** One PUT of the subscription's status; a service with no subscription has nothing to change. */
    private function setStatus(Service $service, string $status, string $action, string $done): array
    {
        $data = $this->getModuleData($service);

        if (empty($data['subscription_id'])) {
            return $this->buildResult(true, __('esapanel::messages.result.nothing_yet'));
        }

        $api = $this->api($service);
        if (! $api) {
            return $this->buildResult(false, __('esapanel::messages.error.no_server'));
        }

        $response = $api->put('subscriptions/'.$data['subscription_id'].'/status', ['status' => $status]);
        if (! $response->ok) {
            return $this->failed($service, $response->error);
        }

        if ($response->data !== null) {
            $this->storeSubscription($service, $response->data);
        }

        $result = $this->buildResult(true, $done);
        $this->logAction($service, $action, $result);

        return $result;
    }

    /**
     * Take back a subscription the service already has on the server: active
     * again, on the product's plan, with the product's add-ons.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function reactivate(EsapanelClient $api, Service $service, array $subscription, int $planId): array
    {
        $id = (int) ($subscription['id'] ?? 0);
        if ($id === 0) {
            return $this->failed($service, __('esapanel::messages.error.no_subscription'));
        }

        if (($subscription['status'] ?? '') !== 'active') {
            $active = $api->put('subscriptions/'.$id.'/status', ['status' => 'active']);
            if (! $active->ok) {
                return $this->failed($service, $active->error);
            }
        }

        // The product may have changed while the subscription was away.
        if ((int) ($subscription['service_plan']['id'] ?? 0) !== $planId) {
            $plan = $api->put('subscriptions/'.$id.'/plan', ['service_plan_id' => $planId]);
            if (! $plan->ok) {
                return $this->failed($service, __('esapanel::messages.error.plan_change', ['error' => $plan->error]));
            }
        }

        $this->storeSubscription($service, $subscription, ['ended' => false]);

        $synced = $this->syncAddons($api, $service, $id);
        if ($synced !== null) {
            return $this->failed($service, __('esapanel::messages.error.addons', ['error' => $synced]));
        }

        $this->refreshSubscription($service);

        $result = $this->buildResult(true, __('esapanel::messages.result.reactivated'));
        $this->logAction($service, 'create', $result);

        return $result;
    }

    private function findSubscription(EsapanelClient $api, Service $service): EsapanelResponse
    {
        $found = $api->get('subscriptions', ['external_ref' => self::serviceRef($service)]);
        if (! $found->ok) {
            return $found;
        }

        return new EsapanelResponse(true, 200, is_array($found->data[0] ?? null) ? $found->data[0] : null);
    }

    /**
     * The add-ons the service's options sell, by their server id; an empty
     * list for a product that sells none. A string says why they could not
     * be told: an add-on name the server does not know.
     *
     * @return list<int>|string
     */
    private function wantedAddonIds(EsapanelClient $api, Service $service): array|string
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
                return __('esapanel::messages.error.addon_unknown', ['addon' => $ref]);
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * What the service sells: the product's fixed add-ons, then what its
     * "addon|..." options chose (the value before the bar of each chosen
     * sub-option). Null when the product has neither, so the subscription's
     * add-ons are left as staff set them on the server.
     *
     * @return list<string>|null
     */
    private function chosenAddons(Service $service): ?array
    {
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
     * Bring the subscription's add-ons to what the service's options sell:
     * the missing ones first, then the ones no longer sold. A product with no
     * add-on option changes nothing. Null when done, else why not.
     */
    private function syncAddons(EsapanelClient $api, Service $service, int $subscription): ?string
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

    /**
     * The customer's id on the server: the one created for them before, else
     * one with their VAT number or their email, else a new one.
     *
     * A reseller's API account sees only its own customers, so the VAT and
     * email searches never find another seller's.
     *
     * @return int|string the id, or why there is none
     */
    private function customerId(EsapanelClient $api, Service $service): int|string
    {
        $client = $service->client;
        if (! $client instanceof Client) {
            return __('esapanel::messages.error.no_client');
        }

        $searches = array_filter([
            ['external_ref', self::clientRef($client)],
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
        $username = self::ownerUsername($client);

        $created = $api->post('customers', array_filter([
            'name' => $name,
            'company_name' => trim((string) $client->company_name),
            'vat_number' => trim((string) $client->tax_id),
            'tax_code' => trim((string) $client->national_id),
            'email' => (string) $client->email,
            'street' => trim($client->address1.' '.$client->address2),
            'city' => trim((string) $client->city),
            'stateprov' => trim((string) $client->state),
            'zipcode' => trim((string) $client->postcode),
            'country' => strtoupper(trim((string) $client->country)),
            'owner_name' => $person ?: $name,
            // The owner is the customer, who signs in to Esapanel: the server
            // mails them a link to choose their password, so this site never
            // holds it and a retry sends the same request.
            'owner_username' => $username,
            'owner_email' => (string) $client->email,
            'password_mode' => 'link',
            'notes' => 'PNLCS client #'.$client->id,
            'external_ref' => self::clientRef($client),
        ], fn ($value) => $value !== ''), 'pnlcs-'.self::site().'-client-'.$client->id.'-create');

        if (! $created->ok || empty($created->data['id'])) {
            return $created->error !== '' ? $created->error : __('esapanel::messages.error.no_client');
        }

        $this->setModuleData($service, ['owner_username' => $username]);

        return (int) $created->data['id'];
    }

    private function planId(Service $service): ?int
    {
        return self::planNumber((string) $this->getRemotePackage($service));
    }

    private static function planNumber(string $plan): ?int
    {
        return ctype_digit($plan) && (int) $plan > 0 ? (int) $plan : null;
    }

    /**
     * The module a plan or a subscription belongs to, from its services'
     * codes ("wfp.storage" => "wfp"); null when it has none.
     *
     * @param  list<mixed>  $services  codes, or {code: ...} as a plan lists them
     */
    public static function moduleOf(array $services): ?string
    {
        foreach ($services as $service) {
            $code = is_array($service) ? (string) ($service['code'] ?? '') : (string) $service;
            $module = strtolower(explode('.', $code, 2)[0]);
            if ($module !== '' && $module !== 'core') {
                return $module;
            }
        }

        return null;
    }

    /**
     * The refs carry the site: they are unique across the whole server, so
     * two sellers' PNLCS client #5 must not share one.
     */
    private static function serviceRef(Service $service): string
    {
        return 'pnlcs:'.self::site().':service:'.$service->id;
    }

    private static function clientRef(Client $client): string
    {
        return 'pnlcs:'.self::site().':client:'.$client->id;
    }

    /**
     * The customer's username on the server: unique there across every
     * seller, so it carries a mark of this site (c-1a2b3c-5).
     */
    private static function ownerUsername(Client $client): string
    {
        return 'c-'.substr(hash('sha256', self::site()), 0, 6).'-'.$client->id;
    }

    private static function site(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'pnlcs';
    }

    private function idempotencyKey(Service $service, string $what): string
    {
        return Str::limit('pnlcs-'.self::site().'-svc-'.$service->id.'-'.$what, 200, '');
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @param  array<string, mixed>  $extra
     */
    private function storeSubscription(Service $service, array $subscription, array $extra = []): void
    {
        $services = array_values(array_map('strval', (array) ($subscription['services'] ?? [])));

        $this->setModuleData($service, [
            'subscription_id' => $subscription['id'] ?? null,
            'customer_id' => $subscription['customer_id'] ?? null,
            'plan_id' => $subscription['service_plan']['id'] ?? null,
            'plan_label' => $subscription['service_plan']['label'] ?? null,
            'subscription_status' => $subscription['status'] ?? null,
            'suspension_reason' => $subscription['suspension_reason'] ?? null,
            'sync_status' => $subscription['sync_status'] ?? null,
            'services' => $services,
            'module' => self::moduleOf($services),
            'addons' => array_values(array_map(
                fn ($addon) => ['id' => $addon['id'] ?? null, 'label' => $addon['label'] ?? null],
                array_filter((array) ($subscription['addons'] ?? []), 'is_array')
            )),
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
