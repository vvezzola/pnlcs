<?php

use App\Models\Client;
use App\Models\ConfigOption;
use App\Models\ConfigOptionGroup;
use App\Models\ConfigOptionSub;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceConfigOption;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Servers\Esapanel\EsapanelModule;

/*
 * Esapanel services sold as subscriptions on an Esapanel server
 * (modules/Servers/Esapanel, Esalogica only). The server is faked at the
 * HTTP level with the shapes its API v1 answers with.
 */

const EP_API = 'https://wfp.test:9943/api/v1/';

/** @return array<string, mixed> a subscription as Esapanel answers it */
function epSubscription(array $over = []): array
{
    return array_merge([
        'id' => 310, 'external_ref' => null, 'label' => 'PNLCS service', 'customer_id' => 57,
        'service_plan' => ['id' => 12, 'label' => 'EsaBackup 500 GB', 'type' => 'client'],
        'status' => 'active', 'suspension_reason' => null, 'sync_status' => 'synced',
        'services' => ['wfp.storage'], 'addons' => [],
        'start_date' => '2026-11-25T00:00:00Z', 'end_date' => null, 'created_at' => '2026-11-25T10:00:00Z',
    ], $over);
}

/** @return array<string, mixed> a plan as Esapanel answers it */
function epPlan(array $over = []): array
{
    return array_merge(['id' => 12, 'label' => 'WFP Base', 'type' => 'client', 'sells_license' => false, 'services' => [['code' => 'wfp.storage']]], $over);
}

/**
 * A fake Esapanel server: answers by "METHOD path", and remembers every call.
 *
 * @param  array<string, mixed>  $answers  "METHOD path" => data, or [status, body]
 * @return ArrayObject<int, array{method: string, path: string, body: array<string, mixed>, query: array<string, mixed>, key: ?string}>
 */
function epServer(array $answers): ArrayObject
{
    $calls = new ArrayObject;
    Http::preventStrayRequests();
    Http::fake([EP_API.'*' => function (Request $request) use ($answers, $calls) {
        $path = explode('?', substr($request->url(), strlen(EP_API)))[0];
        $calls->append([
            'method' => $request->method(), 'path' => $path,
            'body' => $request->method() === 'GET' ? [] : (array) $request->data(),
            'query' => $request->method() === 'GET' ? (array) $request->data() : [],
            'key' => $request->header('Idempotency-Key')[0] ?? null,
        ]);
        $answer = $answers[$request->method().' '.$path] ?? [404, ['message' => 'Not found']];

        return is_array($answer) && is_int($answer[0] ?? null)
            ? Http::response($answer[1], $answer[0])
            : Http::response(['data' => $answer], 200);
    }]);

    return $calls;
}

function epService(array $moduleData = [], array $over = []): Service
{
    $server = Server::factory()->create([
        'type' => EsapanelModule::NAME, 'hostname' => 'wfp.test', 'ip_address' => '', 'port' => null,
        'access_hash' => 'key|with|pipes', 'active' => true, 'disabled' => false,
    ]);
    $product = Product::factory()->create([
        'server_type' => EsapanelModule::NAME, 'config_options' => ['package_name' => '12'],
    ]);
    $client = Client::factory()->create([
        'first_name' => 'Mario', 'last_name' => 'Rossi', 'company_name' => 'Rossi Srl',
        'email' => 'mario@rossi.test', 'tax_id' => 'IT01234567890', 'country' => 'it',
    ]);

    return Service::factory()->create(array_merge([
        'client_id' => $client->id, 'product_id' => $product->id, 'server_id' => $server->id,
        'status' => 'active', 'billing_cycle' => 'annually', 'next_due_date' => now()->addYear()->toDateString(),
        'module_data' => $moduleData ?: null,
    ], $over));
}

