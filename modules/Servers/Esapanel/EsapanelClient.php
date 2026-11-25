<?php

namespace Modules\Servers\Esapanel;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * An Esapanel server's API (/api/v1), signed in with the key of its API
 * account for this site.
 *
 * The key never reaches a log line or a message: errors carry the server's
 * own words and the HTTP status only.
 */
final class EsapanelClient
{
    public function __construct(private string $baseUrl, private string $apiKey, private bool $verifyTls = true) {}

    /** @param  array<string, mixed>  $query */
    public function get(string $path, array $query = []): EsapanelResponse
    {
        return $this->send('GET', $path, $query);
    }

    /**
     * The key is given a digest of the body. The server keeps every
     * answer below 500 for a day and repeats it to the same key and body, and
     * refuses the same key with another body: without the digest, a request
     * refused for a wrong value could not be sent again once the value was
     * put right. The same request sent twice still carries the same key.
     *
     * @param  array<string, mixed>  $body
     */
    public function post(string $path, array $body = [], ?string $idempotencyKey = null): EsapanelResponse
    {
        if ($idempotencyKey !== null) {
            $idempotencyKey .= '-'.substr(hash('sha256', (string) json_encode($body)), 0, 16);
        }

        return $this->send('POST', $path, $body, $idempotencyKey);
    }

    /** @param  array<string, mixed>  $body */
    public function put(string $path, array $body = []): EsapanelResponse
    {
        return $this->send('PUT', $path, $body);
    }

    public function delete(string $path): EsapanelResponse
    {
        return $this->send('DELETE', $path);
    }

    private function request(): PendingRequest
    {
        $request = Http::withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->withUserAgent('PNLCS')
            // Provisioning runs inside the request that took the customer's
            // payment; a slow server must not hold that page for long.
            ->timeout(15)
            ->connectTimeout(8);

        return $this->verifyTls ? $request : $request->withoutVerifying();
    }

    /** @param  array<string, mixed>  $payload */
    private function send(string $method, string $path, array $payload = [], ?string $idempotencyKey = null): EsapanelResponse
    {
        if ($this->apiKey === '') {
            return new EsapanelResponse(false, 0, error: __('esapanel::messages.error.no_key'));
        }

        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');

        try {
            $request = $this->request();
            if ($idempotencyKey !== null) {
                $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
            }

            $response = match ($method) {
                'POST' => $request->post($url, $payload),
                'PUT' => $request->put($url, $payload),
                'DELETE' => $request->delete($url),
                default => $request->get($url, $payload),
            };
        } catch (\Throwable $e) {
            Log::warning('Esapanel: the server could not be reached', ['path' => $path, 'error' => $e->getMessage()]);

            return new EsapanelResponse(false, 0, error: __('esapanel::messages.error.unreachable'));
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        if ($response->successful()) {
            return new EsapanelResponse(true, $response->status(), is_array($json['data'] ?? null) ? $json['data'] : null, '', (array) ($json['meta'] ?? []));
        }

        $message = match ($response->status()) {
            401 => __('esapanel::messages.error.key_refused'),
            // Also a panel without a valid licence: its words tell which.
            403 => trim(__('esapanel::messages.error.not_allowed').' '.self::words($json)),
            429 => __('esapanel::messages.error.too_many'),
            default => self::words($json),
        };

        Log::info('Esapanel: the server refused', ['path' => $path, 'status' => $response->status(), 'error' => $message]);

        return new EsapanelResponse(false, $response->status(), null, $message !== '' ? $message : 'HTTP '.$response->status());
    }

    /**
     * What the server said: its validation messages by field, or its message.
     *
     * @param  array<string, mixed>  $json
     */
    private static function words(array $json): string
    {
        $lines = [];
        foreach ((array) ($json['errors'] ?? []) as $field => $messages) {
            foreach ((array) $messages as $message) {
                $lines[] = $field.': '.(string) $message;
            }
        }

        return $lines !== [] ? implode(' ', $lines) : (string) ($json['message'] ?? '');
    }
}
