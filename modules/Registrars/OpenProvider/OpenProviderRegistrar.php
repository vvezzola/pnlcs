<?php

namespace Modules\Registrars\OpenProvider;

use App\Contracts\RegistrarModuleInterface;
use App\Contracts\SyncsDomainData;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainPricing;
use App\Models\ModuleLog;
use App\Models\RegistrarSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Openprovider, over its REST API (v1).
 *
 * Written against the official Swagger definitions published at
 * docs.openprovider.com (2026-08): bearer token from POST /v1/auth/login,
 * every domain operation under /v1/domains. Contacts are "handles" -
 * created once per customer via POST /v1/customers and referenced by
 * every registration and transfer.
 *
 * On /v1 since 2026-11: Openprovider retired /v1beta (switched off 30 June
 * 2027) and documents /v1 as identical but for the base URL.
 *
 * Responses arrive wrapped: {code: 0, desc, data}. Zero is success; anything
 * else carries the reason in desc, which is what the operator sees.
 */
class OpenProviderRegistrar implements RegistrarModuleInterface, SyncsDomainData
{
    protected string $apiUrl;

    protected string $username;

    protected string $password;

    protected ?string $token = null;

    public function __construct()
    {
        $settings = $this->loadSettings();
        $testMode = ($settings['test_mode'] ?? '1') === '1';
        // The sandbox host is published in Openprovider's own docs; it speaks
        // plain HTTP on a high port and holds an isolated account.
        $this->apiUrl = $testMode
            ? 'http://api.sandbox.openprovider.nl:8480/v1'
            : 'https://api.openprovider.eu/v1';
        $this->username = $settings['username'] ?? '';
        $this->password = $settings['password'] ?? '';
    }

    public function getModuleName(): string
    {
        return 'OpenProvider';
    }

    public function getConfigFields(): array
    {
        return [
            ['name' => 'username', 'label' => 'Openprovider Username', 'type' => 'text', 'required' => true],
            ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
            ['name' => 'test_mode', 'label' => 'Sandbox Mode', 'type' => 'yesno', 'default' => '1'],
            ['name' => 'margin_percent', 'label' => 'Margin (%)', 'type' => 'text', 'required' => false, 'default' => '0'],
            ['name' => 'round_up', 'label' => 'Round prices up to a whole unit', 'type' => 'yesno', 'default' => '0'],
            ['name' => 'daily_price_sync', 'label' => 'Sync prices every day', 'type' => 'yesno', 'default' => '0'],
        ];
    }

