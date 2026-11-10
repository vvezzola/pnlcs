<?php

namespace Modules\Registrars\InternetBS;

use App\Contracts\ManagesWhoisPrivacy;
use App\Contracts\RegistrarModuleInterface;
use App\Contracts\SyncsDomainData;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\RegistrarSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Internet.bs, over its reseller API.
 *
 * Written against Internet.bs's own WHMCS registrar module ("ibs") and the
 * Blesta module, which both speak the documented API: every command is a
 * POST to https://api.internet.bs/<Command> (testapi.internet.bs for the
 * test environment) with ApiKey and Password among the form fields. The
 * default answer is plain text, one "name=value" per line; status is
 * SUCCESS, PENDING or FAILURE, and a failure carries its reason in message.
 * Lists come numbered: nameserver_0, nameserver_1, ...
 *
 * Renewals send the expiry date PNLCS holds (currentexpiration): the API
 * refuses the renewal when it does not match its own, so a retried renewal
 * can never add a second year.
 */
class InternetBSRegistrar implements ManagesWhoisPrivacy, RegistrarModuleInterface, SyncsDomainData
{
    protected string $apiUrl;

    protected string $apiKey;

    protected string $password;

    public function __construct()
    {
        $settings = $this->loadSettings();
        $this->apiUrl = ($settings['test_mode'] ?? '1') === '1'
            ? 'https://testapi.internet.bs'
            : 'https://api.internet.bs';
        $this->apiKey = (string) ($settings['api_key'] ?? '');
        $this->password = (string) ($settings['password'] ?? '');
    }

    public function getModuleName(): string
    {
        return 'Internet.bs';
    }

    public function getConfigFields(): array
    {
        return [
            ['name' => 'api_key', 'label' => 'API Key', 'type' => 'text', 'required' => true],
            ['name' => 'password', 'label' => 'API Password', 'type' => 'password', 'required' => true],
            ['name' => 'test_mode', 'label' => 'Test environment', 'type' => 'yesno', 'default' => '1'],
        ];
    }

