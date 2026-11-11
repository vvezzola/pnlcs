<?php

namespace Modules\Ssl\SSL2Buy;

use App\Models\SslOrder;
use Carbon\Carbon;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Ssl\AbstractSslModule;
use RuntimeException;
use Throwable;

/**
 * SSL2BUY (ssl2buy.com) reseller API.
 *
 * New certificates are bought with the "full order" calls: the panel collects
 * the CSR, contact and validation method and places the order with them, so
 * the certificate comes back here instead of being configured on SSL2BUY's
 * site. That covers AlphaSSL (GlobalSign) and the Sectigo/Comodo DV
 * certificates. Status, download and import work for every order on the
 * account, PrimeSSL and DigiCert brands included, since SSL2BUY answers those
 * per certificate authority ("family").
 *
 * SSL2BUY has no API call to revoke or cancel; that stays on its own panel.
 */
class SSL2BuyModule extends AbstractSslModule
{
    public const LIVE_URL = 'https://api.ssl2buy.com/';

    public const TEST_URL = 'https://demo-api.ssl2buy.com/';

    /**
     * Where each certificate authority's orders are read: details, the issued
     * certificate (and the fields it comes in), the approval e-mail resend.
     *
     * @var array<string, array{details: string, certificate: string|null, cert: string, chain: list<string>, resend: string|null}>
     */
    public const FAMILIES = [
        'globalsign' => [
            'details' => 'queryservice/globalsign/getorderdetails',
            'certificate' => 'orderservice/GlobalSignFullOrder/GetCertificate',
            'cert' => 'X509Cert',
            'chain' => ['InterCert'],
            'resend' => 'queryservice/globalsign/resendapprovalemail',
        ],
        'comodo' => [
            'details' => 'queryservice/comodo/getorderdetails',
            'certificate' => 'queryservice/ComodoFullOrder/GetCertificate',
            'cert' => 'Certificate',
            'chain' => ['IntermediateCertificate1', 'IntermediateCertificate2'],
            'resend' => 'queryservice/ComodoFullOrder/ResendApprovalEmail',
        ],
        'prime' => [
            'details' => 'queryservice/prime/getorderdetails',
            'certificate' => 'queryservice/prime/getcertificate',
            'cert' => 'Certificate',
            'chain' => ['IntermediateCertificate1', 'IntermediateCertificate2'],
            'resend' => null,
        ],
        'digicert' => [
            'details' => 'queryservice/symantec/getorderdetails',
            'certificate' => null,
            'cert' => 'Certificate',
            'chain' => [],
            'resend' => 'queryservice/symantec/resendapprovalemail',
        ],
    ];