    /**
     * Pull the account's purchase prices from Openprovider and write them,
     * with the configured margin, onto the TLDs whose auto registrar is
     * Openprovider. The "reseller" figure is the price in the account's own
     * currency, converted into the shop's before the margin is applied.
     * Run from the registrars screen, or daily by pnlcs:sync-domain-prices.
     *
     * @return array{success: bool, updated?: int, unchanged?: int, skipped?: int, message?: string}
     */
    public function syncPrices(): array
    {
        try {
            $settings = $this->loadSettings();
            $margin = (float) str_replace(',', '.', (string) ($settings['margin_percent'] ?? '0'));
            $roundUp = ($settings['round_up'] ?? '0') === '1';

            $tlds = DomainPricing::whereNotNull('auto_registrar')->get()
                ->filter(fn (DomainPricing $tld) => strtolower(trim((string) $tld->auto_registrar)) === 'openprovider');
            if ($tlds->isEmpty()) {
                return ['success' => true, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
            }

            $catalog = $this->catalogPrices($tlds->map(fn (DomainPricing $tld) => ltrim((string) $tld->extension, '.'))->values()->all());

            $updated = 0;
            $unchanged = 0;
            $skipped = 0;
            $changes = [];
            $skippedExtensions = [];
            $factors = [];

            foreach ($tlds as $tld) {
                $prices = $catalog[ltrim(strtolower((string) $tld->extension), '.')] ?? null;

                // Only a price Openprovider quotes, above zero, in a currency
                // the shop can convert. Anything else is not a price of zero:
                // it is one the panel does not know, so the operator's stays.
                $next = [];
                foreach (['create_price' => 'register_price', 'renew_price' => 'renew_price', 'transfer_price' => 'transfer_price', 'restore_price' => 'restore_price'] as $from => $column) {
                    $quote = $prices[$from]['reseller'] ?? null;
                    $amount = (float) ($quote['price'] ?? 0);
                    $currency = strtoupper((string) ($quote['currency'] ?? ''));
                    if ($amount <= 0 || $currency === '') {
                        continue;
                    }
                    $factors[$currency] ??= Currency::rateToShop($currency);
                    if ($factors[$currency] === null) {
                        continue;
                    }
                    $next[$column] = $this->withMargin($amount * $factors[$currency], $margin, $roundUp);
                }

                // The registration periods the registry allows (.it: one
                // year), so an order cannot ask for a term it would refuse.
                if ($next !== []) {
                    foreach (['min_period' => 'min_years', 'max_period' => 'max_years'] as $from => $column) {
                        if ((int) ($prices[$from] ?? 0) > 0) {
                            $next[$column] = (int) $prices[$from];
                        }
                    }
                }

                if ($next === []) {
                    $skipped++;
                    $skippedExtensions[] = $tld->extension;

                    continue;
                }

                $current = [];
                foreach (array_keys($next) as $column) {
                    $current[$column] = (float) $tld->{$column};
                }

                if ($next == $current) {
                    $unchanged++;

                    continue;
                }

                $tld->update($next);
                $changes[] = ['extension' => $tld->extension, 'before' => $current, 'after' => $next];
                $updated++;
            }

            Log::info("Openprovider price sync: {$updated} changed, {$unchanged} unchanged, {$skipped} skipped");

            try {
                ModuleLog::create([
                    'module' => $this->getModuleName(),
                    'action' => 'sync_prices',
                    'request' => json_encode(['margin_percent' => $margin, 'round_up' => $roundUp, 'rates' => $factors], JSON_UNESCAPED_SLASHES),
                    'response' => json_encode([
                        'updated' => $updated,
                        'unchanged' => $unchanged,
                        'skipped' => $skipped,
                        'skipped_extensions' => $skippedExtensions,
                        'changes' => $changes,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Openprovider module log failed: '.$e->getMessage());
            }

            RegistrarSettings::updateOrCreate(
                ['registrar' => 'openprovider', 'setting' => 'last_price_sync'],
                ['value' => now()->toDateTimeString()],
            );

            return ['success' => true, 'updated' => $updated, 'unchanged' => $unchanged, 'skipped' => $skipped];
        } catch (\Throwable $e) {
            Log::error("Openprovider syncPrices failed: {$e->getMessage()}");

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * The prices of these extensions, keyed by extension without the dot.
     *
     * @param  list<string>  $extensions
     * @return array<string, array<string, mixed>>
     */
    protected function catalogPrices(array $extensions): array
    {
        $catalog = [];
        foreach (array_chunk($extensions, 100) as $chunk) {
            // A repeated parameter (extensions=it&extensions=com), the form the
            // API reads; PHP's own encoding would send extensions[0]=it.
            $query = 'with_price=true&limit='.count($chunk)
                .implode('', array_map(fn (string $ext) => '&extensions='.rawurlencode($ext), $chunk));
            $data = $this->call('get', '/tlds?'.$query);
            foreach ($data['results'] ?? [] as $row) {
                $name = strtolower((string) ($row['name'] ?? ''));
                if ($name !== '') {
                    $catalog[$name] = $row['prices'] ?? [];
                }
            }
        }

        return $catalog;
    }

    /** A purchase price in the shop currency, with the margin, optionally rounded up to a whole unit. */
    protected function withMargin(float $price, float $margin, bool $roundUp): float
    {
        $value = round($price * (1 + $margin / 100), 2);

        return $roundUp ? (float) ceil($value) : $value;
    }

    /**
     * Login and a one-domain list: proves the credentials, the reachability
     * and the account in a single click, the way the HRD module does.
     */
    public function testConnection(): array
    {
        try {
            $this->authToken();
            $this->call('get', '/domains', ['limit' => 1]);

            return ['success' => true, 'message' => 'Connected to Openprovider.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function register(Domain $domain, int $years, array $params = []): array
    {
        try {
            [$name, $extension] = $this->splitDomain($domain->domain);
            $handle = $this->ownerHandle($domain, $params);

            $body = [
                'domain' => ['name' => $name, 'extension' => $extension],
                'period' => max(1, $years),
                'owner_handle' => $handle,
                'admin_handle' => $handle,
                'tech_handle' => $handle,
                'billing_handle' => $handle,
                'autorenew' => 'off', // renewals are this panel's job, not the registrar's
                'name_servers' => $this->nameserverList($params['nameservers'] ?? $domain->nameservers),
            ];

            $data = $this->call('post', '/domains', $body);

            return ['success' => true, 'message' => 'Domain registered via Openprovider (id '.($data['id'] ?? '?').').'];
        } catch (\Throwable $e) {
            Log::error('OpenProvider register failed', ['domain' => $domain->domain, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function transfer(Domain $domain, string $eppCode): array
    {
        try {
            [$name, $extension] = $this->splitDomain($domain->domain);
            $handle = $this->ownerHandle($domain, []);

            $this->call('post', '/domains/transfer', [
                'domain' => ['name' => $name, 'extension' => $extension],
                'auth_code' => $eppCode,
                'owner_handle' => $handle,
                'admin_handle' => $handle,
                'tech_handle' => $handle,
                'billing_handle' => $handle,
                'autorenew' => 'off',
                // The domain keeps answering from wherever it answers now.
                'import_nameservers_from_registry' => true,
            ]);

            return ['success' => true, 'message' => 'Transfer initiated via Openprovider.'];
        } catch (\Throwable $e) {
            Log::error('OpenProvider transfer failed', ['domain' => $domain->domain, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function renew(Domain $domain, int $years): array
    {
        try {
            $info = $this->domainInfo($domain);
            [$name, $extension] = $this->splitDomain($domain->domain);

            $this->call('post', '/domains/'.$info['id'].'/renew', [
                'id' => $info['id'],
                'domain' => ['name' => $name, 'extension' => $extension],
                'period' => max(1, $years),
            ]);

            // The dates are the module's to move on success (see
            // DomainService::renewDomain), as the other registrars do.
            $newExpiry = ($domain->expiry_date ?? now())->copy()->addYears(max(1, $years));
            $domain->update(['expiry_date' => $newExpiry, 'next_due_date' => $newExpiry->copy()]);

            return ['success' => true, 'message' => "Domain renewed via Openprovider for {$years} year(s).", 'expiry_date' => $newExpiry->toDateString()];
        } catch (\Throwable $e) {
            Log::error('OpenProvider renew failed', ['domain' => $domain->domain, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getNameservers(Domain $domain): array
    {
        try {
            $info = $this->domainInfo($domain);

            return array_values(array_filter(array_map(
                fn ($ns) => $ns['name'] ?? null,
                $info['name_servers'] ?? []
            )));
        } catch (\Throwable $e) {
            return json_decode($domain->nameservers ?? '[]', true) ?: [];
        }
    }

    public function saveNameservers(Domain $domain, array $nameservers): bool
    {
        try {
            $info = $this->domainInfo($domain);
            $this->call('put', '/domains/'.$info['id'], [
                'id' => $info['id'],
                'name_servers' => $this->nameserverList($nameservers),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('OpenProvider saveNameservers failed', ['domain' => $domain->domain, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function getEPPCode(Domain $domain): string
    {
        $info = $this->domainInfo($domain);
        $data = $this->call('get', '/domains/'.$info['id'].'/authcode');

        return (string) ($data['auth_code'] ?? '');
    }

    public function getLockStatus(Domain $domain): bool
    {
        try {
            return (bool) ($this->domainInfo($domain)['is_locked'] ?? false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function toggleLock(Domain $domain, bool $lock): bool
    {
        try {
            $info = $this->domainInfo($domain);
            $this->call('put', '/domains/'.$info['id'], [
                'id' => $info['id'],
                'is_locked' => $lock,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('OpenProvider toggleLock failed', ['domain' => $domain->domain, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function checkAvailability(string $domain): array
    {
        try {
            [$name, $extension] = $this->splitDomain($domain);
            $data = $this->call('post', '/domains/check', [
                'domains' => [['name' => $name, 'extension' => $extension]],
            ]);

            // The answer per domain is a status string: "free" is the only one
            // that means it can be registered; "active" and "in use" mean taken.
            $status = strtolower((string) ($data['results'][0]['status'] ?? ''));

            return [
                'available' => $status === 'free',
                'domain' => $domain,
                'method' => 'openprovider_api',
            ];
        } catch (\Throwable $e) {
            return ['available' => false, 'domain' => $domain, 'method' => 'openprovider_api', 'error' => $e->getMessage()];
        }
    }

    public function syncDomain(Domain $domain): array
    {
        try {
            $info = $this->domainInfo($domain);

            return [
                'success' => true,
                'expiry_date' => $this->parseDate($info['expiration_date'] ?? null),
                'status' => $this->mapStatus((string) ($info['status'] ?? '')),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ── Openprovider plumbing ────────────────────────────────────────────

    protected function authToken(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $response = Http::timeout(20)->post($this->apiUrl.'/auth/login', [
            'username' => $this->username,
            'password' => $this->password,
        ]);

        $json = $response->json();
        if (! $response->successful() || ($json['code'] ?? -1) !== 0 || empty($json['data']['token'])) {
            throw new \RuntimeException('Openprovider login failed: '.($json['desc'] ?? ('HTTP '.$response->status())));
        }

        return $this->token = $json['data']['token'];
    }

    /** Every call unwraps the {code, desc, data} envelope; non-zero code throws desc. */
    protected function call(string $method, string $path, array $payload = []): array
    {
        $request = Http::timeout(30)->withToken($this->authToken());

        // A GET with no payload keeps any query already in the path: an empty
        // query option would replace it.
        $response = match (true) {
            $method === 'get' && $payload === [] => $request->get($this->apiUrl.$path),
            $method === 'get' => $request->get($this->apiUrl.$path, $payload),
            default => $request->{$method}($this->apiUrl.$path, $payload),
        };

        $json = $response->json() ?? [];

        if (! $response->successful() || ($json['code'] ?? -1) !== 0) {
            throw new \RuntimeException('Openprovider: '.($json['desc'] ?? ('HTTP '.$response->status())));
        }

        return $json['data'] ?? [];
    }

    /** The registrar's record for this domain, found by its full name. */
    protected function domainInfo(Domain $domain): array
    {
        $data = $this->call('get', '/domains', ['full_name' => $domain->domain, 'limit' => 1]);
        $row = $data['results'][0] ?? null;

        if (! $row || ! isset($row['id'])) {
            throw new \RuntimeException('Domain not found in the Openprovider account.');
        }

        return $row;
    }

    /**
     * The customer as an Openprovider handle, created on first need.
     *
     * Handles are Openprovider's contact objects; a registration without one
     * is refused. The handle is remembered on the client's own record so the
     * second domain does not create a second identity.
     */
    protected function ownerHandle(Domain $domain, array $params): string
    {
        $client = $domain->client;

        // Remembered in the registrar's own settings rows - no schema change,
        // and the config screen only renders the fields the module declares,
        // so these bookkeeping rows never surface there.
        $stored = RegistrarSettings::where('registrar', 'openprovider')
            ->where('setting', 'handle_client_'.$client->id)
            ->value('value');
        if ($stored) {
            return $stored;
        }

        [$countryCode, $subscriber] = $this->splitPhone($params['phone'] ?? $client->phone_number ?? '');

        $data = $this->call('post', '/customers', [
            'name' => [
                'first_name' => $params['firstname'] ?? $client->first_name ?? 'Admin',
                'last_name' => $params['lastname'] ?? $client->last_name ?? 'Admin',
            ],
            'company_name' => $client->company_name ?: null,
            'email' => $params['email'] ?? $client->email,
            'phone' => [
                'country_code' => $countryCode,
                'area_code' => '',
                'subscriber_number' => $subscriber,
            ],
            'address' => [
                'street' => $params['address'] ?? $client->address1 ?? 'Unknown',
                'number' => '1',
                'zipcode' => $params['postcode'] ?? $client->postcode ?? '00000',
                'city' => $params['city'] ?? $client->city ?? 'Unknown',
                'state' => $params['state'] ?? $client->state ?? '',
                'country' => strtoupper($params['country'] ?? $client->country ?? 'US'),
            ],
        ]);

        $handle = (string) ($data['handle'] ?? '');
        if ($handle === '') {
            throw new \RuntimeException('Openprovider did not return a customer handle.');
        }

        RegistrarSettings::updateOrCreate(
            ['registrar' => 'openprovider', 'setting' => 'handle_client_'.$client->id],
            ['value' => $handle]
        );

        return $handle;
    }

    /** @return array{0: string, 1: string} name and extension */
    protected function splitDomain(string $domain): array
    {
        $parts = explode('.', strtolower(trim($domain)), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \RuntimeException("'{$domain}' is not a valid domain name.");
        }

        return [$parts[0], $parts[1]];
    }

    /** @return array<int, array{name: string, seq_nr: int}> */
    protected function nameserverList($nameservers): array
    {
        if (is_string($nameservers)) {
            $nameservers = json_decode($nameservers, true) ?: [];
        }

        $list = [];
        foreach (array_values(array_filter((array) $nameservers)) as $i => $ns) {
            $list[] = ['name' => strtolower(trim((string) $ns)), 'seq_nr' => $i];
        }

        return $list;
    }

    /** @return array{0: string, 1: string} country code and subscriber */
    protected function splitPhone(string $phone): array
    {
        $phone = preg_replace('/[^\d+]/', '', $phone) ?? '';
        if (preg_match('/^(\+\d{1,3})(\d{4,})$/', $phone, $m)) {
            return [$m[1], $m[2]];
        }

        return ['+1', $phone !== '' ? ltrim($phone, '+') : '0000000'];
    }

    protected function parseDate(?string $value): ?string
    {
        try {
            return $value ? Carbon::parse($value)->toDateString() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function mapStatus(string $status): string
    {
        // ACT = active; REQ/SCH/PEN are in-flight; DEL/RDD mean it is gone.
        return match (strtoupper($status)) {
            'ACT' => 'active',
            'DEL', 'RDD' => 'expired',
            default => 'pending',
        };
    }

    /** @return array<string, string> */
    protected function loadSettings(): array
    {
        try {
            return RegistrarSettings::where('registrar', 'openprovider')
                ->pluck('value', 'setting')
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
    }
}