/** Give the service's product an "addon|..." option and choose $choice on the service. */
function epChooseAddon(Service $service, string $choice): void
{
    $group = ConfigOptionGroup::create(['name' => 'Esapanel extras']);
    $service->product->configOptionGroups()->attach($group->id);
    $option = ConfigOption::create(['group_id' => $group->id, 'option_name' => 'addon|Extra space', 'option_type' => 'dropdown']);
    $none = ConfigOptionSub::create(['config_id' => $option->id, 'option_name' => 'none|No', 'sort_order' => 0]);
    $sub = $choice === 'none' ? $none : ConfigOptionSub::create(['config_id' => $option->id, 'option_name' => $choice, 'sort_order' => 1]);
    ServiceConfigOption::create(['service_id' => $service->id, 'config_id' => $option->id, 'option_id' => $sub->id, 'qty' => 1, 'unit_price' => 0]);
}

function epSite(): string
{
    return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'pnlcs';
}

/** @return list<string> "METHOD path" of each call, in order */
function epPaths(ArrayObject $calls): array
{
    return array_map(fn (array $call) => $call['method'].' '.$call['path'], $calls->getArrayCopy());
}

it('creates the customer, who signs in to Esapanel, and the subscription for a new service', function () {
    $service = epService();
    $calls = epServer([
        'GET subscriptions' => [],
        'GET plans/12' => epPlan(),
        'GET customers' => [],
        'POST customers' => ['id' => 57],
        'POST subscriptions' => epSubscription(),
        'GET subscriptions/310' => epSubscription(),
    ]);

    $result = (new EsapanelModule)->create($service);

    expect($result['success'])->toBeTrue();
    expect(epPaths($calls))->toBe([
        'GET subscriptions', 'GET plans/12', 'GET customers', 'GET customers', 'GET customers', 'POST customers', 'POST subscriptions', 'GET subscriptions/310', 'GET subscriptions/310/wfp/storage',
    ]);

    $customer = $calls[5];
    expect($customer['body'])->toMatchArray([
        'name' => 'Rossi Srl', 'vat_number' => 'IT01234567890', 'email' => 'mario@rossi.test', 'country' => 'IT',
        'owner_email' => 'mario@rossi.test', 'password_mode' => 'link',
        'owner_username' => 'c-'.substr(hash('sha256', epSite()), 0, 6).'-'.$service->client_id,
        'external_ref' => 'pnlcs:'.epSite().':client:'.$service->client_id,
    ]);
    expect($customer['body'])->not->toHaveKey('password');
    expect($customer['key'])->not->toBeNull();

    expect($calls[6]['body'])->toMatchArray([
        'customer_id' => 57, 'service_plan_id' => 12, 'external_ref' => 'pnlcs:'.epSite().':service:'.$service->id,
    ]);
    expect($calls[6]['key'])->toContain('svc-'.$service->id.'-create');

    expect($service->fresh()->module_data)->toMatchArray([
        'subscription_id' => 310, 'customer_id' => 57, 'plan_id' => 12, 'module' => 'wfp',
        'subscription_status' => 'active', 'ended' => false,
        'owner_username' => 'c-'.substr(hash('sha256', epSite()), 0, 6).'-'.$service->client_id,
    ]);
});

it('uses the customer Esapanel already has for the client instead of creating one', function () {
    $service = epService();
    $calls = epServer([
        'GET subscriptions' => [],
        'GET plans/12' => epPlan(),
        'GET customers' => [['id' => 91]],
        'POST subscriptions' => epSubscription(['customer_id' => 91]),
        'GET subscriptions/310' => epSubscription(['customer_id' => 91]),
    ]);

    expect((new EsapanelModule)->create($service)['success'])->toBeTrue();

    expect(epPaths($calls))->toBe(['GET subscriptions', 'GET plans/12', 'GET customers', 'POST subscriptions', 'GET subscriptions/310', 'GET subscriptions/310/wfp/storage']);
    expect($calls[2]['query'])->toMatchArray(['external_ref' => 'pnlcs:'.epSite().':client:'.$service->client_id]);
    expect($calls[3]['body'])->toMatchArray(['customer_id' => 91]);
    expect($service->fresh()->module_data)->not->toHaveKey('owner_username');
});

