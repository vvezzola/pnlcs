<?php

use App\Models\Client;
use App\Models\ConfigOption;
use App\Models\ConfigOptionGroup;
use App\Models\ConfigOptionSub;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceConfigOption;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Servers\EsapanelLicense\EsapanelLicenseModule;

/*
 * Esapanel licences sold through the Esapanel License Server
 * (modules/Servers/EsapanelLicense, Esalogica only). The License Server is
 * faked at the HTTP level with the shapes its API v1 answers with.
 */

const EL_API = 'https://lsp.test:9943/api/v1/';

/** @return array<string, mixed> a licence as the License Server answers it */
function elLicense(array $over = []): array
{
    return array_merge([
        'id' => 42, 'code' => 'ESAP-K7QM-2XRT-9HWA-PL4D', 'subscription_id' => 118, 'customer_id' => 57,
        'external_ref' => null, 'status' => 'active', 'modules' => ['csp'], 'tier' => 'company', 'trial' => false,
        'valid_from' => '2026-11-16', 'valid_until' => '2027-11-16', 'expired' => false,
        'configuration_error' => null, 'installation' => null, 'pending_transfer' => null,
    ], $over);
}

/**
 * A fake License Server: answers by "METHOD path", and remembers every call.
 *
 * @param  array<string, mixed>  $answers  "METHOD path" => data, or [status, body]
 * @return ArrayObject<int, array{method: string, path: string, body: array<string, mixed>, key: ?string}>
 */
