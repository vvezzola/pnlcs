<?php

namespace Modules\Servers\ArubaBusiness;

use App\Models\Server;
use App\Models\Service;
use App\Services\NotificationService;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Servers\AbstractServerModule;
use RuntimeException;

/**
 * PEC mailboxes held in an Aruba Business account. Esalogica's own; kept out
 * of upstream PNLCS.
 *
 * The account is a server record (Setup -> Servers) of this type: hostname
 * api.arubabusiness.it, the delegated user's name and password, and the
 * account's Authorization-Key in the API key field.
 *
 * WHAT IT READS. The mailboxes and the certified domains of the account are
 * listed for the server sync page, so each becomes a billing service of its
 * customer; once an hour they are read back, and the service page shows what
 * Aruba says: state, expiry, automatic renewal, renewal price.
 *
 * WHAT IT CHANGES. Two things, neither of which costs money. Automatic
 * renewal: turned off when a service is terminated here, so Aruba stops
 * charging for a mailbox nobody is billed for, and switched either way by a
 * staff button. Cancellation: only by a staff button pressed twice, and always
 * on the day the service expires at Aruba, never at once.
 *
 * WHAT IT DOES NOT DO. Ordering, renewing and paying: each spends the
 * account's money. Aruba renews by itself while automatic renewal is on. A
 * mailbox's password and its suspension are not in Aruba's API at all.
 */
class ArubaBusinessModule extends AbstractServerModule
{
    public const NAME = 'arubabusiness';

    private const DEFAULT_HOST = 'api.arubabusiness.it';

    /** The articles this module manages, as Aruba names them; a product's package is one of these. */
    public const ARTICLES = ['PEC Standard', 'PEC Pro', 'Certificazione Dominio esterno'];

    /** A cancelled service is history, not something to link or to watch. */
    private const GONE = 'Canceled';

    /** How long the first press of "cancel at Aruba" waits for the second. */
    private const CONFIRM_MINUTES = 10;

    /**
     * The module's screens and words live beside its code; named here and not
     * by a service provider, so the module adds nothing to what the site boots.
     */
    public function __construct()
    {
        view()->addNamespace('aruba-business', __DIR__.'/views');
        app('translator')->addNamespace('aruba-business', __DIR__.'/lang');
    }

    public function getModuleName(): string
    {
        return self::NAME;
    }

    public function getConfigFields(): array
    {
        return [];
    }

    // ------------------------------------------------------------------
    // Lifecycle: billing, and the automatic renewal when a service ends
    // ------------------------------------------------------------------

    /** A mailbox is made in the Aruba panel and then linked; Create only confirms a link. */
    public function create(Service $service): array
    {
        if ($this->linkedPanelAccount($service) !== '') {
            return $this->buildResult(true, __('aruba-business::messages.result.linked'));
        }

        return $this->buildResult(false, __('aruba-business::messages.error.create_by_hand'));
    }

    public function suspend(Service $service, string $reason = ''): array
    {
        return $this->buildResult(true, __('aruba-business::messages.result.billing_only'));
    }

    public function unsuspend(Service $service): array
    {
        return $this->buildResult(true, __('aruba-business::messages.result.billing_only'));
    }

    /**
     * The service ends here, so Aruba is told to stop renewing it; the mailbox
     * stays in use until it expires there. When Aruba cannot be told, the
     * service still ends here and staff are asked to do it by hand.
     */
    public function terminate(Service $service): array
    {
        if ($this->linkedPanelAccount($service) === '') {
            return $this->buildResult(true, __('aruba-business::messages.result.nothing_linked'));
        }

        $read = $this->refresh($service);
        $data = $this->getModuleData($service);

        if ($read['success'] && (($data['aruba_state'] ?? '') === self::GONE || empty($data['aruba_autorenew']))) {
            $this->setModuleData($service, ['ended' => true, 'todo' => null]);

            return $this->buildResult(true, __('aruba-business::messages.result.ended'));
        }

        if ($read['success'] && $this->setRenewal($service, false)['success']) {
            $this->setModuleData($service, ['ended' => true, 'todo' => null]);
            $result = $this->buildResult(true, __('aruba-business::messages.result.ended_renewal_off', [
                'account' => (string) ($data['aruba_account'] ?? $service->domain),
                'date' => self::shown($data['aruba_expires'] ?? null),
            ]));
            $this->logAction($service, 'terminate', $result);

            return $result;
        }

        $message = __('aruba-business::messages.todo.autorenew_off', [
            'account' => (string) ($data['aruba_account'] ?? $service->domain),
            'date' => self::shown($data['aruba_expires'] ?? null),
        ]);
        $this->setModuleData($service, ['ended' => true, 'todo' => 'autorenew_off']);
        $this->tellStaff($service, $message);

        $result = $this->buildResult(true, $message);
        $this->logAction($service, 'terminate', $result);

        return $result;
    }