it('takes back the subscription the service already has, active again on the product\'s plan', function () {
    $service = epService(['subscription_id' => 310, 'ended' => true]);
    $calls = epServer([
        'GET subscriptions/310' => epSubscription(['status' => 'disabled', 'service_plan' => ['id' => 11, 'label' => 'EsaBackup 200 GB', 'type' => 'client']]),
        'PUT subscriptions/310/status' => epSubscription(),
        'PUT subscriptions/310/plan' => epSubscription(),
    ]);

    $result = (new EsapanelModule)->create($service);

    expect($result['success'])->toBeTrue();
    expect(epPaths($calls))->toBe(['GET subscriptions/310', 'PUT subscriptions/310/status', 'PUT subscriptions/310/plan', 'GET subscriptions/310', 'GET subscriptions/310/wfp/storage']);
    expect($calls[1]['body'])->toBe(['status' => 'active']);
    expect($calls[2]['body'])->toBe(['service_plan_id' => 12]);
    expect($service->fresh()->module_data['ended'])->toBeFalse();
});

it('adds the add-on the options sell, found by its name on the server', function () {
    $service = epService();
    epChooseAddon($service, 'Extra 500 GB|Extra 500 GB');
    $calls = epServer([
        'GET subscriptions' => [],
        'GET plans' => [['id' => 40, 'label' => 'Extra 500 GB']],
        'GET plans/12' => epPlan(),
        'GET customers' => [['id' => 57]],
        'POST subscriptions' => epSubscription(),
        'GET subscriptions/310' => epSubscription(),
        'POST subscriptions/310/addons' => epSubscription(),
    ]);

    expect((new EsapanelModule)->create($service)['success'])->toBeTrue();

    $added = collect($calls)->firstWhere('path', 'subscriptions/310/addons');
    expect($added['body'])->toBe(['service_plan_id' => 40]);
});

it('creates nothing when an add-on the options sell is unknown to the server', function () {
    $service = epService();
    epChooseAddon($service, 'Extra 9 TB|Extra 9 TB');
    $calls = epServer([
        'GET subscriptions' => [],
        'GET plans' => [['id' => 40, 'label' => 'Extra 500 GB']],
    ]);

    $result = (new EsapanelModule)->create($service);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Extra 9 TB');
    expect(epPaths($calls))->not->toContain('POST customers')->not->toContain('POST subscriptions');
    expect($service->fresh()->module_data['last_error'])->toContain('Extra 9 TB');
});

it('suspends, reactivates and disables the subscription, and a disabled one is not reactivated by unsuspend', function () {
    $service = epService(['subscription_id' => 310]);
    $calls = epServer([
        'PUT subscriptions/310/status' => epSubscription(),
    ]);
    $module = new EsapanelModule;

    expect($module->suspend($service)['success'])->toBeTrue();
    expect($module->unsuspend($service->fresh())['success'])->toBeTrue();
    expect($module->terminate($service->fresh())['success'])->toBeTrue();

    expect(array_map(fn ($call) => $call['body']['status'], $calls->getArrayCopy()))->toBe(['suspended', 'active', 'disabled']);
    expect(epPaths($calls))->not->toContain('DELETE subscriptions/310');
    expect($service->fresh()->module_data['ended'])->toBeTrue();

    $result = $module->unsuspend($service->fresh());
    expect($result['success'])->toBeFalse();
    expect($calls)->toHaveCount(3);
});

it('moves the subscription to the new product\'s plan, and says why when Esapanel refuses', function () {
    $service = epService(['subscription_id' => 310, 'plan_id' => 12]);
    $calls = epServer([
        'PUT subscriptions/310/plan' => [422, ['message' => 'Invalid', 'errors' => ['service_plan_id' => ['The usage is above the new plan.']]]],
    ]);
    $module = new EsapanelModule;

    $refused = $module->changePackage($service, ['config_options' => ['package_name' => '11']]);
    expect($refused['success'])->toBeFalse()
        ->and($refused['message'])->toContain('The usage is above the new plan.');
    expect($calls[0]['body'])->toBe(['service_plan_id' => 11]);

    $calls = epServer(['GET subscriptions/310' => epSubscription()]);
    expect($module->changePackage($service->fresh(), ['config_options' => ['package_name' => '12']])['success'])->toBeTrue();
    expect(epPaths($calls))->toBe(['GET subscriptions/310']);
});

