<?php

use App\Contracts\ChecksAvailabilityInBulk;
use App\Models\RegistrarSettings;
use Illuminate\Support\Facades\Http;
use Modules\Registrars\OpenProvider\OpenProviderRegistrar;

/*
 * The domain search asks about a name and its suggested endings together.
 * Openprovider is asked about all of them in parallel after one login, and
 * a registry it cannot reach (it answers "active" with the reason) is no
 * answer, so the name goes to WHOIS instead of showing as taken.
 */

beforeEach(function () {
    foreach (['username' => 'reseller', 'password' => 'secret', 'test_mode' => '1'] as $k => $v) {
        RegistrarSettings::updateOrCreate(['registrar' => 'openprovider', 'setting' => $k], ['value' => $v]);
    }
});

/**
 * The /domains/check answers, keyed by the extension asked. An integer is
 * an HTTP error for that name; a request asking several names fails when
 * $bulkFails, as one held up by a slow registry does.
 */
function opCheckAnswers(array $byExtension, bool $bulkFails = false): void
{
    Http::fake(function ($request) use ($byExtension, $bulkFails) {
        if (str_ends_with($request->url(), '/auth/login')) {
            return Http::response(['code' => 0, 'desc' => '', 'data' => ['token' => 't']]);
        }
        $asked = $request['domains'];
        if ($bulkFails && count($asked) > 1) {
            return Http::response(['code' => 1, 'desc' => 'timeout'], 504);
        }
        $results = [];
        foreach ($asked as $name) {
            $answer = $byExtension[$name['extension']];
            if (is_int($answer)) {
                return Http::response(['code' => 1, 'desc' => 'error'], $answer);
            }
            $results[] = ['domain' => $name['name'].'.'.$name['extension']] + $answer;
        }

        return Http::response(['code' => 0, 'desc' => '', 'data' => ['results' => $results]]);
    });
}

test('every name is asked in one request after a single login', function () {
    opCheckAnswers([
        'it' => ['status' => 'free'],
        'com' => ['status' => 'active', 'reason' => 'Domain exists'],
        'eu' => ['status' => 'active', 'reason' => 'registered'],
    ]);

    $module = new OpenProviderRegistrar;
    $answers = $module->checkAvailabilityBulk(['rossi.it', 'rossi.com', 'Rossi.EU']);

    expect($module)->toBeInstanceOf(ChecksAvailabilityInBulk::class)
        ->and($answers)->toBe([
            'rossi.it' => ['available' => true],
            'rossi.com' => ['available' => false],
            'rossi.eu' => ['available' => false],
        ]);
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/domains/check') && count($r['domains']) === 3
        && $r->hasHeader('Authorization', 'Bearer t'));
});

test('a registry Openprovider cannot reach is left for WHOIS, not shown as taken', function () {
    opCheckAnswers([
        'org' => ['status' => 'active', 'reason' => 'Registry currently not reachable; Domingo EPP is unreachable: Deadline Exceeded'],
        'com' => ['status' => 'free'],
    ]);

    $answers = (new OpenProviderRegistrar)->checkAvailabilityBulk(['rossi.org', 'rossi.com']);

    expect($answers)->toBe(['rossi.com' => ['available' => true]]);
});

test('when the one request fails, each name is asked on its own and an error is left for WHOIS', function () {
    opCheckAnswers([
        'it' => ['status' => 'free'],
        'net' => 500,
        'com' => ['status' => 'active', 'reason' => 'Domain exists'],
    ], bulkFails: true);

    $answers = (new OpenProviderRegistrar)->checkAvailabilityBulk(['rossi.it', 'rossi.net', 'rossi.com']);

    expect($answers)->toBe(['rossi.it' => ['available' => true], 'rossi.com' => ['available' => false]]);
    Http::assertSentCount(5);
});

test('a single check reports an unreachable registry as an error, not as taken', function () {
    opCheckAnswers(['org' => ['status' => 'active', 'reason' => 'Registry currently not reachable']]);

    $result = (new OpenProviderRegistrar)->checkAvailability('rossi.org');

    expect($result['available'])->toBeFalse()
        ->and($result)->toHaveKey('error');
});