    /**
     * SSL2BUY product code => the full-order call that buys it, and the
     * validation methods the authority accepts for it (panel name => API name).
     *
     * @var array<int, array{family: string, path: string, name: string, contact: string, wildcard: bool, methods: array<string, string>}>
     */
    public const PRODUCTS = [
        101 => ['family' => 'globalsign', 'path' => 'orderservice/GlobalSignFullOrder/AlphaSSLFullOrder', 'name' => 'AlphaSSL', 'contact' => 'ContactInfo', 'wildcard' => false, 'methods' => ['EMAIL' => 'EMAIL', 'DNS' => 'DNS', 'HTTP' => 'URL']],
        102 => ['family' => 'globalsign', 'path' => 'orderservice/GlobalSignFullOrder/AlphaSSLWildCardFullOrder', 'name' => 'AlphaSSL Wildcard', 'contact' => 'ContactInfo', 'wildcard' => true, 'methods' => ['EMAIL' => 'EMAIL', 'DNS' => 'DNS']],
        351 => ['family' => 'comodo', 'path' => 'queryservice/ComodoFullOrder/SectigoPositiveSSL', 'name' => 'Sectigo PositiveSSL', 'contact' => 'ContactDetails', 'wildcard' => false, 'methods' => ['EMAIL' => 'EMAIL', 'DNS' => 'CNAMECSRHASH', 'HTTP' => 'HTTPCSRHASH']],
        352 => ['family' => 'comodo', 'path' => 'queryservice/ComodoFullOrder/SectigoPositiveWildcardSSL', 'name' => 'Sectigo PositiveSSL Wildcard', 'contact' => 'ContactDetails', 'wildcard' => true, 'methods' => ['EMAIL' => 'EMAIL', 'DNS' => 'CNAMECSRHASH']],
        362 => ['family' => 'comodo', 'path' => 'queryservice/ComodoFullOrder/SectigoEssentialSSL', 'name' => 'Sectigo EssentialSSL', 'contact' => 'OrganisationInfo', 'wildcard' => false, 'methods' => ['EMAIL' => 'EMAIL', 'DNS' => 'CNAMECSRHASH', 'HTTP' => 'HTTPCSRHASH']],
        363 => ['family' => 'comodo', 'path' => 'queryservice/ComodoFullOrder/SectigoEssentialWildcardSSL', 'name' => 'Sectigo EssentialSSL Wildcard', 'contact' => 'OrganisationInfo', 'wildcard' => true, 'methods' => ['EMAIL' => 'EMAIL', 'DNS' => 'CNAMECSRHASH']],
        301 => ['family' => 'comodo', 'path' => 'queryservice/ComodoFullOrder/ComodoPositiveSSL', 'name' => 'Comodo PositiveSSL', 'contact' => 'ContactDetails', 'wildcard' => false, 'methods' => ['EMAIL' => 'EMAIL', 'DNS' => 'CNAMECSRHASH', 'HTTP' => 'HTTPCSRHASH']],
        302 => ['family' => 'comodo', 'path' => 'queryservice/ComodoFullOrder/ComodoPositiveWildcardSSL', 'name' => 'Comodo PositiveSSL Wildcard', 'contact' => 'ContactDetails', 'wildcard' => true, 'methods' => ['EMAIL' => 'EMAIL', 'DNS' => 'CNAMECSRHASH']],
        314 => ['family' => 'comodo', 'path' => 'queryservice/ComodoFullOrder/ComodoEssentialSSL', 'name' => 'Comodo EssentialSSL', 'contact' => 'OrganisationInfo', 'wildcard' => false, 'methods' => ['EMAIL' => 'EMAIL', 'DNS' => 'CNAMECSRHASH', 'HTTP' => 'HTTPCSRHASH']],
        315 => ['family' => 'comodo', 'path' => 'queryservice/ComodoFullOrder/ComodoEssentialWildcardSSL', 'name' => 'Comodo EssentialSSL Wildcard', 'contact' => 'OrganisationInfo', 'wildcard' => true, 'methods' => ['EMAIL' => 'EMAIL', 'DNS' => 'CNAMECSRHASH']],
    ];

    /**
     * The product codes SSL2BUY sells. It has no call that lists them, only one
     * that prices a code, so the names and prices are read code by code.
     *
     * @var list<int>
     */
    public const CATALOGUE = [
        101, 102, 103, 104, 105, 106, 107, 108, 109, 110,
        201, 202, 204, 205, 208, 209, 210, 211, 212, 213, 214, 215,
        301, 302, 303, 305, 308, 311, 314, 315, 316, 318, 319, 321, 322,
        351, 352, 354, 355, 357, 358, 360, 361, 362, 363, 364, 365, 366, 367, 368, 370, 371, 373, 374,
        401, 402, 410, 412,
        501, 502, 503, 504, 505, 506, 507, 508, 510, 512, 513, 514,
    ];

    public function getModuleName(): string
    {
        return 'ssl2buy';
    }

    public function getConfigFields(): array
    {
        $catalogue = $this->catalogue();

        $fields = [
            'partner_email' => [
                'label' => __('common.ssl2buy.partner_email'),
                'type' => 'text',
                'required' => true,
            ],
            'api_key' => [
                'label' => __('common.ssl2buy.api_key'),
                'type' => 'password',
                'required' => true,
            ],
            'test_mode' => [
                'label' => __('common.ssl2buy.test_mode'),
                'type' => 'checkbox',
                'default' => '0',
            ],
            'default_product' => [
                'label' => __('common.ssl2buy.default_product'),
                'type' => 'select',
                'options' => $this->orderableOptions($catalogue),
            ],
            'product_map' => [
                'label' => __('common.ssl2buy.product_map'),
                'type' => 'text',
            ],
        ];

        if ($catalogue !== []) {
            $fields['catalogue'] = [
                'label' => __('common.ssl2buy.catalogue', ['count' => count($catalogue)]),
                'type' => 'table',
                'headers' => [
                    __('common.ssl2buy.col_name'),
                    __('common.ssl2buy.col_code'),
                    __('common.ssl2buy.col_price'),
                    __('common.ssl2buy.col_extra_domain'),
                    __('common.ssl2buy.col_orderable'),
                ],
                'rows' => array_map(fn (int $code, array $product) => [
                    $product['name'],
                    (string) $code,
                    number_format($product['price'], 2),
                    $product['extra_domain'] > 0 ? number_format($product['extra_domain'], 2) : '—',
                    isset(self::PRODUCTS[$code]) ? __('common.yes') : __('common.no'),
                ], array_keys($catalogue), $catalogue),
            ];
        }

        return $fields;
    }