it('offers the plans sold to customers, named with their module, and tells the modules apart', function () {
    $server = epService()->server;
    epServer(['GET plans' => [
        ['id' => 12, 'label' => 'EsaBackup 500 GB', 'sells_license' => false, 'services' => [['code' => 'wfp.storage']]],
        ['id' => 20, 'label' => 'Uptime 50', 'sells_license' => false, 'services' => [['code' => 'core.base'], ['code' => 'ucp.monitoring']]],
        ['id' => 5, 'label' => 'LSP Company', 'sells_license' => true, 'services' => [['code' => 'lsp.license']]],
        ['id' => 7, 'label' => 'Remote copies', 'sells_license' => false, 'services' => [['code' => 'wfph.remote']]],
    ]]);
    $module = new EsapanelModule;

    expect($module->listPackages($server))->toBe([
        ['id' => '12', 'name' => 'WFP · EsaBackup 500 GB'],
        ['id' => '20', 'name' => 'UCP · Uptime 50'],
    ]);
    expect($module->sellableModules($server))->toBe(['wfp', 'ucp']);
});

it('sells only the modules the server lists as covered, with customer plans the key may work on', function () {
    $server = epService()->server;
    epServer([
        'GET server/modules' => [
            ['code' => 'wfp', 'role' => 'customer', 'active' => true, 'sellable' => true, 'api_actions' => true, 'services' => ['wfp.storage']],
            ['code' => 'ucp', 'role' => 'customer', 'active' => false, 'sellable' => true, 'api_actions' => true, 'services' => ['ucp.monitoring']],
            ['code' => 'drp', 'role' => 'customer', 'active' => true, 'sellable' => true, 'api_actions' => false, 'services' => ['drp.reports']],
            ['code' => 'lsp', 'role' => 'customer', 'active' => true, 'sellable' => true, 'api_actions' => true, 'services' => ['lsp.license']],
            ['code' => 'wfph', 'role' => 'secondary', 'active' => true, 'sellable' => false, 'api_actions' => true, 'services' => []],
        ],
        'GET plans' => [
            ['id' => 12, 'label' => 'WFP Base', 'sells_license' => false, 'services' => [['code' => 'wfp.storage']]],
            ['id' => 20, 'label' => 'Uptime 50', 'sells_license' => false, 'services' => [['code' => 'ucp.monitoring']]],
            ['id' => 30, 'label' => 'DMARC 10', 'sells_license' => false, 'services' => [['code' => 'drp.reports']]],
        ],
    ]);
    $module = new EsapanelModule;

    expect($module->sellableModules($server))->toBe(['wfp']);
    expect($module->listPackages($server))->toBe([['id' => '12', 'name' => 'WFP · WFP Base']]);
});

it('sends the customer to Esapanel\'s sign-in page while the service runs, and never sets their password', function () {
    $module = new EsapanelModule;

    $running = epService(['subscription_id' => 310]);
    expect($module->ssoLogin($running))->toMatchArray(['success' => true, 'data' => ['url' => 'https://wfp.test:9943/']]);

    $ended = epService(['subscription_id' => 311, 'ended' => true]);
    expect($module->ssoLogin($ended)['success'])->toBeFalse();

    expect($module->changePassword($running, 'whatever-1')['success'])->toBeFalse();
});

it('shows the customer their plan, username and the way into Esapanel, and staff the subscription', function () {
    $service = epService([
        'subscription_id' => 310, 'customer_id' => 57, 'plan_id' => 12, 'plan_label' => 'EsaBackup 500 GB',
        'module' => 'wfp', 'subscription_status' => 'active', 'owner_username' => 'c-1a2b3c-5',
        'addons' => [['id' => 3, 'label' => 'Extra 500 GB']],
    ]);
    app()->setLocale('it');
    $module = new EsapanelModule;

    $client = (string) $module->clientAreaPanel($service)->render();
    expect($client)->toContain('EsaBackup 500 GB')->toContain('c-1a2b3c-5')->toContain('Extra 500 GB')
        ->toContain(route('client.services.login', $service))->toContain('https://wfp.test:9943/');

    $admin = (string) $module->adminAreaPanel($service)->render();
    expect($admin)->toContain('EsaBackup (WFP)')->toContain('310')->toContain('Extra 500 GB #3');

    $ended = epService(['subscription_id' => 311, 'ended' => true, 'module' => 'wfp']);
    expect((string) $module->clientAreaPanel($ended)->render())->not->toContain(route('client.services.login', $ended));
});

