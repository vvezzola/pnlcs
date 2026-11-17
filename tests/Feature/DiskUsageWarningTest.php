<?php

use App\Mail\DiskUsageWarningMail;
use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Services\DiskUsageWarningService;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Modules\Servers\Plesk\PleskModule;

/*
 * A customer who fills the disk space of their plan is told before it runs
 * out, with a link to a larger plan. Plesk subscriptions linked from a server
 * are owned by the Plesk admin, so their usage is read per subscription.
 */

function duwService(array $usage, string $email = 'owner@example.com'): Service
{
    return Service::factory()->create(array_merge([
        'client_id' => Client::factory()->create(['email' => $email, 'language' => 'en'])->id,
        'product_id' => Product::factory()->create(['group_id' => ProductGroup::factory()->create()->id])->id,
        'status' => 'active', 'domain' => 'shop.test',
    ], $usage));
}

test('a service past the threshold is warned once, and again only after it went back under it', function () {
    Mail::fake();
    Setting::set(DiskUsageWarningService::SETTING, '80');
    $service = duwService(['disk_usage' => 820, 'disk_limit' => 1000]);
    $warnings = app(DiskUsageWarningService::class);

    expect($warnings->run())->toBe(1)->and($warnings->run())->toBe(0);
    Mail::assertQueued(DiskUsageWarningMail::class, 1);

    // Still within the margin under the threshold: remembered.
    $service->update(['disk_usage' => 760]);
    expect($warnings->run())->toBe(0);

    $service->update(['disk_usage' => 500]);
    $warnings->run();
    $service->update(['disk_usage' => 900]);

    expect($warnings->run())->toBe(1);
    Mail::assertQueued(DiskUsageWarningMail::class, 2);
});

test('no warning for a service under the threshold, with no limit, not active, or with the setting off', function (array $usage, string $percent) {
    Mail::fake();
    Setting::set(DiskUsageWarningService::SETTING, $percent);
    duwService($usage);

    expect(app(DiskUsageWarningService::class)->run())->toBe(0);
    Mail::assertNothingQueued();
})->with([
    'under the threshold' => [['disk_usage' => 790, 'disk_limit' => 1000], '80'],
    'unlimited' => [['disk_usage' => 99999, 'disk_limit' => 0], '80'],
    'suspended' => [['disk_usage' => 990, 'disk_limit' => 1000, 'status' => 'suspended'], '80'],
    'setting off' => [['disk_usage' => 990, 'disk_limit' => 1000], '0'],
]);

test('the warning tells the customer the usage, the limit and where to upgrade', function () {
    $service = duwService(['disk_usage' => 4915, 'disk_limit' => 5120]);
    $sent = new ArrayObject;
    Event::listen(MessageSent::class, fn ($event) => $sent->append([$event->message->getSubject(), $event->message->getHtmlBody().$event->message->getTextBody()]));
    EmailTemplate::where('name', 'Disk Usage Warning')->update(['disabled' => false]);

    Mail::to('owner@example.com')->send(new DiskUsageWarningMail($service));

    [$subject, $body] = $sent[0];
    expect($subject)->toContain('shop.test')->toContain('95%')
        ->and($body)->toContain('4.8 GB')->toContain('5 GB')
        ->toContain(route('client.services.upgrade', $service));
});

test('the usage polling reads a linked Plesk subscription by itself, not through its customer', function () {
    $server = Server::factory()->create([
        'type' => 'plesk', 'hostname' => 'plesk.test', 'ip_address' => '', 'port' => 8443, 'access_hash' => 'sk-123',
    ]);
    $service = duwService(['server_id' => $server->id]);
    $service->forceFill(['module_data' => ['plesk_domain_id' => '42']])->save();

    Http::preventStrayRequests();
    Http::fake(['https://plesk.test:8443/enterprise/control/agent.php' => fn (Request $request) => Http::response(
        '<?xml version="1.0"?><packet><webspace><get><result><status>ok</status><id>42</id><data>'
        .'<gen_info><name>shop.test</name><real_size>3221225472</real_size></gen_info>'
        .'<limits><limit><name>disk_space</name><value>5368709120</value></limit><limit><name>max_traffic</name><value>-1</value></limit></limits>'
        .'<stat><traffic>104857600</traffic></stat></data></result></get></webspace></packet>', 200)]);

    expect((new PleskModule)->usageUpdate($server))->toBe(['updated' => 1, 'errors' => 0])
        ->and($service->refresh()->only(['disk_usage', 'disk_limit', 'bw_usage', 'bw_limit']))
        ->toBe(['disk_usage' => 3072, 'disk_limit' => 5120, 'bw_usage' => 100, 'bw_limit' => 0]);
});

test('a linked subscription the server no longer has is counted as an error', function () {
    $server = Server::factory()->create([
        'type' => 'plesk', 'hostname' => 'plesk.test', 'ip_address' => '', 'port' => 8443, 'access_hash' => 'sk-123',
    ]);
    duwService(['server_id' => $server->id])->forceFill(['module_data' => ['plesk_domain_id' => '42']])->save();

    Http::preventStrayRequests();
    Http::fake(['https://plesk.test:8443/enterprise/control/agent.php' => Http::response(
        '<?xml version="1.0"?><packet><webspace><get><result><status>ok</status></result></get></webspace></packet>', 200)]);

    expect((new PleskModule)->usageUpdate($server))->toBe(['updated' => 0, 'errors' => 1]);
});
