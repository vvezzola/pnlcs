<?php

namespace Modules\Servers\ArubaBusiness;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The Aruba Business API (api.arubabusiness.it), read only.
 *
 * Signed in as a delegated user of the account: a password grant at
 * /auth/token with the account's Authorization-Key, then the bearer token and
 * the key on every call. The token lasts a day and is kept for most of it.
 *
 * Only GET is spoken here. Ordering, renewing and cancelling exist in the API
 * (carts) but are not documented outside the Aruba panel, and each of them
 * spends the account's money or ends a customer's mailbox.
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
        $response = $this->send($path, $this->token());

        // A token Aruba ended early: sign in once more, then give up.
        if ($response->status() === 401) {
            $response = $this->send($path, $this->token(fresh: true));
        }

        if (! $response->successful() || ! is_array($response->json())) {
            Log::info('Aruba Business: refused', ['path' => $path, 'status' => $response->status()]);

            throw new RuntimeException(__('aruba-business::messages.error.refused', ['status' => $response->status()]));
        }

        return $response->json();
    }

    private function send(string $path, string $token): Response
    {
        try {
            return Http::withToken($token)
                ->withHeaders(['Authorization-Key' => $this->key])
                ->acceptJson()
                ->timeout(40)
                ->connectTimeout(10)
                ->get($this->baseUrl.'/'.ltrim($path, '/'));
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