    /**
     * The certificates the panel can buy, for the drop-down, with the price
     * when the account could be read.
     *
     * @param  array<int, array{name: string, price: float, extra_domain: float}>  $catalogue
     * @return array<int|string, string>
     */
    private function orderableOptions(array $catalogue): array
    {
        $options = ['' => __('common.ssl2buy.no_default')];

        foreach (self::PRODUCTS as $code => $product) {
            $options[$code] = isset($catalogue[$code])
                ? $product['name'].' ('.$code.') - '.number_format($catalogue[$code]['price'], 2)
                : $product['name'].' ('.$code.')';
        }

        return $options;
    }

    /**
     * What SSL2BUY sells, as it prices it for this account for one year: code
     * => name and prices. Empty until the credentials are set or when they do
     * not work. Kept for a day, or five minutes after a failure so the settings
     * page does not wait on a provider that is down at every load.
     *
     * @return array<int, array{name: string, price: float, extra_domain: float}>
     */
    public function catalogue(): array
    {
        if (blank($this->getSetting('partner_email')) || blank($this->getSetting('api_key'))) {
            return [];
        }

        $key = 'ssl2buy_catalogue_'.($this->isTestMode() ? 'test' : 'live');
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $catalogue = $this->fetchCatalogue();
        Cache::put($key, $catalogue, $catalogue === [] ? now()->addMinutes(5) : now()->addDay());

        return $catalogue;
    }

