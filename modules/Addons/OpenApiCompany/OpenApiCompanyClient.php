<?php

namespace Modules\Addons\OpenApiCompany;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * OpenAPI's Company service (company.openapi.com, 2026-11): the Italian
 * business register by VAT number, with a bearer token. Every call is paid
 * for, so each one is recorded.
 */
class OpenApiCompanyClient
{
    public function __construct(private string $token, private string $baseUrl) {}

    /**
     * Name, address, activity status and SDI code; null when the register
     * does not know the VAT number.
     *
     * @return array<string, mixed>|null
     *
     * @throws OpenApiCompanyUnavailable
     */
    public function start(string $vatCode, string $purpose): ?array
    {
        return $this->first($this->get('IT-start', $vatCode, $purpose));
    }

    /**
     * The company's current PEC address, or null when it has none.
     *
     * @throws OpenApiCompanyUnavailable
     */
    public function pec(string $vatCode, string $purpose): ?string
    {
        $pec = strtolower(trim((string) ($this->first($this->get('IT-pec', $vatCode, $purpose))['pec'] ?? '')));

        return filter_var($pec, FILTER_VALIDATE_EMAIL) ? $pec : null;
    }

    private function get(string $endpoint, string $vatCode, string $purpose): Response
    {
        try {
            $response = Http::withToken($this->token)->acceptJson()->timeout(20)->retry(2, 500, fn (\Throwable $e) => $e instanceof ConnectionException, throw: false)
                ->get(rtrim($this->baseUrl, '/').'/'.$endpoint.'/'.rawurlencode($vatCode));
        } catch (ConnectionException $e) {
            throw new OpenApiCompanyUnavailable(__('messages.openapi_company.unreachable'), 0, $e);
        }

        OpenApiCompanyCall::create(['endpoint' => $endpoint, 'vat_code' => $vatCode, 'status' => $response->status(), 'purpose' => $purpose]);

        if (in_array($response->status(), [401, 402, 403], true)) {
            throw new OpenApiCompanyUnavailable(__('messages.openapi_company.refused', ['status' => $response->status()]));
        }
        if ($response->serverError() || ($response->failed() && $response->status() !== 404)) {
            throw new OpenApiCompanyUnavailable(__('messages.openapi_company.failed', ['status' => $response->status()]));
        }

        return $response;
    }

    /** @return array<string, mixed>|null */
    private function first(Response $response): ?array
    {
        if ($response->status() === 404) {
            return null;
        }
        $row = ((array) $response->json('data'))[0] ?? null;

        return is_array($row) ? $row : null;
    }
}
