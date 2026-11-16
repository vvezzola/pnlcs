<?php

namespace Modules\Servers\ArubaBusiness;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The Aruba Business API (api.arubabusiness.it); its description is public at
 * /docs on the same host.
 *
 * Signed in as a delegated user of the account: a password grant at
 * /auth/token with the account's Authorization-Key, then the bearer token and
 * the key on every call. The token lasts a day and is kept for most of it.
 *
 * It reads the account's services, and changes two things that cost nothing:
 * whether Aruba renews a service by itself, and a cancellation on a given day.
 * Ordering, renewing and paying exist in the API too and are left out: each
 * spends the account's money.
 *
 * The key, the password and the token never reach a log line or a message.
 */
final class ArubaBusinessClient
{
    public function __construct(
        private string $baseUrl,
        private string $key,
        private string $username,
        private string $password,
    ) {}

    /**
     * Every service of the account, as Aruba lists them.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException with words fit for the operator
     */
    public function services(): array
    {
        $response = $this->get('api/services');

        return array_values(array_filter($response, 'is_array'));
    }

    /**
     * Turn a service's automatic renewal on or off.
     *
     * @throws RuntimeException with words fit for the operator
     */
    public function setAutomaticRenewal(int $serviceId, bool $on): void
    {
        $this->put('api/services/autorenew', ['ServiceId' => $serviceId, 'AutomaticRenewal' => $on]);
    }

    /**
     * Cancel a service on a day (Y-m-d). It stays in use until then.
     *
     * @throws RuntimeException with words fit for the operator
     */
    public function unsubscribe(int $serviceId, string $day): void
    {
        $this->put('api/services/unsubscribe', ['IdService' => $serviceId, 'UnsubscribeDate' => $day.'T00:00:00']);
    }

    /** Whether the account signs in. */
    public function signsIn(): bool
    {
        try {
            return $this->token(fresh: true) !== '';
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    private function get(string $path): array
    {
        $response = $this->answered('get', $path);

        if (! is_array($response->json())) {
            Log::info('Aruba Business: refused', ['path' => $path, 'status' => $response->status()]);

            throw new RuntimeException(__('aruba-business::messages.error.refused', ['status' => $response->status()]));
        }

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function put(string $path, array $body): void
    {
        $this->answered('put', $path, $body);
    }

    /**
     * A call Aruba accepted.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws RuntimeException when Aruba is not reached or says no
     */
    private function answered(string $method, string $path, array $body = []): Response
    {
        $response = $this->send($method, $path, $this->token(), $body);

        // A token Aruba ended early: sign in once more, then give up.
        if ($response->status() === 401) {
            $response = $this->send($method, $path, $this->token(fresh: true), $body);
        }

        if (! $response->successful()) {
            Log::info('Aruba Business: refused', ['method' => $method, 'path' => $path, 'status' => $response->status()]);

            throw new RuntimeException(__('aruba-business::messages.error.'.(in_array($response->status(), [401, 403], true) ? 'not_allowed' : 'refused'), [
                'status' => $response->status(),
            ]));
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(string $method, string $path, string $token, array $body = []): Response
    {
        try {
            $request = Http::withToken($token)
                ->withHeaders(['Authorization-Key' => $this->key])
                ->acceptJson()
                ->timeout(40)
                ->connectTimeout(10);
            $url = $this->baseUrl.'/'.ltrim($path, '/');

            return $method === 'put' ? $request->put($url, $body) : $request->get($url);
        } catch (\Throwable $e) {
            Log::warning('Aruba Business: not reached', ['path' => $path, 'error' => $e->getMessage()]);

            throw new RuntimeException(__('aruba-business::messages.error.unreachable'));
        }
    }

    private function token(bool $fresh = false): string
    {
        $cacheKey = 'aruba_business:token:'.hash('sha256', $this->baseUrl.'|'.$this->key.'|'.$this->username.'|'.$this->password);

        if (! $fresh && is_string($cached = Cache::get($cacheKey)) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::asForm()
                ->withHeaders(['Authorization-Key' => $this->key])
                ->acceptJson()
                ->timeout(30)
                ->connectTimeout(10)
                ->post($this->baseUrl.'/auth/token', [
                    'grant_type' => 'password',
                    'username' => $this->username,
                    'password' => $this->password,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Aruba Business: sign-in not reached', ['error' => $e->getMessage()]);

            throw new RuntimeException(__('aruba-business::messages.error.unreachable'));
        }

        $token = (string) ($response->json('access_token') ?? '');
        if (! $response->successful() || $token === '') {
            Log::info('Aruba Business: sign-in refused', ['status' => $response->status()]);

            throw new RuntimeException(__('aruba-business::messages.error.sign_in', ['status' => $response->status()]));
        }

        // Kept a little short of what Aruba grants, and never past a day.
        $seconds = max(60, min(86400, (int) ($response->json('expires_in') ?? 3600)) - 300);
        Cache::put($cacheKey, $token, now()->addSeconds($seconds));

        return $token;
    }
}