    /** The account balance: proves the key, the password and the IP allow-list in one call. */
    public function testConnection(): array
    {
        try {
            $this->call('Account/Balance/Get');

            return ['success' => true, 'message' => 'Connected to Internet.bs.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function register(Domain $domain, int $years, array $params = []): array
    {
        try {
            $fields = ['domain' => $domain->domain, 'period' => max(1, $years).'Y']
                + $this->contactFields($domain, $params)
                + $this->dotItFields($domain, $params);

            $nameservers = $this->nameserverList($params['nameservers'] ?? $domain->nameservers);
            if ($nameservers !== '') {
                $fields['ns_list'] = $nameservers;
            }

            $result = $this->call('Domain/Create', $fields);

            return ['success' => true, 'message' => 'Domain '.(($result['status'] ?? '') === 'PENDING' ? 'registration submitted' : 'registered').' via Internet.bs.'];
        } catch (\Throwable $e) {
            Log::error('Internet.bs register failed', ['domain' => $domain->domain, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function transfer(Domain $domain, string $eppCode): array
    {
        try {
            $this->call('Domain/Transfer/Initiate', ['domain' => $domain->domain, 'transferAuthInfo' => $eppCode]
                + $this->contactFields($domain, [])
                + $this->dotItFields($domain, []));

            return ['success' => true, 'message' => 'Transfer initiated via Internet.bs.'];
        } catch (\Throwable $e) {
            Log::error('Internet.bs transfer failed', ['domain' => $domain->domain, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function renew(Domain $domain, int $years): array
    {
        try {
            $years = max(1, $years);
            $fields = ['domain' => $domain->domain, 'period' => $years.'Y'];
            if ($domain->expiry_date) {
                $fields['currentexpiration'] = $domain->expiry_date->toDateString();
            }

            $result = $this->call('Domain/Renew', $fields);

            // The registry's new date when the answer carries one; otherwise
            // the old date moved by the years renewed, as the other modules do.
            $newExpiry = $this->parseDate($result['product_0_newexpiration'] ?? null)
                ?? ($domain->expiry_date ?? now())->copy()->addYears($years)->toDateString();
            $domain->update(['expiry_date' => $newExpiry, 'next_due_date' => $newExpiry]);

            return ['success' => true, 'message' => "Domain renewed via Internet.bs for {$years} year(s).", 'expiry_date' => $newExpiry];
        } catch (\Throwable $e) {
            Log::error('Internet.bs renew failed', ['domain' => $domain->domain, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getNameservers(Domain $domain): array
    {
        try {
            return $this->numbered($this->info($domain), 'nameserver');
        } catch (\Throwable) {
            return json_decode($domain->nameservers ?? '[]', true) ?: [];
        }
    }

    public function saveNameservers(Domain $domain, array $nameservers): bool
    {
        try {
            $this->call('Domain/Update', ['domain' => $domain->domain, 'ns_list' => $this->nameserverList($nameservers)]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Internet.bs saveNameservers failed', ['domain' => $domain->domain, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function getEPPCode(Domain $domain): string
    {
        return (string) ($this->info($domain)['transferauthinfo'] ?? '');
    }

    public function getLockStatus(Domain $domain): bool
    {
        try {
            $result = $this->call('Domain/RegistrarLock/Status', ['domain' => $domain->domain]);

            return strtoupper((string) ($result['registrar_lock_status'] ?? '')) === 'LOCKED';
        } catch (\Throwable) {
            return false;
        }
    }

    public function toggleLock(Domain $domain, bool $lock): bool
    {
        try {
            $this->call('Domain/RegistrarLock/'.($lock ? 'Enable' : 'Disable'), ['domain' => $domain->domain]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Internet.bs toggleLock failed', ['domain' => $domain->domain, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function setPrivacy(Domain $domain, bool $enabled): array
    {
        try {
            $this->call('Domain/PrivateWhois/'.($enabled ? 'Enable' : 'Disable'), ['domain' => $domain->domain]);

            return ['success' => true, 'message' => $enabled ? 'WHOIS privacy enabled.' : 'WHOIS privacy disabled.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function checkAvailability(string $domain): array
    {
        try {
            $result = $this->call('Domain/Check', ['domain' => $domain], acceptStatuses: ['AVAILABLE', 'UNAVAILABLE']);

            return ['available' => ($result['status'] ?? '') === 'AVAILABLE', 'domain' => $domain, 'method' => 'internetbs_api'];
        } catch (\Throwable $e) {
            return ['available' => false, 'domain' => $domain, 'method' => 'internetbs_api', 'error' => $e->getMessage()];
        }
    }

    /**
     * The registry's view of the domain. paiduntil is what the registrar has
     * been paid for; for some registries (.it among them) expirationdate can
     * lag behind it, so paiduntil wins when it is given - the WHMCS module of
     * Internet.bs reads it the same way.
     */
    public function syncDomain(Domain $domain): array
    {
        try {
            $info = $this->info($domain);
            $paidUntil = $info['paiduntil'] ?? null;
            $lock = strtoupper((string) ($info['registrarlock'] ?? ''));
            $privacy = strtoupper((string) ($info['privatewhois'] ?? ''));

            return [
                'success' => true,
                'registration_date' => $this->parseDate($info['registrationdate'] ?? null),
                'expiry_date' => $this->parseDate($paidUntil !== 'n/a' ? $paidUntil : null)
                    ?? $this->parseDate($info['expirationdate'] ?? null),
                'status' => $this->mapStatus((string) ($info['domainstatus'] ?? '')),
                'locked' => $lock === '' ? null : in_array($lock, ['ENABLED', 'LOCKED'], true),
                'nameservers' => $this->numbered($info, 'nameserver'),
                'id_protection' => $privacy === '' ? null : $privacy !== 'DISABLED',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * The names of every domain in the account, for importing a portfolio that
     * already lives at Internet.bs; each one's dates then come from syncDomain.
     * Asked as JSON, the one shape of this answer the API documents:
     * {"status": "SUCCESS", "domaincount": n, "domain": ["example.com", ...]}.
     *
     * @return array<int, string>
     */
    public function listDomains(): array
    {
        $response = Http::asForm()->timeout(120)->post($this->apiUrl.'/Domain/List', [
            'apikey' => $this->apiKey, 'password' => $this->password, 'ResponseFormat' => 'JSON',
        ]);
        $json = $response->json() ?? [];

        if (! $response->successful() || strtoupper((string) ($json['status'] ?? '')) !== 'SUCCESS') {
            throw new \RuntimeException('Internet.bs: '.($json['message'] ?? ('HTTP '.$response->status())));
        }

        return array_values(array_map(fn ($name) => strtolower((string) $name), (array) ($json['domain'] ?? [])));
    }

    // ── Internet.bs plumbing ────────────────────────────────────────────

    /** @return array<string, string> */
    protected function info(Domain $domain): array
    {
        return $this->call('Domain/Info', ['domain' => $domain->domain]);
    }

    /**
     * One command. The answer's status decides: FAILURE (or anything not
     * expected) throws its message, which is what the operator then sees.
     *
     * @param  array<string, scalar|null>  $fields
     * @param  array<int, string>  $acceptStatuses
     * @return array<string, string> lower-cased keys
     */
    protected function call(string $command, array $fields = [], array $acceptStatuses = ['SUCCESS', 'PENDING']): array
    {
        if ($this->apiKey === '' || $this->password === '') {
            throw new \RuntimeException('Internet.bs API key and password are not configured.');
        }

        $response = Http::asForm()->timeout(30)->post($this->apiUrl.'/'.$command, array_filter(
            ['apikey' => $this->apiKey, 'password' => $this->password] + $fields,
            fn ($value) => $value !== null && $value !== ''
        ));

        if (! $response->successful()) {
            throw new \RuntimeException('Internet.bs: HTTP '.$response->status());
        }

        $result = $this->parse($response->body());
        $status = strtoupper($result['status'] ?? '');

        if (! in_array($status, $acceptStatuses, true)) {
            throw new \RuntimeException('Internet.bs: '.($result['message'] ?? ($status !== '' ? $status : 'no answer')));
        }

        return $result;
    }

    /** @return array<string, string> */
    protected function parse(string $body): array
    {
        $result = [];
        foreach (preg_split('/\r?\n/', trim($body)) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $result[strtolower(trim($name))] = trim($value);
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $result
     * @return array<int, string> name_0, name_1, ... in order
     */
    protected function numbered(array $result, string $name): array
    {
        $values = [];
        for ($i = 0; isset($result["{$name}_{$i}"]); $i++) {
            $values[] = strtolower($result["{$name}_{$i}"]);
        }

        return $values;
    }

    /** @param  array<int, string>|string|null  $nameservers */
    protected function nameserverList($nameservers): string
    {
        if (is_string($nameservers)) {
            $nameservers = json_decode($nameservers, true) ?: [];
        }

        return implode(',', array_map(fn ($ns) => strtolower(trim((string) $ns)), array_values(array_filter((array) $nameservers))));
    }

    /**
     * The customer as registrant and as admin, technical and billing contact.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string|null>
     */
    protected function contactFields(Domain $domain, array $params): array
    {
        $client = $domain->client;
        $contact = [
            'FirstName' => $params['firstname'] ?? $client?->first_name,
            'LastName' => $params['lastname'] ?? $client?->last_name,
            'Organization' => $params['companyname'] ?? $client?->company_name,
            'Email' => $params['email'] ?? $client?->email,
            'PhoneNumber' => $this->phone($params['phone'] ?? $client?->phone_number ?? '', $params['phone_prefix'] ?? $client?->phone_prefix ?? ''),
            'Street' => $params['address'] ?? $client?->address1,
            'Street2' => $params['address2'] ?? $client?->address2,
            'City' => $params['city'] ?? $client?->city,
            'PostalCode' => $params['postcode'] ?? $client?->postcode,
            'CountryCode' => strtoupper((string) ($params['country'] ?? $client?->country ?? '')),
        ];

        $fields = [];
        foreach (['registrant', 'admin', 'technical', 'billing'] as $role) {
            $fields["{$role}_firstname"] = $contact['FirstName'];
            $fields["{$role}_lastname"] = $contact['LastName'];
            $fields["{$role}_email"] = $contact['Email'];
            $fields["{$role}_phonenumber"] = $contact['PhoneNumber'];
            $fields["{$role}_street"] = $contact['Street'];
            $fields["{$role}_street2"] = $contact['Street2'];
            $fields["{$role}_city"] = $contact['City'];
            $fields["{$role}_postalcode"] = $contact['PostalCode'];
            $fields["{$role}_countrycode"] = $contact['CountryCode'];
            // The one field the API spells with a capital, as Internet.bs's
            // own WHMCS module sends it.
            $fields[($role === 'registrant' ? 'Registrant' : $role).'_Organization'] = $contact['Organization'];
        }

        return $fields;
    }

    /**
     * What the .it registry asks of a registrant: who they are (a person, or
     * a company), their nationality, province and tax code or VAT number,
     * and the four acceptances of its terms. Read from the client record:
     * a company name makes the registrant a company (type 2), otherwise a
     * natural person (type 1), as the Internet.bs WHMCS module maps them.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string|null>
     */
    protected function dotItFields(Domain $domain, array $params): array
    {
        if (! str_ends_with(strtolower($domain->domain), '.it')) {
            return [];
        }

        $client = $domain->client;
        $country = strtoupper((string) ($params['country'] ?? $client?->country ?? 'IT'));
        $isCompany = trim((string) ($params['companyname'] ?? $client?->company_name ?? '')) !== '';
        $province = strtoupper(trim((string) ($params['state'] ?? $client?->state ?? '')));
        $regCode = $params['dotit_regcode'] ?? ($isCompany
            ? ($client?->tax_id ?: $client?->national_id)
            : ($client?->national_id ?: $client?->tax_id));

        return [
            'registrant_dotitentitytype' => (string) ($params['dotit_entity_type'] ?? ($isCompany ? 2 : 1)),
            'registrant_dotitnationality' => strtoupper((string) ($params['dotit_nationality'] ?? $country)),
            'registrant_dotitprovince' => $province,
            'admin_dotitprovince' => $province,
            'technical_dotitprovince' => $province,
            'registrant_dotitregcode' => $regCode,
            'registrant_dotithidewhois' => $isCompany ? 'NO' : 'YES',
            'admin_dotithidewhois' => $isCompany ? 'NO' : 'YES',
            'technical_dotithidewhois' => 'YES',
            'registrant_dotitterm1' => 'yes',
            'registrant_dotitterm2' => 'yes',
            'registrant_dotitterm3' => $isCompany ? 'yes' : 'no',
            'registrant_dotitterm4' => 'yes',
            'registrant_clientip' => $params['client_ip'] ?? $client?->ip_address,
        ];
    }

    /** +CC.NUMBER, the form Internet.bs asks for; Italy when no prefix is known. */
    protected function phone(string $number, string $prefix): string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';
        $prefix = preg_replace('/\D/', '', $prefix) ?: '39';

        if ($digits === '') {
            return '';
        }
        if (str_starts_with(trim($number), '+') && str_starts_with($digits, $prefix)) {
            $digits = substr($digits, strlen($prefix));
        }

        return "+{$prefix}.{$digits}";
    }

    protected function parseDate(?string $value): ?string
    {
        try {
            return $value ? Carbon::parse(str_replace('/', '-', $value))->toDateString() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function mapStatus(string $status): string
    {
        return match (strtoupper(trim($status))) {
            'REGISTERED', 'REGISTRAR LOCKED', 'OK', 'ACTIVE' => DomainStatus::Active->value,
            'REDEMPTIONPERIOD', 'REDEMPTION PERIOD' => DomainStatus::Redemption->value,
            'EXPIRED', 'PENDINGDELETE', 'PENDING DELETE' => DomainStatus::Expired->value,
            'DELETED' => DomainStatus::Cancelled->value,
            'TRANSFERRED AWAY', 'TRANSFERRED_AWAY' => DomainStatus::TransferredAway->value,
            default => DomainStatus::Pending->value,
        };
    }

    /** @return array<string, string> */
    protected function loadSettings(): array
    {
        try {
            return RegistrarSettings::where('registrar', 'internetbs')->pluck('value', 'setting')->toArray();
        } catch (\Throwable) {
            return [];
        }
    }
}
