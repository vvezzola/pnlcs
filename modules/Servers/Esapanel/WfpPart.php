<?php

namespace Modules\Servers\Esapanel;

/**
 * WFP, the off-site copies (sold as EsaBackup): what PNLCS reads and does on
 * a subscription of this module, through Esapanel's /wfp API.
 *
 * Read: the space (used, limit, read only past it), the connection data and
 * the accounts with their last upload. Done for the customer: a new account,
 * a new password, suspend and resume. A password is in the answer that makes
 * it and nowhere else: it is shown once and never stored here.
 */
final class WfpPart
{
    public const MODULE = 'wfp';

    public const KINDS = ['device', 'person'];

    public const PROTOCOLS = ['rsync', 'sftp', 'ftps', 'webdav'];

    /** Esapanel's rule for an account name (WfpAccount::NAME_PATTERN there). */
    public const NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,24}[a-z0-9])?$/';

    public function __construct(private EsapanelClient $api) {}

    /**
     * The space and the accounts, as the screens keep them; null with the
     * reason when the server did not answer.
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    public function read(int $subscription): array
    {
        $storage = $this->api->get('subscriptions/'.$subscription.'/wfp/storage');
        if (! $storage->ok || $storage->data === null) {
            return [null, $storage->error];
        }

        $accounts = $this->api->get('subscriptions/'.$subscription.'/wfp/accounts', ['per_page' => 100]);
        if (! $accounts->ok) {
            return [null, $accounts->error];
        }

        $server = (array) ($storage->data['server'] ?? []);

        return [[
            'code' => $storage->data['code'] ?? null,
            'status' => $storage->data['status'] ?? null,
            'read_only' => (bool) ($storage->data['read_only'] ?? false),
            'space_mb' => $storage->data['space_mb'] ?? null,
            'used_mb' => $storage->data['used_mb'] ?? null,
            'measured_at' => $storage->data['measured_at'] ?? null,
            'accounts_limit' => $storage->data['accounts_limit'] ?? null,
            'server' => [
                'host' => $server['host'] ?? null,
                'rsync_port' => $server['rsync_port'] ?? null,
                'sftp_port' => $server['sftp_port'] ?? null,
                'ftps_port' => $server['ftps_port'] ?? null,
                'webdav_port' => $server['webdav_port'] ?? null,
            ],
            'accounts' => array_values(array_map(fn (array $account) => [
                'id' => (int) ($account['id'] ?? 0),
                'name' => (string) ($account['name'] ?? ''),
                'username' => (string) ($account['username'] ?? ''),
                'description' => $account['description'] ?? null,
                'kind' => $account['kind'] ?? null,
                'protocols' => array_values((array) ($account['protocols'] ?? [])),
                'status' => $account['status'] ?? null,
                'last_upload_at' => $account['last_upload_at'] ?? null,
                'late' => (bool) ($account['late'] ?? false),
            ], array_filter((array) $accounts->data, 'is_array'))),
            'read_at' => now()->toIso8601String(),
        ], ''];
    }

    /**
     * A new account. The answer carries its password, once.
     *
     * @param  array{name: string, kind: string, protocols: list<string>, description?: ?string}  $account
     */
    public function createAccount(int $subscription, array $account): EsapanelResponse
    {
        return $this->api->post('subscriptions/'.$subscription.'/wfp/accounts', array_filter([
            'name' => $account['name'],
            'kind' => $account['kind'],
            'protocols' => $account['protocols'],
            'description' => $account['description'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * Whether the account is one of this subscription's. The API key reaches
     * every customer of the seller, so an id from a form proves nothing until
     * the server says whose it is.
     */
    public function belongs(int $subscription, int $account): bool
    {
        $found = $this->api->get('wfp/accounts/'.$account);

        return $found->ok && (int) ($found->data['subscription_id'] ?? 0) === $subscription;
    }

    /** A new password for the account; the answer carries it, once. */
    public function newPassword(int $account): EsapanelResponse
    {
        return $this->api->post('wfp/accounts/'.$account.'/password');
    }

    public function suspend(int $account): EsapanelResponse
    {
        return $this->api->post('wfp/accounts/'.$account.'/suspend');
    }

    public function resume(int $account): EsapanelResponse
    {
        return $this->api->post('wfp/accounts/'.$account.'/resume');
    }
}
