<?php

namespace Modules\Addons\BankReconciliation;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads a Qonto business account through its Business API with an API key:
 * the organization's bank accounts and the incoming transfers on one of
 * them. Read-only - an API key cannot move money.
 *
 * docs.qonto.com (2026-11): the key goes in "Authorization: <login>:<secret>"
 * (no Bearer, no Base64); the sandbox also wants X-Qonto-Staging-Token.
 */
class QontoClient
{
    public const PRODUCTION = 'https://thirdparty.qonto.com';

    public const SANDBOX = 'https://thirdparty-sandbox.staging.qonto.co';

    /** page x per_page may not pass 10,000 on Qonto's side. */
    private const MAX_PAGES = 100;

    /** @param array<string, string> $settings as BankReconciliationSettings::resolve() returns them */
    public function __construct(private array $settings) {}

    /**
     * The organization's bank accounts.
     *
     * @return list<array{id: string, iban: string, name: string, balance: float, status: string}>
     */
    public function bankAccounts(): array
    {
        $accounts = $this->get('/v2/organization')['organization']['bank_accounts'] ?? [];

        return array_values(array_map(fn (array $account): array => [
            'id' => (string) ($account['id'] ?? ''),
            'iban' => (string) ($account['iban'] ?? ''),
            'name' => (string) ($account['name'] ?? ''),
            'balance' => (float) ($account['balance'] ?? 0),
            'status' => (string) ($account['status'] ?? ''),
        ], is_array($accounts) ? $accounts : []));
    }

    /**
     * Completed incoming transactions on the account, oldest first, settled
     * from the given moment on.
     *
     * @return list<array{id: string, booked_at: ?string, amount: float, currency: string, counterparty: ?string, counterparty_iban: ?string, reference: string}>
     */
    public function incomingSince(string $iban, CarbonInterface $since): array
    {
        $transactions = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $data = $this->get('/v2/transactions', [
                'iban' => $iban,
                'side' => 'credit',
                'settled_at_from' => $since->copy()->utc()->toIso8601ZuluString(),
                'sort_by' => 'settled_at:asc',
                'per_page' => 100,
                'page' => $page,
            ]);

            foreach ($data['transactions'] ?? [] as $row) {
                if (($row['side'] ?? 'credit') !== 'credit' || ($row['status'] ?? 'completed') !== 'completed') {
                    continue;
                }
                $transactions[] = $this->transaction($row);
            }

            if (empty($data['meta']['next_page'])) {
                break;
            }
        }

        return $transactions;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, booked_at: ?string, amount: float, currency: string, counterparty: ?string, counterparty_iban: ?string, reference: string}
     */
    private function transaction(array $row): array
    {
        // The remittance text of a transfer: Qonto's docs do not name the
        // field, so every free-text field the transaction carries is kept
        // and searched.
        $texts = array_filter([$row['reference'] ?? null, $row['note'] ?? null, $row['label'] ?? null], fn ($text) => is_string($text) && trim($text) !== '');

        $iban = null;
        foreach (['income', 'transfer', 'swift_income'] as $subject) {
            if (! empty($row[$subject]['counterparty_account_number'])) {
                $iban = (string) $row[$subject]['counterparty_account_number'];
                break;
            }
        }

        return [
            'id' => (string) ($row['id'] ?? ''),
            'booked_at' => $row['settled_at'] ?? $row['emitted_at'] ?? null,
            'amount' => isset($row['amount_cents']) ? ((int) $row['amount_cents']) / 100 : (float) ($row['amount'] ?? 0),
            'currency' => strtoupper((string) ($row['currency'] ?? 'EUR')) === 'EUROS' ? 'EUR' : strtoupper(substr((string) ($row['currency'] ?? 'EUR'), 0, 3)),
            'counterparty' => isset($row['clean_counterparty_name']) || isset($row['label']) ? (string) ($row['clean_counterparty_name'] ?? $row['label']) : null,
            'counterparty_iban' => $iban,
            'reference' => implode(' | ', array_unique(array_map('trim', $texts))),
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $response = $this->request()->get($path, $query);

        if (! $response->successful()) {
            $message = $response->json('errors.0.detail') ?? $response->json('message') ?? ('HTTP '.$response->status());

            throw new RuntimeException('Qonto: '.$message);
        }

        return (array) $response->json();
    }

    private function request(): PendingRequest
    {
        $sandbox = ($this->settings['sandbox'] ?? '0') === '1';
        $headers = ['Authorization' => trim((string) ($this->settings['qonto_login'] ?? '')).':'.trim((string) ($this->settings['qonto_secret_key'] ?? ''))];
        if ($sandbox) {
            $headers['X-Qonto-Staging-Token'] = trim((string) ($this->settings['staging_token'] ?? ''));
        }

        return Http::baseUrl($sandbox ? self::SANDBOX : self::PRODUCTION)
            ->withHeaders($headers)
            ->acceptJson()
            ->timeout(20);
    }
}
