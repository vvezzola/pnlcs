<?php

use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
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
        'GET lsp/licenses', 'GET customers', 'GET customers', 'GET customers', 'POST customers', 'POST lsp/licenses',
    ]);

    $customer = $calls[4];
    expect($customer['body'])->toMatchArray([
        'name' => 'Rossi Srl', 'vat_number' => 'IT01234567890', 'owner_username' => 'pnlcs-'.$service->client_id,
        'password_mode' => 'password', 'external_ref' => 'pnlcs:client:'.$service->client_id,
    ]);
    expect($customer['key'])->not->toBeNull();
    expect($customer['body']['password'])->toBe($customer['body']['password_confirmation']);

    $license = $calls[5];
    expect($license['body'])->toMatchArray([
        'customer_id' => 57, 'service_plan_id' => 5, 'external_ref' => 'pnlcs:service:'.$service->id,
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

it('uses the customer the License Server already has', function () {
    $service = elService();
    $calls = elServer([
        'GET lsp/licenses' => [],
        'GET customers' => [['id' => 57]],
        'POST lsp/licenses' => elLicense(),
    ]);

    (new EsapanelLicenseModule)->create($service);

    expect(elPaths($calls))->toBe(['GET lsp/licenses', 'GET customers', 'POST lsp/licenses']);
    expect($calls[1]['query'])->toMatchArray(['external_ref' => 'pnlcs:client:'.$service->client_id]);
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