    public function changePassword(Service $service, string $newPassword): array
    {
        return $this->buildResult(false, __('aruba-business::messages.error.password_by_hand'));
    }

    public function changePackage(Service $service, array $newPackage): array
    {
        return $this->buildResult(false, __('aruba-business::messages.error.package_by_hand'));
    }

    /** Aruba renews by itself; a paid renewal here only reads the new expiry back. */
    public function renew(Service $service): array
    {
        if ($this->linkedPanelAccount($service) === '') {
            return $this->buildResult(true, __('aruba-business::messages.result.nothing_linked'));
        }

        $read = $this->refresh($service);
        if (! $read['success']) {
            return $read;
        }

        $data = $this->getModuleData($service);

        return $this->buildResult(true, __('aruba-business::messages.result.renews_itself', [
            'date' => self::shown($data['aruba_expires'] ?? null),
            'autorenew' => __('aruba-business::messages.autorenew.'.(empty($data['aruba_autorenew']) ? 'off' : 'on')),
        ]));
    }

    // ------------------------------------------------------------------
    // The account's services: listing, linking, reading back
    // ------------------------------------------------------------------

    /**
     * For the "link existing account" picker of a service.
     *
     * @return list<array{id: string, username: string, email: string, status: string}>
     */
    public function listAccounts(Server $server): array
    {
        return array_map(fn (array $account): array => [
            'id' => $account['id'],
            'username' => $account['domain'].' · '.$account['plan'],
            'email' => $account['email'],
            'status' => $account['status'],
        ], $this->listAccountDetails($server));
    }

    /**
     * The mailboxes and certified domains of the account, for the server sync
     * page. The "domain" is the mailbox's address (the domain itself for a
     * certification), the plan is Aruba's article, and the email is the
     * address again, which finds the customer whose login is their PEC.
     *
     * @return list<array{id: string, domain: string, owner: string, email: string, status: string, plan: ?string, created: ?string}>
     */
    public function listAccountDetails(Server $server): array
    {
        try {
            $services = $this->managed($this->clientFor($server)->services());
        } catch (RuntimeException $e) {
            Log::warning('Aruba Business: the account could not be listed', ['server' => $server->id, 'error' => $e->getMessage()]);

            return [];
        }

        $accounts = array_map(fn (array $row): array => [
            'id' => (string) $row['Id'],
            'domain' => self::account($row),
            'owner' => '',
            'email' => str_contains(self::account($row), '@') ? self::account($row) : '',
            'status' => ($row['State'] ?? '') === 'Active' ? 'active' : 'suspended',
            'plan' => (string) ($row['Article'] ?? ''),
            'created' => self::day($row['ActivationDate'] ?? null),
        ], $services);

        usort($accounts, fn ($a, $b) => strcasecmp($a['domain'], $b['domain']));

        return $accounts;
    }

    /** The Aruba service a billing service manages, or '' for none. */
    public function linkedPanelAccount(Service $service): string
    {
        return (string) ($this->getModuleData($service)['aruba_service_id'] ?? '');
    }

    /** Tie a billing service to a service of the account. Nothing changes at Aruba. */
    public function linkAccount(Service $service, string $id): array
    {
        $server = $this->getServer($service);
        if (! $server) {
            return $this->buildResult(false, __('aruba-business::messages.error.no_server'));
        }

        try {
            $rows = $this->managed($this->clientFor($server)->services());
        } catch (RuntimeException $e) {
            return $this->buildResult(false, $e->getMessage());
        }

        foreach ($rows as $row) {
            if ((string) $row['Id'] === trim($id)) {
                $this->store($service, $row);
                $service->forceFill(['username' => self::account($row)])->save();

                return $this->buildResult(true, __('aruba-business::messages.result.linked'));
            }
        }

        return $this->buildResult(false, __('aruba-business::messages.error.not_found', ['id' => $id]));
    }