/** @return array<string, mixed> a WFP space as Esapanel answers it */
function epStorage(array $over = []): array
{
    return array_merge([
        'subscription_id' => 310, 'code' => 'w7k2', 'status' => 'active', 'read_only' => false,
        'space_mb' => 512000, 'used_mb' => 128000, 'measured_at' => '2026-11-25T09:00:00Z',
        'accounts' => 1, 'accounts_limit' => 10, 'shared_folders' => 0, 'shared_folders_limit' => 2,
        'server' => ['host' => 'wfp.test', 'ftps_port' => 21, 'passive_ports' => '40000-40100', 'sftp_port' => 2222, 'rsync_port' => 2223, 'webdav_port' => 8443],
    ], $over);
}

/** @return array<string, mixed> a WFP account as Esapanel answers it */
function epAccount(array $over = []): array
{
    return array_merge([
        'id' => 801, 'subscription_id' => 310, 'name' => 'nas', 'username' => 'w7k2-nas', 'description' => 'Synology',
        'kind' => 'device', 'program' => null, 'protocols' => ['rsync'], 'public_keys' => [], 'status' => 'active',
        'last_error' => null, 'path' => '/', 'last_access_at' => null, 'last_upload_at' => '2026-11-25T02:00:00Z',
        'upload_alert_minutes' => 1500, 'small_upload_alert' => true, 'late' => false,
    ], $over);
}

function epWfpService(): Service
{
    return epService(['subscription_id' => 310, 'module' => 'wfp', 'subscription_status' => 'active', 'plan_label' => 'EsaBackup 500 GB']);
}

function epCustomer(Service $service): User
{
    $user = User::factory()->create();
    $user->clients()->attach($service->client_id, ['owner' => true, 'permissions' => json_encode([])]);

    return $user;
}

it('reads the space and the accounts of a WFP subscription with the subscription', function () {
    $service = epWfpService();
    epServer([
        'GET subscriptions/310' => epSubscription(),
        'GET subscriptions/310/wfp/storage' => epStorage(),
        'GET subscriptions/310/wfp/accounts' => [epAccount(['late' => true])],
    ]);

    expect((new EsapanelModule)->refreshSubscription($service)['success'])->toBeTrue();

    $wfp = $service->fresh()->module_data['wfp'];
    expect($wfp)->toMatchArray(['space_mb' => 512000, 'used_mb' => 128000, 'read_only' => false])
        ->and($wfp['server'])->toMatchArray(['host' => 'wfp.test', 'rsync_port' => 2223])
        ->and($wfp['accounts'][0])->toMatchArray(['id' => 801, 'username' => 'w7k2-nas', 'late' => true]);
});

it('keeps the space it read before when the server cannot tell it now', function () {
    $service = epService(['subscription_id' => 310, 'module' => 'wfp', 'wfp' => ['used_mb' => 5, 'accounts' => []]]);
    epServer(['GET subscriptions/310' => epSubscription(), 'GET subscriptions/310/wfp/storage' => [409, ['message' => 'WFP is not installed on this server.']]]);

    expect((new EsapanelModule)->refreshSubscription($service)['success'])->toBeTrue();

    expect($service->fresh()->module_data)->toMatchArray(['wfp' => ['used_mb' => 5, 'accounts' => []], 'wfp_error' => 'WFP is not installed on this server.']);
});

