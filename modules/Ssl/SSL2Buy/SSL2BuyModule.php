<?php

namespace Modules\Ssl\SSL2Buy;

use App\Models\SslOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Ssl\AbstractSslModule;
use RuntimeException;
use Throwable;

/**
 * SSL2BUY (ssl2buy.com) reseller API, "full order" flow: the panel collects the
 * CSR, contact and validation method, and places the order with them, so the
 * certificate comes back here instead of being configured on SSL2BUY's site.
 *
 * Covered: the single-domain and wildcard Sectigo/Comodo DV certificates, which
 * share one request shape. SSL2BUY has no API call to revoke or cancel; that
 * stays on its own panel.
 */
class SSL2BuyModule extends AbstractSslModule
{
    public const LIVE_URL = 'https://api.ssl2buy.com/';

    public const TEST_URL = 'https://demo-api.ssl2buy.com/';

    /**
     * SSL2BUY product code => the full-order call that buys it.
     *
     * @var array<int, array{path: string, name: string, brand: string, contact: string, wildcard: bool}>
     */
    public const PRODUCTS = [
        351 => ['path' => 'SectigoPositiveSSL', 'name' => 'Sectigo PositiveSSL', 'brand' => 'Sectigo', 'contact' => 'ContactDetails', 'wildcard' => false],
        352 => ['path' => 'SectigoPositiveWildcardSSL', 'name' => 'Sectigo PositiveSSL Wildcard', 'brand' => 'Sectigo', 'contact' => 'ContactDetails', 'wildcard' => true],
        362 => ['path' => 'SectigoEssentialSSL', 'name' => 'Sectigo EssentialSSL', 'brand' => 'Sectigo', 'contact' => 'OrganisationInfo', 'wildcard' => false],
        363 => ['path' => 'SectigoEssentialWildcardSSL', 'name' => 'Sectigo EssentialSSL Wildcard', 'brand' => 'Sectigo', 'contact' => 'OrganisationInfo', 'wildcard' => true],
        301 => ['path' => 'ComodoPositiveSSL', 'name' => 'Comodo PositiveSSL', 'brand' => 'Comodo', 'contact' => 'ContactDetails', 'wildcard' => false],
        302 => ['path' => 'ComodoPositiveWildcardSSL', 'name' => 'Comodo PositiveSSL Wildcard', 'brand' => 'Comodo', 'contact' => 'ContactDetails', 'wildcard' => true],
        314 => ['path' => 'ComodoEssentialSSL', 'name' => 'Comodo EssentialSSL', 'brand' => 'Comodo', 'contact' => 'OrganisationInfo', 'wildcard' => false],
        315 => ['path' => 'ComodoEssentialWildcardSSL', 'name' => 'Comodo EssentialSSL Wildcard', 'brand' => 'Comodo', 'contact' => 'OrganisationInfo', 'wildcard' => true],
    ];

    public function getModuleName(): string
    {
        return 'ssl2buy';
    }

    public function getConfigFields(): array
    {
        return [
            'partner_email' => [
                'label' => 'SSL2BUY login email',
                'type' => 'text',
                'required' => true,
            ],
            'api_key' => [
                'label' => 'API Key',
                'type' => 'password',
                'required' => true,
            ],
            'test_mode' => [
                'label' => 'Test mode (demo-api.ssl2buy.com, nothing is charged)',
                'type' => 'checkbox',
                'default' => '0',
            ],
            'default_product' => [
                'label' => 'Default certificate: SSL2BUY product code (351 Sectigo PositiveSSL, 352 PositiveSSL Wildcard, 362 EssentialSSL, 363 EssentialSSL Wildcard)',
                'type' => 'text',
            ],
            'product_map' => [
                'label' => 'Certificate per product: panel product ID=SSL2BUY code, comma separated (e.g. 12=351, 13=352)',
                'type' => 'text',
            ],
        ];
    }