    /** Whether Aruba still has the linked service; null when it could not be asked. */
    public function accountOnServer(Service $service): ?bool
    {
        if ($this->linkedPanelAccount($service) === '') {
            return false;
        }

        return $this->refresh($service)['success'] ? ($this->getModuleData($service)['aruba_state'] ?? '') !== self::GONE : null;
    }

    /** Read one service back from Aruba. */
    public function refresh(Service $service): array
    {
        $id = $this->linkedPanelAccount($service);
        $server = $this->getServer($service);
        if ($id === '' || ! $server) {
            return $this->buildResult(false, __('aruba-business::messages.error.not_linked'));
        }

        try {
            $rows = $this->clientFor($server)->services();
        } catch (RuntimeException $e) {
            return $this->buildResult(false, $e->getMessage());
        }

        foreach ($rows as $row) {
            if ((string) ($row['Id'] ?? '') === $id) {
                $this->store($service, $row);

                return $this->buildResult(true, __('aruba-business::messages.result.refreshed'));
            }
        }

        return $this->buildResult(false, __('aruba-business::messages.error.not_found', ['id' => $id]));
    }

    public function checkNow(Service $service): array
    {
        return $this->refresh($service);
    }

    // ------------------------------------------------------------------
    // Staff buttons that change something at Aruba
    // ------------------------------------------------------------------

    public function autorenewOff(Service $service): array
    {
        return $this->setRenewal($service, false);
    }

    public function autorenewOn(Service $service): array
    {
        return $this->setRenewal($service, true);
    }

    /**
     * Cancel the linked service at Aruba on the day it expires there.
     *
     * The staff buttons of a service page ask no confirmation, and this one
     * ends a customer's mailbox: the first press only says what will happen,
     * and a second press within a few minutes does it.
     */
    public function cancelAtAruba(Service $service): array
    {
        $read = $this->refresh($service);
        if (! $read['success']) {
            return $read;
        }

        $data = $this->getModuleData($service);
        $day = $data['aruba_expires'] ?? null;
        if (($data['aruba_state'] ?? '') === self::GONE || ! empty($data['cancel_on'])) {
            return $this->buildResult(false, __('aruba-business::messages.error.already_cancelled'));
        }
        if (! is_string($day) || $day < now()->toDateString()) {
            return $this->buildResult(false, __('aruba-business::messages.error.no_expiry'));
        }

        $with = ['account' => (string) ($data['aruba_account'] ?? $service->domain), 'date' => self::shown($day)];

        if (! $this->cancelIsArmed($data)) {
            $this->setModuleData($service, ['cancel_armed_at' => now()->toIso8601String()]);

            return $this->buildResult(false, __('aruba-business::messages.confirm.cancel', $with + ['minutes' => self::CONFIRM_MINUTES]));
        }

        $this->setModuleData($service, ['cancel_armed_at' => null]);

        try {
            $this->clientFor($this->getServer($service))->unsubscribe((int) $data['aruba_service_id'], $day);
        } catch (RuntimeException $e) {
            $result = $this->buildResult(false, $e->getMessage());
            $this->logAction($service, 'cancelAtAruba', $result);

            return $result;
        }

        $this->setModuleData($service, ['cancel_on' => $day]);
        $this->refresh($service);

        $result = $this->buildResult(true, __('aruba-business::messages.result.cancelled', $with));
        $this->logAction($service, 'cancelAtAruba', $result);

        return $result;
    }

    /**
     * The hourly usage run: one call to Aruba, and every linked service of
     * this account is brought up to date. A service that ended here while
     * Aruba still renews it keeps its reminder; once Aruba agrees it is dropped.
     */
    public function usageUpdate(Server $server): array
    {
        try {
            $rows = collect($this->clientFor($server)->services())->keyBy(fn (array $row) => (string) ($row['Id'] ?? ''));
        } catch (RuntimeException $e) {
            Log::warning('Aruba Business: the account could not be read', ['server' => $server->id, 'error' => $e->getMessage()]);

            return ['updated' => 0, 'errors' => 1];
        }

        $done = ['updated' => 0, 'errors' => 0];
        $services = Service::where('server_id', $server->id)->whereNotNull('module_data->aruba_service_id')->get();

        foreach ($services as $service) {
            $row = $rows->get($this->linkedPanelAccount($service));
            if (! is_array($row)) {
                $done['errors']++;

                continue;
            }

            $this->store($service, $row);
            if (($this->getModuleData($service)['todo'] ?? null) === 'autorenew_off'
                && (($row['State'] ?? '') === self::GONE || empty($row['AutomaticRenewal']))) {
                $this->setModuleData($service, ['todo' => null]);
            }
            $done['updated']++;
        }

        return $done;
    }