    /** @return array<int, array{name: string, price: float, extra_domain: float}> */
    private function fetchCatalogue(): array
    {
        $url = ($this->isTestMode() ? self::TEST_URL : self::LIVE_URL).'orderservice/order/getproductprice';
        $credentials = [
            'PartnerEmail' => (string) $this->getSetting('partner_email', ''),
            'ApiKey' => (string) $this->getSetting('api_key', ''),
        ];

        $catalogue = [];

        try {
            foreach (array_chunk(self::CATALOGUE, 10) as $codes) {
                $responses = Http::pool(fn (Pool $pool) => array_map(
                    fn (int $code) => $pool->as((string) $code)->acceptJson()->timeout(15)
                        ->post($url, $credentials + ['ProductId' => $code, 'Year' => 1]),
                    $codes
                ));

                foreach ($responses as $code => $response) {
                    $json = $response instanceof Response ? $response->json() : null;
                    if (! is_array($json) || (int) ($json['StatusCode'] ?? -1) !== 0 || blank($json['ProductName'] ?? null)) {
                        continue;
                    }

                    $catalogue[(int) $code] = [
                        'name' => trim((string) $json['ProductName']),
                        'price' => (float) ($json['Price'] ?? 0),
                        'extra_domain' => (float) ($json['AddDomainPrice'] ?? 0),
                    ];
                }
            }
        } catch (Throwable $e) {
            Log::warning('SSL2BUY catalogue failed', ['error' => $e->getMessage()]);

            return [];
        }

        ksort($catalogue);

        return $catalogue;
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
            [$family, $details] = $this->orderDetails($order);
            $remoteStatus = (string) ($details['OrderStatus'] ?? '');
            $status = $this->mapRemoteStatus($remoteStatus);

            $updates = ['last_polled_at' => now(), 'status' => $status];
            $data = (array) ($order->config_data ?? []);
            $data['ssl2buy_family'] = $family;

            $expires = $this->apiDate($details['EndDate'] ?? null);
            if ($expires) {
                $updates['crt_expires'] = $expires;
            }
            $productName = (string) ($details['BaseOrderDetails']['ProductName'] ?? '');
            if ($productName !== '' && empty($order->cert_type)) {
                $updates['cert_type'] = $productName;
            }

            if ($status === 'Completed') {
                $updates += $this->downloadCertificate($family, (string) $order->remote_id);
                if (! $order->completion_date) {
                    $updates['completion_date'] = $this->apiDate($details['StartDate'] ?? null) ?? now();
                }
            } elseif ($family === 'globalsign' && ($dcv = $this->globalSignDcv($details, (string) $order->domain))) {
                // GlobalSign hands out the DNS/file value once the order exists.
                $data['dcv'] = $dcv;
            }

            $updates['config_data'] = $data;
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
            'COMPLETED', 'ISSUED', 'ACTIVE', 'VALID' => 'Completed',
            'CANCELLED', 'CANCELED', 'REJECTED', 'REFUNDED' => 'Cancelled',
            'EXPIRED' => 'Expired',
            'REVOKED' => 'Revoked',
            // LINKPENDING, PENDING_CONFIGURATION, INPROCESS, WAIT_FOR_APPROVAL,
            // SECURITY_REVIEW: placed, not issued. Never "Awaiting
            // Configuration": configuring an order here buys a new certificate.
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
            $this->requireComodo($order, 'reissue');
            $method = $this->approvalMethod($order, (string) $order->validation_method);
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
                'config_data' => $this->withDcv($order, 'comodo', $newCsr, $method, $response),
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
            $path = self::FAMILIES[$this->familyFor($order) ?? 'comodo']['resend'];
            if ($path === null) {
                throw new RuntimeException('SSL2BUY offers no resend for this certificate: use the SSL2BUY panel');
            }
            $this->call($path, ['OrderNumber' => (int) $order->remote_id]);
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
            $this->requireComodo($order, 'change the validation method');
            $approvalMethod = $this->approvalMethod($order, $method);
            $response = $this->call('queryservice/ComodoFullOrder/ChangeDCVMethod/', array_filter([
                'OrderNumber' => (int) $order->remote_id,
                'DCVMethod' => $approvalMethod,
                'ApprovalEmail' => $approvalMethod === 'EMAIL' ? (string) $order->approver_email : null,
            ], fn ($value) => $value !== null));

            $order->update([
                'validation_method' => strtoupper($method),
                'config_data' => $this->withDcv($order, 'comodo', (string) $order->csr, $approvalMethod, $response),
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

    /** The server software list Sectigo wants its own codes from (code => name). */
    public function getWebServerTypes(): array
    {
        try {
            return Cache::remember('ssl2buy_webservers_'.($this->isTestMode() ? 'test' : 'live'), now()->addDay(), function () {
                $list = $this->call('queryservice/ComodoFullOrder/GetComodoWebServerList')['WebServerTypeList'] ?? [];
                $types = [];
                // Sent as {"Apache/ModSSL": "2", ...}: name => code.
                foreach ((array) $list as $name => $code) {
                    if (str_starts_with((string) $name, '$') || ! is_scalar($code) || (string) $code === '') {
                        continue;
                    }
                    $types[(string) $code] = (string) $name;
                }
                if ($types === []) {
                    throw new RuntimeException('empty web server list');
                }

                return $types;
            });
        } catch (Throwable) {
            return ['-1' => 'OTHER'];
        }
    }

    public function getCertificateTypes(): array
    {
        $types = [];
        foreach (self::PRODUCTS as $code => $product) {
            $types[] = [
                'id' => $code,
                'name' => $product['name'],
                'brand' => $product['family'] === 'globalsign' ? 'GlobalSign' : 'Sectigo',
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
     * What the domain owner has to publish for a Sectigo HTTP or DNS
     * validation: the CSR-hash scheme, built from the CSR itself plus the
     * unique value SSL2BUY returns. Null for email validation.
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

    /**
     * The certificate authority an order belongs to, when it can be told
     * without asking SSL2BUY: remembered from an earlier poll, the product
     * bought here, or the product name an imported order carries.
     */
    public function familyFor(SslOrder $order): ?string
    {
        $family = $order->config_data['ssl2buy_family'] ?? null;
        if (is_string($family) && isset(self::FAMILIES[$family])) {
            return $family;
        }

        $code = (int) ($order->config_data['ssl2buy_product'] ?? 0);
        if (isset(self::PRODUCTS[$code])) {
            return self::PRODUCTS[$code]['family'];
        }

        return self::familyForName((string) $order->cert_type);
    }

    public static function familyForName(string $productName): ?string
    {
        return match (true) {
            (bool) preg_match('/alphassl|globalsign/i', $productName) => 'globalsign',
            (bool) preg_match('/primessl/i', $productName) => 'prime',
            (bool) preg_match('/sectigo|comodo|positive|essential|instantssl/i', $productName) => 'comodo',
            (bool) preg_match('/rapidssl|geotrust|thawte|digicert|symantec/i', $productName) => 'digicert',
            default => null,
        };
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
            $method = $this->approvalMethod($order, (string) $order->validation_method, $code);
            $partnerOrderId = 'pnlcs-'.$order->id.'-'.now()->format('YmdHis');

            $payload = $product['family'] === 'globalsign'
                ? [
                    'Year' => $years,
                    'PartnerOrderID' => $partnerOrderId,
                    'ApprovalMethod' => $method,
                    // Required even when the domain is validated by DNS or file.
                    'ApprovalEmail' => (string) ($order->approver_email ?: 'admin@'.$this->baseDomain((string) $order->domain)),
                    'CSR' => $order->csr,
                    'IsAutoConfig' => false,
                    'ContactInfo' => [
                        'Title' => '',
                        'FirstName' => (string) $order->admin_first_name,
                        'LastName' => (string) $order->admin_last_name,
                        'Email' => (string) $order->admin_email,
                        'OrganizationName' => (string) $order->admin_org,
                        'OrganizationUnit' => '',
                        'Phone' => (string) $order->admin_phone,
                        'FunctionInOrg' => '',
                    ],
                ]
                : [
                    'Year' => $years,
                    'PartnerOrderID' => $partnerOrderId,
                    'WebServer' => (string) ($order->webserver_type ?: '-1'),
                    'ApprovalMethod' => $method,
                    'ApprovalEmail' => $method === 'EMAIL' ? (string) $order->approver_email : '',
                    'CSR' => $order->csr,
                    'IsAutoConfig' => false,
                    $product['contact'] => $this->sectigoContact($order),
                ];

            $response = $this->call($product['path'], $payload);

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
                'config_data' => array_merge($this->withDcv($order, $product['family'], (string) $order->csr, $method, $response), [
                    'ssl2buy_product' => $code,
                    'ssl2buy_family' => $product['family'],
                    'ca_order_number' => (string) ($response['ComodoOrderNumber'] ?? $response['GSOrderNumber'] ?? ''),
                ]),
            ]);

            $result = $this->buildResult(true, $successMessage, $this->publicFields($response));
        } catch (Throwable $e) {
            $result = $this->buildResult(false, "SSL2BUY: {$e->getMessage()}");
        }

        $this->logAction($order, $action, $result);

        return $result;
    }

    /**
     * The order's details from its certificate authority's endpoint. An order
     * whose authority is not known yet (imported) is looked up under each one
     * until SSL2BUY recognises it.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function orderDetails(SslOrder $order): array
    {
        $known = $this->familyFor($order);
        $families = $known ? [$known] : array_keys(self::FAMILIES);
        $lastError = null;

        foreach ($families as $family) {
            try {
                $details = $this->call(self::FAMILIES[$family]['details'], ['OrderNumber' => (int) $order->remote_id]);
            } catch (Throwable $e) {
                $lastError = $e;

                continue;
            }

            // Asked under the wrong authority, SSL2BUY may still answer
            // "success" with an empty status; the product name tells.
            $named = self::familyForName((string) ($details['BaseOrderDetails']['ProductName'] ?? ''));
            if ($named !== null && $named !== $family) {
                return [$named, $this->call(self::FAMILIES[$named]['details'], ['OrderNumber' => (int) $order->remote_id])];
            }
            if ($known || trim((string) ($details['OrderStatus'] ?? '')) !== '') {
                return [$family, $details];
            }
            $lastError = new RuntimeException('order not found');
        }

        throw $lastError ?? new RuntimeException('order not found');
    }

    /** @return array<string, string|null> cert, ca_cert and fullchain, or nothing when none is issued */
    private function downloadCertificate(string $family, string $remoteId): array
    {
        $spec = self::FAMILIES[$family];
        if ($spec['certificate'] === null) {
            return [];
        }

        $response = $this->call($spec['certificate'], ['OrderNumber' => (int) $remoteId]);
        $cert = trim((string) ($response[$spec['cert']] ?? ''));
        if ($cert === '') {
            return [];
        }

        $chain = implode("\n", array_filter(array_map(fn (string $field) => trim((string) ($response[$field] ?? '')), $spec['chain'])));

        return [
            'cert' => $cert,
            'ca_cert' => $chain !== '' ? $chain : null,
            'fullchain' => $chain !== '' ? $cert."\n".$chain : $cert,
        ];
    }

    /** @return array<string, string> the one contact block the Sectigo DV products take */
    private function sectigoContact(SslOrder $order): array
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

    /** The panel's EMAIL / HTTP / DNS as the product's own method name. */
    private function approvalMethod(SslOrder $order, string $method, ?int $code = null): string
    {
        $code ??= $this->productCodeFor($order);
        $methods = $code !== null ? self::PRODUCTS[$code]['methods'] : ['EMAIL' => 'EMAIL', 'DNS' => 'CNAMECSRHASH', 'HTTP' => 'HTTPCSRHASH'];

        $panelMethod = match (strtoupper(trim($method))) {
            'HTTP', 'HTTPS', 'URL', 'HTTPCSRHASH', 'HTTPSCSRHASH' => 'HTTP',
            'DNS', 'CNAME', 'CNAMECSRHASH' => 'DNS',
            default => 'EMAIL',
        };

        if (! isset($methods[$panelMethod])) {
            throw new RuntimeException('this certificate cannot be validated by HTTP file (a wildcard never can): use email or DNS');
        }

        return $methods[$panelMethod];
    }

    /** Reissue and validation changes exist in SSL2BUY's API for Sectigo orders only. */
    private function requireComodo(SslOrder $order, string $what): void
    {
        $family = $this->familyFor($order);
        if ($family !== null && $family !== 'comodo') {
            throw new RuntimeException("SSL2BUY cannot {$what} this certificate through its API: use the SSL2BUY panel");
        }
    }

    /** config_data with the validation the domain owner has to publish now. */
    private function withDcv(SslOrder $order, string $family, string $csr, string $method, array $response): array
    {
        $data = (array) ($order->config_data ?? []);
        $domain = (string) ($response['DomainName'] ?? $order->domain);

        $dcv = $family === 'globalsign'
            ? $this->globalSignDcv($response, $domain)
            : self::dcvInstructions($csr, $method, $domain, $response['UniqueValue'] ?? null);

        if ($dcv) {
            $data['dcv'] = $dcv;
        } else {
            unset($data['dcv']);
        }

        return $data;
    }

    /**
     * GlobalSign's own validation values: a TXT record, or a file under
     * /.well-known/pki-validation/gsdv.txt holding the meta tag.
     *
     * @param  array<string, mixed>  $response
     * @return array{method: string, url?: string, content?: string, name?: string, type?: string, value?: string}|null
     */
    private function globalSignDcv(array $response, string $domain): ?array
    {
        $dnsText = trim((string) ($response['DNSText'] ?? ''));
        if ($dnsText !== '') {
            return [
                'method' => 'DNS',
                'name' => $this->baseDomain((string) (((array) ($response['DNSVerificationDomains'] ?? []))[0] ?? $domain)),
                'type' => 'TXT',
                'value' => $dnsText,
            ];
        }

        $metaTag = trim((string) ($response['URLMetaTag'] ?? ''));
        if ($metaTag !== '') {
            $host = preg_replace('#^https?://#', '', (string) (((array) ($response['URLVerificationDomains'] ?? []))[0] ?? $domain));

            return [
                'method' => 'HTTP',
                'url' => 'http://'.rtrim($this->baseDomain((string) $host), '/').'/.well-known/pki-validation/gsdv.txt',
                'content' => $metaTag,
            ];
        }

        return null;
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
