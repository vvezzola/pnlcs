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

    private function findLicense(LicenseServerClient $api, Service $service): LicenseServerResponse
    {
        $found = $api->get('lsp/licenses', ['external_ref' => self::serviceRef($service)]);
        if (! $found->ok) {
            return $found;
        }

        $first = $found->data[0] ?? null;

        return new LicenseServerResponse(true, 200, is_array($first) ? $first : null);
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

        $searches = array_filter([
            'external_ref' => self::clientRef($client),
            'vat_number' => trim((string) $client->tax_id),
            'email' => trim((string) $client->email),
        ], fn ($value) => $value !== '');

        foreach ($searches as $field => $value) {
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

        $created = $api->post('customers', array_filter([
            'name' => $name,
            'company_name' => trim((string) $client->company_name),
            'vat_number' => trim((string) $client->tax_id),
            'email' => (string) $client->email,
            'country' => (string) $client->country,
            'owner_name' => $person ?: $name,
            'owner_username' => 'pnlcs-'.$client->id,
            'owner_email' => (string) $client->email,
            // The owner never needs the License Server's panel: a password
            // nobody is told, and no mail asking them to choose one. It is
            // worked out, not drawn: a retry must send the same request, or
            // the Idempotency-Key is refused as used for another one.
            'password_mode' => 'password',
            'password' => $password = self::ownerPassword($client),
            'password_confirmation' => $password,
            'notes' => 'PNLCS client #'.$client->id,
            'external_ref' => self::clientRef($client),
        ], fn ($value) => $value !== ''), 'pnlcs-'.self::site().'-client-'.$client->id.'-create');

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

    private static function serviceRef(Service $service): string
    {
        return 'pnlcs:service:'.$service->id;
    }

    private static function clientRef(Client $client): string
    {
        return 'pnlcs:client:'.$client->id;
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