    // ------------------------------------------------------------------
    // Screens and buttons (see App\Services\Module\ModuleScreens)
    // ------------------------------------------------------------------

    /** @return list<string> */
    public function servicePageHides(): array
    {
        return ['server_details', 'change_password'];
    }

    public function isProvisioned(Service $service): bool
    {
        return $this->linkedPanelAccount($service) !== '';
    }

    /**
     * Only what applies to the service is offered: the one renewal switch that
     * would change something, and the cancellation while there is one to ask.
     *
     * @return array<string, string> staff buttons, method => label
     */
    public function customFunctions(?Service $service = null): array
    {
        $buttons = ['checkNow' => __('aruba-business::messages.action.check_now')];
        $data = $service ? $this->getModuleData($service) : [];

        if (empty($data['aruba_service_id']) || ($data['aruba_state'] ?? '') === self::GONE) {
            return $buttons;
        }

        $buttons[empty($data['aruba_autorenew']) ? 'autorenewOn' : 'autorenewOff'] =
            __('aruba-business::messages.action.autorenew_'.(empty($data['aruba_autorenew']) ? 'on' : 'off'));

        if (empty($data['cancel_on'])) {
            $buttons['cancelAtAruba'] = __('aruba-business::messages.action.'.($this->cancelIsArmed($data) ? 'cancel_confirm' : 'cancel'));
        }

        return $buttons;
    }

    public function clientAreaPanel(Service $service): ?Htmlable
    {
        return view('aruba-business::client', $this->screenData($service));
    }

    public function adminAreaPanel(Service $service): ?Htmlable
    {
        return view('aruba-business::admin', $this->screenData($service));
    }

    /**
     * What both screens show, with what staff should look at.
     *
     * @return array<string, mixed>
     */
    public function screenData(Service $service): array
    {
        $data = $this->getModuleData($service);
        $linked = $this->linkedPanelAccount($service) !== '';
        $open = ! in_array(strtolower((string) $service->status), ['terminated', 'cancelled', 'fraud'], true);
        $expires = $data['aruba_expires'] ?? null;
        $state = (string) ($data['aruba_state'] ?? '');

        $warnings = [];
        if ($linked && $open && $state !== '' && $state !== 'Active') {
            $warnings[] = __('aruba-business::messages.warning.state', ['state' => $state]);
        }
        if ($linked && $open && $state === 'Active' && empty($data['aruba_autorenew'])) {
            $warnings[] = __('aruba-business::messages.warning.autorenew_off', ['date' => self::shown($expires)]);
        }
        if ($linked && $open && $expires && $service->next_due_date && $expires < $service->next_due_date->toDateString()
            && empty($data['aruba_autorenew'])) {
            $warnings[] = __('aruba-business::messages.warning.expires_first', ['date' => self::shown($expires)]);
        }
        if ($linked && ! empty($data['cancel_on']) && $state !== self::GONE) {
            $warnings[] = __('aruba-business::messages.warning.cancel_on', ['date' => self::shown($data['cancel_on'])]);
        }
        if (($data['todo'] ?? null) === 'autorenew_off') {
            $warnings[] = __('aruba-business::messages.todo.autorenew_off', [
                'account' => (string) ($data['aruba_account'] ?? $service->domain), 'date' => self::shown($expires),
            ]);
        }

        return [
            'service' => $service,
            'data' => $data,
            'linked' => $linked,
            'warnings' => $warnings,
            'isMailbox' => str_contains((string) ($data['aruba_account'] ?? ''), '@'),
        ];
    }

    // ------------------------------------------------------------------
    // The account (a server record of this type)
    // ------------------------------------------------------------------

    /**
     * The articles a product can be: Aruba's names for what this module manages.
     *
     * @return list<array{id: string, name: string}>
     */
    public function listPackages(?Server $server = null): array
    {
        return array_map(fn (string $article): array => ['id' => $article, 'name' => $article], self::ARTICLES);
    }

    public function testConnection(Server $server): bool
    {
        try {
            return $this->clientFor($server)->signsIn();
        } catch (RuntimeException) {
            return false;
        }
    }

    // ------------------------------------------------------------------