    public function testConnection(): bool
    {
        try {
            $this->call('orderservice/order/getbalance');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** The account balance, or null when it could not be read. */
    public function getBalance(): ?float
    {
        try {
            return (float) ($this->call('orderservice/order/getbalance')['Balance'] ?? 0);
        } catch (Throwable) {
            return null;
        }
    }

    public function purchaseCertificate(SslOrder $order, array $config): array
    {
        $years = max(1, intdiv((int) ($config['period'] ?? 12), 12));

        return $this->placeOrder($order, $years, 'purchaseCertificate', 'Certificate order placed');
    }

    /** A renewal is a new order for the same certificate, with the CSR already on file. */
    public function renewCertificate(SslOrder $order): array
    {
        return $this->placeOrder($order, 1, 'renewCertificate', 'Renewal order placed');
    }

    public function getCertificateStatus(SslOrder $order): array
    {
        if (empty($order->remote_id)) {
            return $this->buildResult(false, 'No remote order ID');
        }

        try {
            $details = $this->call('queryservice/comodo/getorderdetails', ['OrderNumber' => (int) $order->remote_id]);
            $remoteStatus = (string) ($details['OrderStatus'] ?? '');
            $status = $this->mapRemoteStatus($remoteStatus);

            $updates = ['last_polled_at' => now(), 'status' => $status];
            $expires = $this->apiDate($details['EndDate'] ?? null);
            if ($expires) {
                $updates['crt_expires'] = $expires;
            }

            if ($status === 'Completed') {
                $certificate = $this->call('queryservice/ComodoFullOrder/GetCertificate', ['OrderNumber' => (int) $order->remote_id]);
                $cert = trim((string) ($certificate['Certificate'] ?? ''));
                $chain = implode("\n", array_filter([
                    trim((string) ($certificate['IntermediateCertificate1'] ?? '')),
                    trim((string) ($certificate['IntermediateCertificate2'] ?? '')),
                ]));

                if ($cert !== '') {
                    $updates['cert'] = $cert;
                    $updates['ca_cert'] = $chain !== '' ? $chain : null;
                    $updates['fullchain'] = $chain !== '' ? $cert."\n".$chain : $cert;
                }
                if (! $order->completion_date) {
                    $updates['completion_date'] = $this->apiDate($details['StartDate'] ?? null) ?? now();
                }
            }

            $order->update($updates);

            $result = $this->buildResult(true, "Status: {$remoteStatus}", ['status' => $remoteStatus]);
        } catch (Throwable $e) {
            $result = $this->buildResult(false, "Status check failed: {$e->getMessage()}");
        }

        $this->logAction($order, 'getCertificateStatus', $result);

        return $result;
    }

    /**
     * Every order on the SSL2BUY account, for importing certificates bought
     * before the panel. Null when the account could not be read.
     *
     * @return array<int, array{remote_id: string, domain: string, status: string, valid_from: string|null, valid_till: string|null}>|null
     */
    public function listRemoteOrders(): ?array
    {
        $orders = [];
        $page = 1;

        try {
            do {
                $response = $this->call('orderservice/order/getorderlist', ['PageNo' => $page, 'PageSize' => 50]);
                foreach ((array) ($response['OrderList'] ?? []) as $remote) {
                    if (! is_array($remote)) {
                        continue;
                    }
                    $orders[] = [
                        'remote_id' => (string) ($remote['OrderNumber'] ?? ''),
                        'domain' => (string) ($remote['DomainName'] ?? ''),
                        'status' => (string) ($remote['OrderStatus'] ?? ''),
                        'valid_from' => null,
                        'valid_till' => $this->apiDate($remote['ExpireOn'] ?? null),
                    ];
                }
                $pages = (int) ($response['TotalPages'] ?? 1);
                $page++;
            } while ($page <= $pages);
        } catch (Throwable $e) {
            Log::warning('SSL2BUY order list failed', ['error' => $e->getMessage()]);

            return null;
        }

        return $orders;
    }

    /** Map an SSL2BUY order status onto the panel's SslOrder status. */
    public function mapRemoteStatus(string $status): string
    {
        return match (strtoupper(str_replace(' ', '_', trim($status)))) {
            'COMPLETED', 'ISSUED', 'ACTIVE' => 'Completed',
            'CANCELLED', 'CANCELED', 'REJECTED', 'REFUNDED' => 'Cancelled',
            'EXPIRED' => 'Expired',
            'REVOKED' => 'Revoked',
            // LINKPENDING, INPROCESS, WAIT_FOR_APPROVAL, SECURITY_REVIEW: placed,
            // not issued. Never "Awaiting Configuration": configuring an order
            // here buys a new certificate.
            default => 'Awaiting Issuance',
        };
    }

    public function revokeCertificate(SslOrder $order, string $reason = ''): array
    {
        $result = $this->buildResult(false, 'SSL2BUY has no API call to revoke or cancel a certificate: do it from the SSL2BUY panel.');
        $this->logAction($order, 'revokeCertificate', $result);

        return $result;
    }

    public function reissueCertificate(SslOrder $order, string $newCsr): array
    {
        if (empty($order->remote_id)) {
            return $this->buildResult(false, 'No remote order ID');
        }

        try {
            $method = $this->approvalMethod((string) $order->validation_method, $this->isWildcard($order));
            $response = $this->call('queryservice/ComodoFullOrder/ReissueCertificate', array_filter([
                'OrderNumber' => (int) $order->remote_id,
                'CSR' => $newCsr,
                'ApprovalMethod' => $method,
                'ApprovalEmail' => $method === 'EMAIL' ? (string) $order->approver_email : null,
            ], fn ($value) => $value !== null));

            $order->update([
                'csr' => $newCsr,
                'status' => 'Awaiting Issuance',
                'cert' => null,
                'ca_cert' => null,
                'fullchain' => null,
                'completion_date' => null,
                'config_data' => $this->withDcv($order, $newCsr, $method, $response),
            ]);

            $result = $this->buildResult(true, 'Reissue requested', $this->publicFields($response));
        } catch (Throwable $e) {
            $result = $this->buildResult(false, "Reissue error: {$e->getMessage()}");
        }

        $this->logAction($order, 'reissueCertificate', $result);

        return $result;
    }

    public function resendValidationEmail(SslOrder $order): array
    {
        if (empty($order->remote_id)) {
            return $this->buildResult(false, 'No remote order ID');
        }

        try {
            $this->call('queryservice/ComodoFullOrder/ResendApprovalEmail', ['OrderNumber' => (int) $order->remote_id]);
            $result = $this->buildResult(true, 'Validation email resent');
        } catch (Throwable $e) {
            $result = $this->buildResult(false, "Resend error: {$e->getMessage()}");
        }

        $this->logAction($order, 'resendValidationEmail', $result);

        return $result;
    }

    public function changeValidationMethod(SslOrder $order, string $method): array
    {
        if (empty($order->remote_id)) {
            return $this->buildResult(false, 'No remote order ID');
        }

        try {
            $approvalMethod = $this->approvalMethod($method, $this->isWildcard($order));
            $response = $this->call('queryservice/ComodoFullOrder/ChangeDCVMethod/', array_filter([
                'OrderNumber' => (int) $order->remote_id,
                'DCVMethod' => $approvalMethod,
                'ApprovalEmail' => $approvalMethod === 'EMAIL' ? (string) $order->approver_email : null,
            ], fn ($value) => $value !== null));

            $order->update([
                'validation_method' => strtoupper($method),
                'config_data' => $this->withDcv($order, (string) $order->csr, $approvalMethod, $response),
            ]);

            $result = $this->buildResult(true, 'Validation method changed', $this->publicFields($response));
        } catch (Throwable $e) {
            $result = $this->buildResult(false, "Change DCV error: {$e->getMessage()}");
        }

        $this->logAction($order, 'changeValidationMethod', $result);

        return $result;
    }

    public function getApproverEmails(string $domain): array
    {
        $domain = $this->baseDomain($domain);

        try {
            $emails = $this->call('queryservice/ComodoFullOrder/GetComodoApprovalEmailList', ['DomainName' => $domain])['ApprovalEmailList'] ?? [];
            $emails = array_values(array_filter((array) $emails, fn ($email) => is_string($email) && str_contains($email, '@')));
            if ($emails !== []) {
                return $emails;
            }
        } catch (Throwable) {
            // Falls back to the addresses every CA accepts.
        }

        return array_map(fn (string $box) => "{$box}@{$domain}", ['admin', 'administrator', 'webmaster', 'hostmaster', 'postmaster']);
    }

    /** The server software list SSL2BUY passes on to Sectigo, which wants its own codes. */
    public function getWebServerTypes(): array
    {
        try {
            return Cache::remember('ssl2buy_webservers_'.($this->isTestMode() ? 'test' : 'live'), now()->addDay(), function () {
                $list = $this->call('queryservice/ComodoFullOrder/GetComodoWebServerList')['WebServerTypeList'] ?? [];
                $types = [];
                foreach ((array) $list as $key => $value) {
                    if (is_array($value)) {
                        $key = $value['Key'] ?? $value['key'] ?? $value['Id'] ?? $key;
                        $value = $value['Value'] ?? $value['value'] ?? $value['Name'] ?? '';
                    }
                    if ((string) $value !== '') {
                        $types[(string) $key] = (string) $value;
                    }
                }
                if ($types === []) {
                    throw new RuntimeException('empty web server list');
                }

                return $types;
            });
        } catch (Throwable) {
            return ['-1' => 'Other'];
        }
    }

    public function getCertificateTypes(): array
    {
        $types = [];
        foreach (self::PRODUCTS as $code => $product) {
            $types[] = [
                'id' => $code,
                'name' => $product['name'],
                'brand' => $product['brand'],
                'type' => 'DV',
                'wildcard' => $product['wildcard'],
                'multi_domain' => false,
                'min_period' => 12,
                'max_period' => 12,
            ];
        }

        return $types;
    }

    /** Read here with OpenSSL: the CSR never has to leave the panel to be decoded. */
    public function decodeCsr(string $csr): array
    {
        $subject = @openssl_csr_get_subject($csr, false);
        if (! is_array($subject)) {
            return ['success' => false, 'message' => 'The CSR could not be read'];
        }

        $publicKey = @openssl_csr_get_public_key($csr);
        $details = $publicKey ? openssl_pkey_get_details($publicKey) : false;

        return [
            'success' => true,
            'data' => [
                'cn' => (string) ($subject['commonName'] ?? ''),
                'org' => (string) ($subject['organizationName'] ?? ''),
                'ou' => (string) ($subject['organizationalUnitName'] ?? ''),
                'city' => (string) ($subject['localityName'] ?? ''),
                'state' => (string) ($subject['stateOrProvinceName'] ?? ''),
                'country' => (string) ($subject['countryName'] ?? ''),
                'email' => (string) ($subject['emailAddress'] ?? ''),
                'key_size' => is_array($details) ? (string) ($details['bits'] ?? '') : '',
                'san' => [],
            ],
        ];
    }

    /** Generated here, so the private key is never sent to anyone. */
    public function generateCsr(array $params): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            return ['success' => false, 'message' => 'The private key could not be generated'];
        }

        $subject = array_filter([
            'commonName' => (string) ($params['domain'] ?? ''),
            'organizationName' => (string) ($params['org'] ?? ''),
            'organizationalUnitName' => (string) ($params['ou'] ?? ''),
            'localityName' => (string) ($params['city'] ?? ''),
            'stateOrProvinceName' => (string) ($params['state'] ?? ''),
            'countryName' => (string) ($params['country'] ?? ''),
            'emailAddress' => (string) ($params['email'] ?? ''),
        ], fn (string $value) => $value !== '');

        $csr = openssl_csr_new($subject, $key, ['digest_alg' => 'sha256']);
        if ($csr === false || ! openssl_csr_export($csr, $csrPem) || ! openssl_pkey_export($key, $keyPem)) {
            return ['success' => false, 'message' => 'The CSR could not be generated'];
        }

        return ['success' => true, 'csr' => $csrPem, 'key' => $keyPem];
    }

