<?php

namespace Modules\Addons\Invoicetronic;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Invoicetronic's API (api.invoicetronic.com/v1, 2026-11): an intermediary
 * that hands FatturaPA files to the SDI and reports what the SDI did.
 *
 * The API key is the Basic auth user name with an empty password; a key
 * starting ik_test_ works against the sandbox, ik_live_ against the SDI.
 */
class InvoicetronicClient
{
    public const BASE_URL = 'https://api.invoicetronic.com/v1';

    public function __construct(private string $apiKey) {}

    /**
     * Posts a FatturaPA file for the SDI. Invoicetronic checks it first and
     * refuses it with the reasons when it would not pass the SDI's checks. A
     * retry with the same idempotency key returns the first answer instead
     * of sending the invoice twice.
     *
     * @return array{id: string, file_name: ?string, identifier: ?string, state: ?string}
     *
     * @throws InvoicetronicRefused when Invoicetronic refuses the file
     * @throws InvoicetronicUnavailable when it could not be reached or failed on its side
     */
    public function send(string $xml, string $idempotencyKey): array
    {
        $response = $this->call(fn () => $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->withBody($xml, 'application/xml')
            ->post('send/xml?validate=true'));

        if ($response->clientError()) {
            throw new InvoicetronicRefused($this->problem($response));
        }

        $this->failUnlessOk($response);

        return $this->sentRow((array) $response->json());
    }

    /**
     * Where the SDI is with each of the given sent invoices.
     *
     * @param  list<string>  $ids  at most 100
     * @return array<string, array{id: string, file_name: ?string, identifier: ?string, state: ?string}> by id
     *
     * @throws InvoicetronicUnavailable
     */
    public function sent(array $ids): array
    {
        $response = $this->call(fn () => $this->request()->get('send', [
            'ids' => implode(',', $ids),
            'page_size' => 100,
        ]));
        $this->failUnlessOk($response);

        $rows = [];
        foreach ((array) $response->json() as $row) {
            if (is_array($row) && isset($row['id'])) {
                $sent = $this->sentRow($row);
                $rows[$sent['id']] = $sent;
            }
        }

        return $rows;
    }

    /**
     * What the SDI said about one sent invoice, latest first: its state and
     * the reasons it gave, as one line for staff to read.
     *
     * @throws InvoicetronicUnavailable
     */
    public function latestNote(string $id): ?string
    {
        $response = $this->call(fn () => $this->request()->get('update', [
            'send_id' => $id,
            'sort' => '-last_update',
            'page_size' => 1,
        ]));
        $this->failUnlessOk($response);

        $update = ((array) $response->json())[0] ?? null;
        if (! is_array($update)) {
            return null;
        }

        $description = trim((string) ($update['description'] ?? ''));
        $errors = (array) ($update['errors'] ?? []);
        // The SDI often repeats an error as the description; say it once, with its code.
        $repeated = in_array($description, array_map(fn ($error) => trim((string) ($error['description'] ?? '')), $errors), true);
        $parts = array_filter([$repeated ? '' : $description]);
        foreach ($errors as $error) {
            $parts[] = trim(implode(' ', array_filter([
                $error['code'] ?? null,
                $error['description'] ?? null,
                isset($error['hint']) && $error['hint'] !== '' ? '('.$error['hint'].')' : null,
            ])));
        }

        return $parts !== [] ? implode(' | ', array_unique($parts)) : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, file_name: ?string, identifier: ?string, state: ?string}
     */
    private function sentRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'file_name' => isset($row['file_name']) ? (string) $row['file_name'] : null,
            'identifier' => isset($row['identifier']) ? (string) $row['identifier'] : null,
            'state' => isset($row['latest_state']) ? (string) $row['latest_state'] : null,
        ];
    }

    /** The refusal as one line: the summary, then each check that failed. */
    private function problem(Response $response): string
    {
        $parts = [];
        foreach ((array) $response->json('validation_errors', []) as $error) {
            $field = isset($error['property_name']) ? $error['property_name'].': ' : '';
            $parts[] = $field.mb_strimwidth((string) ($error['description'] ?? ''), 0, 300, '...');
        }

        if ($parts === []) {
            $parts[] = (string) ($response->json('detail') ?? $response->json('title') ?? 'HTTP '.$response->status());
        }

        return implode(' | ', $parts);
    }

    /**
     * @param  callable(): Response  $call
     *
     * @throws InvoicetronicUnavailable
     */
    private function call(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new InvoicetronicUnavailable('Invoicetronic: '.$e->getMessage(), 0, $e);
        }

        if ($response->serverError() || in_array($response->status(), [401, 403, 429], true)) {
            throw new InvoicetronicUnavailable('Invoicetronic: HTTP '.$response->status().' '.($response->json('detail') ?? $response->json('title') ?? ''));
        }

        return $response;
    }

    private function failUnlessOk(Response $response): void
    {
        if (! $response->successful()) {
            throw new InvoicetronicUnavailable('Invoicetronic: HTTP '.$response->status().' '.($response->json('detail') ?? ''));
        }
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withBasicAuth($this->apiKey, '')
            ->acceptJson()
            ->timeout(30);
    }
}