    /**
     * Turn the linked service's automatic renewal on or off, and believe it
     * only once Aruba's own list says so.
     */
    private function setRenewal(Service $service, bool $on): array
    {
        $id = $this->linkedPanelAccount($service);
        $server = $this->getServer($service);
        if ($id === '' || ! $server) {
            return $this->buildResult(false, __('aruba-business::messages.error.not_linked'));
        }

        try {
            $this->clientFor($server)->setAutomaticRenewal((int) $id, $on);
        } catch (RuntimeException $e) {
            $result = $this->buildResult(false, $e->getMessage());
            $this->logAction($service, $on ? 'autorenewOn' : 'autorenewOff', $result);

            return $result;
        }

        $read = $this->refresh($service);
        $data = $this->getModuleData($service);
        if (! $read['success'] || ! empty($data['aruba_autorenew']) !== $on) {
            $result = $this->buildResult(false, __('aruba-business::messages.error.not_applied'));
            $this->logAction($service, $on ? 'autorenewOn' : 'autorenewOff', $result);

            return $result;
        }

        if (! $on) {
            $this->setModuleData($service, ['todo' => null]);
        }

        $result = $this->buildResult(true, __('aruba-business::messages.result.autorenew_'.($on ? 'on' : 'off'), [
            'date' => self::shown($data['aruba_expires'] ?? null),
        ]));
        $this->logAction($service, $on ? 'autorenewOn' : 'autorenewOff', $result);

        return $result;
    }

    /** @param  array<string, mixed>  $data */
    private function cancelIsArmed(array $data): bool
    {
        $at = $data['cancel_armed_at'] ?? null;

        return is_string($at) && $at !== '' && Carbon::parse($at)->gt(now()->subMinutes(self::CONFIRM_MINUTES));
    }

    private function clientFor(Server $server): ArubaBusinessClient
    {
        $key = trim((string) $server->access_hash);
        $username = trim((string) $server->username);
        $password = (string) $server->password;

        if ($key === '' || $username === '' || $password === '') {
            throw new RuntimeException(__('aruba-business::messages.error.no_credentials'));
        }

        $host = trim((string) $server->hostname) ?: self::DEFAULT_HOST;

        return new ArubaBusinessClient('https://'.$host.((int) $server->port ? ':'.(int) $server->port : ''), $key, $username, $password);
    }

    /**
     * The rows this module manages: its articles, cancelled ones left out.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function managed(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => isset($row['Id'])
            && in_array((string) ($row['Article'] ?? ''), self::ARTICLES, true)
            && ($row['State'] ?? '') !== self::GONE));
    }

    /**
     * The mailbox's address, or the domain for a service that has none.
     *
     * @param  array<string, mixed>  $row
     */
    private static function account(array $row): string
    {
        return strtolower(trim((string) ($row['UserNameAccount'] ?? ''))) ?: strtolower(trim((string) ($row['Domain'] ?? '')));
    }

    private static function day(mixed $date): ?string
    {
        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}/', $date) ? substr($date, 0, 10) : null;
    }

    /** A day the way the site writes dates, or "?" when Aruba gave none. */
    private static function shown(mixed $day): string
    {
        return is_string($day) && $day !== '' ? Carbon::parse($day)->format(date_fmt()) : '?';
    }

    /** @param  array<string, mixed>  $row */
    private function store(Service $service, array $row): void
    {
        $this->setModuleData($service, [
            'aruba_service_id' => (string) $row['Id'],
            'aruba_account' => self::account($row),
            'aruba_article' => (string) ($row['Article'] ?? ''),
            'aruba_state' => (string) ($row['State'] ?? ''),
            'aruba_activated' => self::day($row['ActivationDate'] ?? null),
            'aruba_expires' => self::day($row['ExpirationDate'] ?? null),
            'aruba_autorenew' => (bool) ($row['AutomaticRenewal'] ?? false),
            'aruba_renewal_price' => (string) ($row['RenewalPrice'] ?? ''),
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    private function tellStaff(Service $service, string $message): void
    {
        try {
            app(NotificationService::class)->dispatch('module.failed', [
                'event_type' => 'module.failed',
                'subject' => 'Aruba Business: automatic renewal to turn off',
                'message' => "Service #{$service->id}: {$message}",
                'service_id' => $service->id,
                'action' => 'terminate',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Aruba Business: could not notify staff', ['error' => $e->getMessage()]);
        }
    }
}