it('lets the customer make an account and shows its password once', function () {
    $service = epWfpService();
    $calls = epServer([
        'POST subscriptions/310/wfp/accounts' => [201, ['data' => epAccount(['name' => 'ufficio', 'username' => 'w7k2-ufficio', 'password' => 'S3cret-once'])]],
        'GET subscriptions/310' => epSubscription(),
        'GET subscriptions/310/wfp/storage' => epStorage(),
        'GET subscriptions/310/wfp/accounts' => [epAccount(), epAccount(['id' => 802, 'name' => 'ufficio', 'username' => 'w7k2-ufficio'])],
    ]);

    $this->actingAs(epCustomer($service))
        ->post(route('client.services.esapanel.wfp.accounts.store', $service), ['name' => 'Ufficio', 'kind' => 'person', 'protocols' => ['sftp', 'webdav']])
        ->assertRedirect()
        ->assertSessionHas('esapanel_password', ['username' => 'w7k2-ufficio', 'password' => 'S3cret-once']);

    expect($calls[0]['body'])->toBe(['name' => 'ufficio', 'kind' => 'person', 'protocols' => ['sftp', 'webdav']]);
    expect(json_encode($service->fresh()->module_data))->not->toContain('S3cret-once');
});

it('refuses an account name Esapanel would refuse, without asking it', function () {
    $service = epWfpService();
    $calls = epServer([]);

    $this->actingAs(epCustomer($service))
        ->post(route('client.services.esapanel.wfp.accounts.store', $service), ['name' => '-nas_1', 'kind' => 'device', 'protocols' => ['rsync']])
        ->assertSessionHasErrors('name');

    expect($calls)->toHaveCount(0);
});

it('acts only on the accounts of the customer\'s own subscription', function () {
    $service = epWfpService();
    $calls = epServer(['GET wfp/accounts/900' => epAccount(['id' => 900, 'subscription_id' => 999])]);
    $customer = epCustomer($service);

    $this->actingAs($customer)
        ->post(route('client.services.esapanel.wfp.accounts.act', [$service, 900, 'password']))
        ->assertSessionHas('error', __('esapanel::messages.wfp.no_account'));
    expect(epPaths($calls))->toBe(['GET wfp/accounts/900']);

    $other = epWfpService();
    $this->actingAs($customer)
        ->post(route('client.services.esapanel.wfp.accounts.act', [$other, 801, 'suspend']))
        ->assertForbidden();
    expect($calls)->toHaveCount(1);
});

it('suspends an account of the subscription and gives a new password once', function () {
    $service = epWfpService();
    $calls = epServer([
        'GET wfp/accounts/801' => epAccount(),
        'POST wfp/accounts/801/suspend' => epAccount(['status' => 'suspended']),
        'POST wfp/accounts/801/password' => epAccount(['password' => 'N3w-once']),
        'GET subscriptions/310' => epSubscription(),
        'GET subscriptions/310/wfp/storage' => epStorage(),
        'GET subscriptions/310/wfp/accounts' => [epAccount()],
    ]);
    $customer = epCustomer($service);

    $this->actingAs($customer)->post(route('client.services.esapanel.wfp.accounts.act', [$service, 801, 'suspend']))
        ->assertSessionHas('success', __('esapanel::messages.wfp.done.suspend'));
    $this->actingAs($customer)->post(route('client.services.esapanel.wfp.accounts.act', [$service, 801, 'password']))
        ->assertSessionHas('esapanel_password', ['username' => 'w7k2-nas', 'password' => 'N3w-once']);

    expect(epPaths($calls))->toContain('POST wfp/accounts/801/suspend', 'POST wfp/accounts/801/password');
});

it('shows the customer the space, the connection data and the accounts of WFP', function () {
    $service = epService([
        'subscription_id' => 310, 'module' => 'wfp', 'subscription_status' => 'active', 'plan_label' => 'EsaBackup 500 GB',
        'wfp' => ['read_only' => true, 'space_mb' => 512000, 'used_mb' => 512000, 'accounts_limit' => 10,
            'server' => ['host' => 'wfp.test', 'rsync_port' => 2223, 'sftp_port' => 2222],
            'accounts' => [['id' => 801, 'username' => 'w7k2-nas', 'kind' => 'device', 'protocols' => ['rsync'], 'status' => 'active', 'late' => true, 'last_upload_at' => '2026-11-20T02:00:00Z']]],
    ]);
    app()->setLocale('it');

    $html = (string) (new EsapanelModule)->clientAreaPanel($service)->render();

    expect($html)->toContain('w7k2-nas')->toContain('porta 2223')->toContain(e(__('esapanel::messages.wfp.read_only')))
        ->toContain(e(__('esapanel::messages.wfp.late')))
        ->toContain(route('client.services.esapanel.wfp.accounts.store', $service))
        ->toContain(route('client.services.esapanel.wfp.accounts.act', [$service, 801, 'password']));

    $this->actingAs(epCustomer($service))->get(route('client.services.show', $service))->assertOk()->assertSee('w7k2-nas');
});