    /**
     * What the domain owner has to publish for an HTTP or DNS validation: the
     * Sectigo CSR-hash scheme, built from the CSR itself plus the unique value
     * SSL2BUY returns. Null for email validation.
     *
     * @return array{method: string, url?: string, content?: string, name?: string, type?: string, value?: string}|null
     */
    public static function dcvInstructions(string $csr, string $approvalMethod, string $domain, ?string $uniqueValue = null): ?array
    {
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $csr), true);
        if ($der === false || $der === '') {
            return null;
        }

        $md5 = strtoupper(md5($der));
        $sha256 = strtoupper(hash('sha256', $der));
        $domain = preg_replace('/^\*\./', '', strtolower(trim($domain)));
        $uniqueValue = trim((string) $uniqueValue);

        return match ($approvalMethod) {
            'HTTPCSRHASH' => [
                'method' => 'HTTP',
                'url' => "http://{$domain}/.well-known/pki-validation/{$md5}.txt",
                'content' => $sha256."\nsectigo.com".($uniqueValue !== '' ? "\n".$uniqueValue : ''),
            ],
            'CNAMECSRHASH' => [
                'method' => 'DNS',
                'name' => "_{$md5}.{$domain}",
                'type' => 'CNAME',
                'value' => substr($sha256, 0, 32).'.'.substr($sha256, 32).'.'.($uniqueValue !== '' ? $uniqueValue.'.' : '').'sectigo.com',
            ],
            default => null,
        };
    }

    /** The SSL2BUY product this order buys: chosen on the order, by its product, or the default. */
    public function productCodeFor(SslOrder $order): ?int
    {
        $candidates = [
            $order->config_data['ssl2buy_product'] ?? null,
            $order->cert_type,
        ];

        $productId = $order->service?->product_id;
        if ($productId) {
            $candidates[] = $this->productMap()[(int) $productId] ?? null;
        }
        $candidates[] = $this->getSetting('default_product');

        foreach ($candidates as $candidate) {
            $code = (int) trim((string) $candidate);
            if (isset(self::PRODUCTS[$code])) {
                return $code;
            }
        }

        return null;
    }

    private function placeOrder(SslOrder $order, int $years, string $action, string $successMessage): array
    {
        try {
            $code = $this->productCodeFor($order);
            if ($code === null) {
                throw new RuntimeException('no SSL2BUY certificate is set for this product: set the default product code or the product map in the SSL2BUY settings');
            }
            if (empty($order->csr)) {
                throw new RuntimeException('the order has no CSR');
            }

            $product = self::PRODUCTS[$code];
            $method = $this->approvalMethod((string) $order->validation_method, $product['wildcard']);

            $response = $this->call('queryservice/ComodoFullOrder/'.$product['path'], [
                'Year' => $years,
                'PartnerOrderID' => 'pnlcs-'.$order->id.'-'.now()->format('YmdHis'),
                'WebServer' => (string) ($order->webserver_type ?: '-1'),
                'ApprovalMethod' => $method,
                'ApprovalEmail' => $method === 'EMAIL' ? (string) $order->approver_email : '',
                'CSR' => $order->csr,
                'IsAutoConfig' => false,
                $product['contact'] => $this->contact($order),
            ]);

            $remoteId = (string) ($response['SSL2BuyOrderNumber'] ?? '');
            if ($remoteId === '' || $remoteId === '0') {
                throw new RuntimeException('no order number in the answer');
            }

            $order->update([
                'remote_id' => $remoteId,
                'cert_type' => $product['name'],
                'status' => 'Awaiting Issuance',
                'order_date' => now(),
                'last_polled_at' => now(),
                'cert' => null,
                'ca_cert' => null,
                'fullchain' => null,
                'completion_date' => null,
                'config_data' => array_merge($this->withDcv($order, (string) $order->csr, $method, $response), [
                    'ssl2buy_product' => $code,
                    'ca_order_number' => (string) ($response['ComodoOrderNumber'] ?? ''),
                ]),
            ]);

            $result = $this->buildResult(true, $successMessage, $this->publicFields($response));
        } catch (Throwable $e) {
            $result = $this->buildResult(false, "SSL2BUY: {$e->getMessage()}");
        }

        $this->logAction($order, $action, $result);

        return $result;
    }

    /** @return array<string, string> the one contact block the DV products take */
    private function contact(SslOrder $order): array
    {
        return [
            'FirstName' => (string) $order->admin_first_name,
            'LastName' => (string) $order->admin_last_name,
            'Email' => (string) $order->admin_email,
            'Address1' => (string) $order->admin_address,
            'Address2' => '',
            'City' => (string) $order->admin_city,
            'State' => (string) $order->admin_state,
            'CountryName' => strtoupper((string) $order->admin_country),
            'PostalCode' => (string) $order->admin_zip,
            'PhoneNo' => (string) $order->admin_phone,
            'Fax' => '',
        ];
    }

    /** The panel's EMAIL / HTTP / DNS as the CSR-hash methods SSL2BUY names. */
    private function approvalMethod(string $method, bool $wildcard): string
    {
        $approvalMethod = match (strtoupper(trim($method))) {
            'HTTP', 'HTTPS', 'HTTPCSRHASH', 'HTTPSCSRHASH' => 'HTTPCSRHASH',
            'DNS', 'CNAME', 'CNAMECSRHASH' => 'CNAMECSRHASH',
            default => 'EMAIL',
        };

        if ($wildcard && $approvalMethod === 'HTTPCSRHASH') {
            throw new RuntimeException('a wildcard certificate cannot be validated by HTTP file: use email or DNS');
        }

        return $approvalMethod;
    }

    /** config_data with the validation the domain owner has to publish now. */
    private function withDcv(SslOrder $order, string $csr, string $method, array $response): array
    {
        $data = (array) ($order->config_data ?? []);
        $dcv = self::dcvInstructions($csr, $method, (string) ($response['DomainName'] ?? $order->domain), $response['UniqueValue'] ?? null);

        if ($dcv) {
            $data['dcv'] = $dcv;
        } else {
            unset($data['dcv']);
        }

        return $data;
    }

    private function isWildcard(SslOrder $order): bool
    {
        $code = $this->productCodeFor($order);

        return $code !== null ? self::PRODUCTS[$code]['wildcard'] : str_starts_with((string) $order->domain, '*.');
    }

    private function baseDomain(string $domain): string
    {
        return (string) preg_replace('/^\*\./', '', strtolower(trim($domain)));
    }

    /** @return array<int, int> panel product ID => SSL2BUY product code */
    private function productMap(): array
    {
        $map = [];
        foreach (preg_split('/[,;\n]+/', (string) $this->getSetting('product_map', '')) as $pair) {
            if (preg_match('/^\s*(\d+)\s*[=:]\s*(\d+)\s*$/', $pair, $m)) {
                $map[(int) $m[1]] = (int) $m[2];
            }
        }

        return $map;
    }

    private function isTestMode(): bool
    {
        return (string) $this->getSetting('test_mode', '0') === '1';
    }

    /** A date from the API, or null for an empty one (it sends 0001-01-01 for "none"). */
    private function apiDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0001-')) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** The answer without the API's own bookkeeping, for the log and the caller. */
    private function publicFields(array $response): array
    {
        unset($response['$id'], $response['Errors'], $response['APIError']);

        return $response;
    }

    /**
     * One API call: a JSON POST carrying the account credentials. SSL2BUY
     * answers HTTP 200 either way; StatusCode 0 is success, anything else an
     * error described in Errors.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function call(string $path, array $data = []): array
    {
        $response = Http::acceptJson()
            ->timeout(60)
            ->post(($this->isTestMode() ? self::TEST_URL : self::LIVE_URL).$path, [
                'PartnerEmail' => (string) $this->getSetting('partner_email', ''),
                'ApiKey' => (string) $this->getSetting('api_key', ''),
            ] + $data);

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('unexpected answer (HTTP '.$response->status().')');
        }

        if ((int) ($json['StatusCode'] ?? -1) !== 0) {
            $error = (array) ($json['Errors'] ?? $json['APIError'] ?? []);
            $message = trim((string) ($error['ErrorMessage'] ?? '')) ?: 'unknown error';
            $number = $error['ErrorNumber'] ?? null;

            throw new RuntimeException($number !== null ? "{$message} ({$number})" : $message);
        }

        return $json;
    }
}