function elServer(array $answers): ArrayObject
{
    $calls = new ArrayObject;
    Http::preventStrayRequests();
    Http::fake([EL_API.'*' => function (Request $request) use ($answers, $calls) {
        $path = explode('?', substr($request->url(), strlen(EL_API)))[0];
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

function elService(array $moduleData = [], array $over = []): Service
{
    $server = Server::factory()->create([
        'type' => EsapanelLicenseModule::NAME, 'hostname' => 'lsp.test', 'ip_address' => '', 'port' => null,
        'access_hash' => 'key|with|pipes', 'active' => true, 'disabled' => false,
    ]);
    $product = Product::factory()->create([
        'server_type' => EsapanelLicenseModule::NAME, 'config_options' => ['package_name' => '5'],
    ]);
    $client = Client::factory()->create([
        'first_name' => 'Mario', 'last_name' => 'Rossi', 'company_name' => 'Rossi Srl',
        'email' => 'mario@rossi.test', 'tax_id' => 'IT01234567890', 'country' => 'IT',
    ]);

    return Service::factory()->create(array_merge([
        'client_id' => $client->id, 'product_id' => $product->id, 'server_id' => $server->id,
        'status' => 'active', 'billing_cycle' => 'annually', 'next_due_date' => now()->addYear()->toDateString(),
        'module_data' => $moduleData ?: null,
    ], $over));
}

/** The site's mark in the refs: its host. */
function elSite(): string
{
    return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'pnlcs';
}

/** @return list<string> "METHOD path" of each call, in order */
function elPaths(ArrayObject $calls): array
{
    return array_map(fn (array $call) => $call['method'].' '.$call['path'], $calls->getArrayCopy());
}

it('creates the customer and the licence for a new service', function () {
    $service = elService();
    $calls = elServer([
        'GET lsp/licenses' => [],
        'GET customers' => [],
        'POST customers' => ['id' => 57],
        'POST lsp/licenses' => elLicense(),
    ]);

    $result = (new EsapanelLicenseModule)->create($service);

    expect($result['success'])->toBeTrue();
    expect(elPaths($calls))->toBe([
        'GET lsp/licenses', 'GET lsp/licenses',
        'GET customers', 'GET customers', 'GET customers', 'GET customers', 'POST customers', 'POST lsp/licenses',
    ]);

    $customer = $calls[6];
    expect($customer['body'])->toMatchArray([
        'name' => 'Rossi Srl', 'vat_number' => 'IT01234567890',
        'owner_username' => 'pnlcs-'.substr(hash('sha256', elSite()), 0, 6).'-'.$service->client_id,
        'password_mode' => 'password', 'external_ref' => 'pnlcs:'.elSite().':client:'.$service->client_id,
    ]);
    expect($customer['key'])->not->toBeNull();
    expect($customer['body']['password'])->toBe($customer['body']['password_confirmation']);

    $license = $calls[7];
    expect($license['body'])->toMatchArray([
        'customer_id' => 57, 'service_plan_id' => 5, 'external_ref' => 'pnlcs:'.elSite().':service:'.$service->id,
        'valid_until' => $service->next_due_date->toDateString(),
    ]);
    expect($license['key'])->toContain('svc-'.$service->id.'-create');

    expect($service->fresh()->module_data)->toMatchArray([
        'subscription_id' => 118, 'code' => 'ESAP-K7QM-2XRT-9HWA-PL4D', 'license_status' => 'active', 'ended' => false,
    ]);
});

it('sends the same customer request again on a retry, so the Idempotency-Key still fits', function () {
    $service = elService();
    $answers = ['GET lsp/licenses' => [], 'GET customers' => [], 'POST customers' => [500, ['message' => 'Server Error']]];

    $first = elServer($answers);
    (new EsapanelLicenseModule)->create($service);
    (new EsapanelLicenseModule)->create($service->fresh());

    $posts = array_values(array_filter($first->getArrayCopy(), fn (array $call) => $call['method'] === 'POST'));
    expect($posts)->toHaveCount(2);
    expect($posts[1]['body'])->toBe($posts[0]['body']);
    expect($posts[1]['key'])->toBe($posts[0]['key']);
});

it('sends a corrected customer request under a new Idempotency-Key', function () {
    $service = elService();
    $calls = elServer(['GET lsp/licenses' => [], 'GET customers' => [], 'POST customers' => [422, ['errors' => ['name' => ['Too long.']]]]]);

    (new EsapanelLicenseModule)->create($service);
    $service->client->update(['company_name' => 'Rossi']);
    (new EsapanelLicenseModule)->create($service->fresh());

    $posts = array_values(array_filter($calls->getArrayCopy(), fn (array $call) => $call['method'] === 'POST'));
    expect($posts)->toHaveCount(2);
    expect($posts[1]['key'])->not->toBe($posts[0]['key']);
});

it('uses the customer the License Server already has', function () {
    $service = elService();
    $calls = elServer([
        'GET lsp/licenses' => [],
        'GET customers' => [['id' => 57]],
        'POST lsp/licenses' => elLicense(),
    ]);

    (new EsapanelLicenseModule)->create($service);

    expect(elPaths($calls))->toBe(['GET lsp/licenses', 'GET lsp/licenses', 'GET customers', 'POST lsp/licenses']);
    expect($calls[0]['query'])->toMatchArray(['external_ref' => 'pnlcs:'.elSite().':service:'.$service->id]);
    expect($calls[1]['query'])->toMatchArray(['external_ref' => 'pnlcs:service:'.$service->id]);
    expect($calls[2]['query'])->toMatchArray(['external_ref' => 'pnlcs:'.elSite().':client:'.$service->client_id]);
});

it('finds a licence written before the refs named the site', function () {
    $service = elService();
    $calls = new ArrayObject;
    Http::preventStrayRequests();
    Http::fake([EL_API.'*' => function (Request $request) use ($calls, $service) {
        $calls->append($request->url());
        $legacy = str_contains(urldecode($request->url()), 'external_ref=pnlcs:service:'.$service->id);

        return Http::response(['data' => match (true) {
            str_contains($request->url(), 'lsp/licenses') && $legacy => [elLicense()],
            str_contains($request->url(), 'lsp/licenses') => [],
            default => elLicense(),
        }]);
    }]);

    expect((new EsapanelLicenseModule)->create($service)['success'])->toBeTrue()
        ->and($service->fresh()->module_data['subscription_id'])->toBe(118);
    expect(collect($calls)->filter(fn ($url) => str_contains($url, 'customers'))->count())->toBe(0);
});

it('gives the owner a technical address of this site and keeps the real one on the customer', function () {
    $service = elService();
    $calls = elServer([
        'GET lsp/licenses' => [],
        'GET customers' => [],
        'POST customers' => ['id' => 58],
        'POST lsp/licenses' => elLicense(),
    ]);

    expect((new EsapanelLicenseModule)->create($service)['success'])->toBeTrue();

    $customer = collect($calls)->first(fn ($call) => $call['method'] === 'POST' && $call['path'] === 'customers');
    expect($customer['body']['owner_email'])->toBe('lsp-'.substr(hash('sha256', elSite()), 0, 6).'-'.$service->client_id.'@'.elSite())
        ->and($customer['body']['email'])->toBe('mario@rossi.test');
});

it('takes back a licence the service already has instead of creating a second one', function () {
    $service = elService();
    $calls = elServer([
        'GET lsp/licenses' => [elLicense(['status' => 'revoked'])],
        'PUT subscriptions/118/status' => elLicense(),
        'PUT subscriptions/118/plan' => elLicense(),
        'PUT subscriptions/118/license' => elLicense(),
        'GET subscriptions/118/license' => elLicense(),
    ]);

    $result = (new EsapanelLicenseModule)->create($service);

    expect($result['success'])->toBeTrue();
    expect(elPaths($calls))->toBe([
        'GET lsp/licenses', 'PUT subscriptions/118/status', 'PUT subscriptions/118/plan',
        'PUT subscriptions/118/license', 'GET subscriptions/118/license',
    ]);
    expect($calls[1]['body'])->toBe(['status' => 'active']);
    expect($calls[2]['body'])->toBe(['service_plan_id' => 5]);
    expect($service->fresh()->module_data['subscription_id'])->toBe(118);
});

it('sends suspend, unsuspend and terminate as the subscription status', function (string $action, string $status) {
    $service = elService(['subscription_id' => 118]);
    $calls = elServer([
        'PUT subscriptions/118/status' => elLicense(),
        'GET subscriptions/118/license' => elLicense(['status' => $status === 'disabled' ? 'revoked' : $status]),
    ]);

    $result = (new EsapanelLicenseModule)->{$action}($service);

    expect($result['success'])->toBeTrue();
    expect($calls[0]['body'])->toBe(['status' => $status]);
    expect(! empty($service->fresh()->module_data['ended']))->toBe($action === 'terminate');
})->with([
    'suspend' => ['suspend', 'suspended'],
    'unsuspend' => ['unsuspend', 'active'],
    'terminate' => ['terminate', 'disabled'],
]);

it('moves the last day to the next due date on renewal', function () {
    $service = elService(['subscription_id' => 118]);
    $due = $service->next_due_date->toDateString();
    $calls = elServer(['PUT subscriptions/118/license' => elLicense(['valid_until' => $due])]);

    $result = (new EsapanelLicenseModule)->renew($service);

    expect($result['success'])->toBeTrue();
    expect($calls[0]['body'])->toBe(['valid_until' => $due]);
    expect($service->fresh()->module_data['valid_until'])->toBe($due);
});

it('renews nothing for a service without a licence', function () {
    $service = elService();
    $calls = elServer([]);

    expect((new EsapanelLicenseModule)->renew($service)['success'])->toBeTrue();
    expect($calls)->toHaveCount(0);
});

it('changes the plan and then sends the last day again', function () {
    $service = elService(['subscription_id' => 118]);
    $calls = elServer([
        'PUT subscriptions/118/plan' => elLicense(),
        'PUT subscriptions/118/license' => elLicense(),
        'GET subscriptions/118/license' => elLicense(['tier' => 'integrator']),
    ]);

    $result = (new EsapanelLicenseModule)->changePackage($service, ['config_options' => ['package_name' => '6']]);

    expect($result['success'])->toBeTrue();
    expect($calls[0]['body'])->toBe(['service_plan_id' => 6]);
    expect($calls[1]['body'])->toBe(['valid_until' => $service->next_due_date->toDateString()]);
    expect($service->fresh()->module_data['tier'])->toBe('integrator');
});

it('frees the licence from its installation', function () {
    $service = elService(['subscription_id' => 118, 'installation' => ['hostname' => 'old.customer.test']]);
    $calls = elServer([
        'POST subscriptions/118/license/reset' => elLicense(),
        'GET subscriptions/118/license' => elLicense(),
    ]);

    $result = (new EsapanelLicenseModule)->unbindServer($service);

    expect($result['success'])->toBeTrue();
    expect(elPaths($calls))->toBe(['POST subscriptions/118/license/reset', 'GET subscriptions/118/license']);
    expect($service->fresh()->module_data['installation'])->toBeNull();
});

it('offers only the plans that sell a licence, and marks the Trial', function () {
    $service = elService();
    $limit = fn (string $trial) => [['code' => 'lsp.license.trial', 'value' => $trial, 'is_unlimited' => false]];
    elServer(['GET plans' => [
        ['id' => 3, 'label' => 'CSP Small', 'services' => [['code' => 'csp.hosting', 'limits' => []]]],
        ['id' => 5, 'label' => 'LSP CSP Company', 'services' => [['code' => 'lsp.license', 'limits' => $limit('false')]]],
        ['id' => 8, 'label' => 'LSP CSP Trial', 'services' => [['code' => 'lsp.license', 'limits' => $limit('true')]]],
    ]]);

    expect((new EsapanelLicenseModule)->listPackages($service->server))->toBe([
        ['id' => '5', 'name' => 'LSP CSP Company'],
        ['id' => '8', 'name' => 'LSP CSP Trial (Trial)'],
    ]);
});

it('reports the License Server\'s validation words and remembers them', function () {
    $service = elService();
    elServer([
        'GET lsp/licenses' => [],
        'GET customers' => [['id' => 57]],
        'POST lsp/licenses' => [422, ['message' => 'Invalid', 'errors' => ['service_plan_id' => ['This plan sells no licence.']]]],
    ]);

    $result = (new EsapanelLicenseModule)->create($service);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('This plan sells no licence.');
    expect($service->fresh()->module_data['last_error'])->toContain('This plan sells no licence.');
    expect($service->fresh()->module_data)->not->toHaveKey('subscription_id');
});

it('refuses to create without a License Server key, and calls nobody', function () {
    $service = elService();
    $service->server->update(['access_hash' => '']);
    $calls = elServer([]);

    $result = (new EsapanelLicenseModule)->create($service->fresh());

    expect($result['success'])->toBeFalse();
    expect($calls)->toHaveCount(0);
});

it('never puts the API key in an error message', function () {
    $service = elService();
    elServer(['GET lsp/licenses' => [401, ['message' => 'Unauthenticated.']]]);

    $result = (new EsapanelLicenseModule)->create($service);

    expect($result['success'])->toBeFalse();
    expect($result['message'])->not->toContain('key|with|pipes');
    expect((string) json_encode($service->fresh()->module_data))->not->toContain('key|with|pipes');
});

/*
 * The 2027 price list: the product sells a level, its "addon|..." options
 * sell the modules, each naming a License Server add-on.
 */

/**
 * Give the service's product module options, and choose some of them.
 *
 * @param  array<string, list<string>>  $options  option name => sub-option names
 * @param  list<string>  $chosen  the sub-option names the customer took
 */
function elAddonOptions(Service $service, array $options, array $chosen): void
{
    $group = ConfigOptionGroup::create(['name' => 'Esapanel modules']);
    $service->product->configOptionGroups()->attach($group->id);

    foreach ($options as $name => $subs) {
        $option = ConfigOption::create(['group_id' => $group->id, 'option_name' => $name, 'option_type' => count($subs) > 1 ? 'dropdown' : 'checkbox']);
        foreach ($subs as $i => $subName) {
            $sub = ConfigOptionSub::create(['config_id' => $option->id, 'option_name' => $subName, 'sort_order' => $i]);
            if (in_array($subName, $chosen, true)) {
                ServiceConfigOption::create(['service_id' => $service->id, 'config_id' => $option->id, 'option_id' => $sub->id, 'qty' => 1, 'unit_price' => 0]);
            }
        }
    }
}

/** The client add-ons the License Server lists. */
function elAddonPlans(): array
{
    return [
        ['id' => 31, 'label' => 'LSP CSP'],
        ['id' => 34, 'label' => 'LSP UCP'],
        ['id' => 40, 'label' => 'LSP CSP Company 25'],
        ['id' => 41, 'label' => 'LSP CSP Company 100'],
    ];
}

it('creates the licence with the add-ons of the modules chosen, found by name', function () {
    $service = elService();
    elAddonOptions($service, [
        'addon|Central Syslog' => ['LSP CSP|CSP'],
        'addon|Uptime Check' => ['none|No', '34|UCP'],
        'addon|DMARC Report' => ['none|No', 'LSP DRP|DRP'],
    ], ['LSP CSP|CSP', '34|UCP', 'none|No']);
    $calls = elServer([
        'GET lsp/licenses' => [],
        'GET plans' => elAddonPlans(),
        'GET customers' => [['id' => 57]],
        'POST lsp/licenses' => elLicense(['modules' => ['csp', 'ucp']]),
    ]);

    expect((new EsapanelLicenseModule)->create($service)['success'])->toBeTrue();

    $post = collect($calls)->first(fn ($call) => $call['method'] === 'POST' && $call['path'] === 'lsp/licenses');
    expect($post['body']['addon_ids'])->toBe([31, 34])
        ->and(collect($calls)->firstWhere('path', 'plans')['query'])->toMatchArray(['type' => 'client_addon']);
});

it('creates nothing when a chosen add-on is unknown to the License Server', function () {
    $service = elService();
    elAddonOptions($service, ['addon|Web File' => ['LSP WFP|WFP']], ['LSP WFP|WFP']);
    $calls = elServer(['GET lsp/licenses' => [], 'GET plans' => elAddonPlans()]);

    $result = (new EsapanelLicenseModule)->create($service);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('LSP WFP')
        ->and(collect($calls)->where('method', 'POST')->count())->toBe(0);
});

it('brings the licence\'s add-ons to the options: the new size first, then the old one away', function () {
    $service = elService(['subscription_id' => 118]);
    elAddonOptions($service, [
        'addon|Central Syslog' => ['LSP CSP Company 25|25 device', 'LSP CSP Company 100|100 device'],
    ], ['LSP CSP Company 100|100 device']);
    $calls = elServer([
        'PUT subscriptions/118/plan' => elLicense(),
        'GET plans' => elAddonPlans(),
        'GET subscriptions/118' => ['id' => 118, 'addons' => [['id' => 900, 'service_plan_id' => 40, 'label' => 'LSP CSP Company 25']]],
        'POST subscriptions/118/addons' => ['id' => 118],
        'DELETE subscriptions/118/addons/900' => ['id' => 118],
        'PUT subscriptions/118/license' => elLicense(),
        'GET subscriptions/118/license' => elLicense(),
    ]);

    expect((new EsapanelLicenseModule)->changePackage($service, ['config_options' => ['package_name' => '5']])['success'])->toBeTrue();

    expect(elPaths($calls))->toBe([
        'PUT subscriptions/118/plan', 'GET plans', 'GET subscriptions/118',
        'POST subscriptions/118/addons', 'DELETE subscriptions/118/addons/900',
        'PUT subscriptions/118/license', 'GET subscriptions/118/license',
    ]);
    expect($calls[3]['body'])->toBe(['service_plan_id' => 41]);
});

it('reports a refused add-on change and keeps the licence\'s last day untouched', function () {
    $service = elService(['subscription_id' => 118]);
    elAddonOptions($service, ['addon|Central Syslog' => ['LSP CSP|CSP']], []);
    $calls = elServer([
        'PUT subscriptions/118/plan' => elLicense(),
        'GET subscriptions/118' => ['id' => 118, 'addons' => [['id' => 900, 'service_plan_id' => 31, 'label' => 'LSP CSP']]],
        'DELETE subscriptions/118/addons/900' => [422, ['message' => 'The usage is above what is left.']],
    ]);

    $result = (new EsapanelLicenseModule)->changePackage($service, ['config_options' => ['package_name' => '5']]);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('usage is above')
        ->and(collect($calls)->where('path', 'subscriptions/118/license')->count())->toBe(0);
});

it('creates a Trial with the add-ons its product always sells', function () {
    $service = elService();
    $service->product->update(['config_options' => ['package_name' => '75', 'addons' => ['LSP CSP', 'LSP UCP']]]);
    $calls = elServer([
        'GET lsp/licenses' => [],
        'GET plans' => elAddonPlans(),
        'GET customers' => [['id' => 57]],
        'POST lsp/licenses' => elLicense(['trial' => true, 'modules' => ['csp', 'ucp']]),
    ]);

    expect((new EsapanelLicenseModule)->create($service->fresh())['success'])->toBeTrue();

    $post = collect($calls)->first(fn ($call) => $call['method'] === 'POST' && $call['path'] === 'lsp/licenses');
    expect($post['body']['addon_ids'])->toBe([31, 34])
        ->and($post['body']['service_plan_id'])->toBe(75);
});