it('sells a reseller plan to a reseller account, made at that tier with its own ref and username', function () {
    $service = epService();
    $resellerSubscription = epSubscription(['service_plan' => ['id' => 12, 'label' => 'WFP Reseller', 'type' => 'reseller']]);
    $calls = epServer([
        'GET subscriptions' => [],
        'GET plans/12' => epPlan(['label' => 'WFP Reseller', 'type' => 'reseller']),
        'GET resellers' => [],
        'GET customers' => [],
        'POST resellers' => ['id' => 66],
        'POST subscriptions' => $resellerSubscription,
        'GET subscriptions/310' => $resellerSubscription,
        'GET subscriptions/310/limits' => [
            ['code' => 'wfp.storage.disk_space', 'value' => '2097152', 'is_unlimited' => false, 'unit' => 'MB', 'current_usage' => 1024],
            ['code' => 'core.customers', 'value' => null, 'is_unlimited' => true, 'unit' => null, 'current_usage' => 3],
        ],
    ]);

    expect((new EsapanelModule)->create($service)['success'])->toBeTrue();

    $made = collect($calls)->first(fn ($call) => $call['method'] === 'POST' && $call['path'] === 'resellers');
    expect($made['body'])->toMatchArray([
        'owner_username' => 'r-'.substr(hash('sha256', epSite()), 0, 6).'-'.$service->client_id,
        'external_ref' => 'pnlcs:'.epSite().':reseller:'.$service->client_id, 'password_mode' => 'link',
    ]);
    expect(collect($calls)->first(fn ($call) => $call['method'] === 'POST' && $call['path'] === 'subscriptions')['body'])->toMatchArray(['customer_id' => 66]);
    expect(epPaths($calls))->not->toContain('POST customers')->not->toContain('GET subscriptions/310/wfp/storage');

    $data = $service->fresh()->module_data;
    expect($data)->toMatchArray(['plan_type' => 'reseller', 'customer_id' => 57])
        ->and($data['limits'][0])->toMatchArray(['code' => 'wfp.storage.disk_space', 'usage' => 1024]);

    app()->setLocale('it');
    expect((string) (new EsapanelModule)->clientAreaPanel($service->fresh())->render())
        ->toContain(e(__('esapanel::messages.reseller.lead')))->toContain('2.048,0 GB')->toContain('illimitato')
        ->not->toContain(route('client.services.esapanel.wfp.accounts.store', $service));
});

it('does not make a reseller beside the customer the client already is, and says why', function () {
    $service = epService();
    $calls = epServer([
        'GET subscriptions' => [],
        'GET plans/12' => epPlan(['type' => 'reseller']),
        'GET resellers' => [],
        'GET customers' => [['id' => 91]],
    ]);

    $result = (new EsapanelModule)->create($service);

    expect($result['success'])->toBeFalse()->and($result['message'])->toContain('#91');
    expect(epPaths($calls))->not->toContain('POST resellers')->not->toContain('POST subscriptions');
});

it('offers the reseller plans beside the customer plans when the key may sell them', function () {
    $server = epService()->server;
    Http::preventStrayRequests();
    Http::fake([EP_API.'*' => function (Request $request) {
        $type = $request->data()['type'] ?? null;

        return match (true) {
            str_contains($request->url(), 'server/modules') => Http::response([], 404),
            $type === 'client' => Http::response(['data' => [epPlan()]]),
            $type === 'reseller' => Http::response(['data' => [epPlan(['id' => 9, 'label' => 'WFP Reseller', 'type' => 'reseller'])]]),
            default => Http::response(['message' => 'Not found'], 404),
        };
    }]);

    expect((new EsapanelModule)->listPackages($server))->toBe([
        ['id' => '12', 'name' => 'WFP · WFP Base'],
        ['id' => '9', 'name' => 'WFP · Reseller · WFP Reseller'],
    ]);
});
