<?php

namespace App\Services;

use App\Enums\ServiceStatus;
use App\Models\Server;
use App\Models\Service;
use App\Models\SslOrder;
use App\Services\Module\ModuleRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Carries a certificate the panel manages onto the hosting that serves its
 * domain: the record its authority validates by goes into the DNS zone the
 * hosting server serves, and the certificate, once issued, into that
 * server's certificate pool, in the places the one it renews was used.
 *
 * It acts only where the panel holds the private key and the domain is
 * hosted here on a server whose module can do it. Anything else is left to
 * be done by hand, as before.
 */
class SslHostingDeployment
{
    public function __construct(protected ModuleRegistry $registry) {}

    /**
     * Publishes the DNS record the order's validation waits for, once.
     *
     * @return array{success: bool, message: string}|null null when there is nothing to publish or nowhere to publish it
     */
    public function publishValidation(SslOrder $order): ?array
    {
        $dcv = (array) ($order->config_data['dcv'] ?? []);
        if (($dcv['method'] ?? '') !== 'DNS' || blank($dcv['name'] ?? null) || blank($dcv['value'] ?? null)) {
            return null;
        }

        $published = (array) ($order->config_data['dns_record'] ?? []);
        if (($published['value'] ?? null) === $dcv['value']) {
            return null;
        }

        [$hosting, $module] = $this->hostingFor($order, 'addDnsRecord');
        if (! $hosting) {
            return null;
        }

        $zone = strtolower((string) $hosting->domain);
        $name = rtrim(strtolower((string) $dcv['name']), '.');
        $host = $name === $zone ? '' : (string) preg_replace('/\.'.preg_quote($zone, '/').'$/', '', $name);

        $result = $module->addDnsRecord($hosting->server, $zone, (string) ($dcv['type'] ?? 'TXT'), $host, (string) $dcv['value']);
        if ($result['success']) {
            $this->remember($order, ['dns_record' => [
                'server_id' => $hosting->server_id, 'id' => $result['record_id'] ?? null, 'value' => $dcv['value'],
            ]]);
        }
        Log::info('SSL validation record', ['order' => $order->id, 'zone' => $zone, 'result' => $result['message']]);

        return $result;
    }

    /**
     * Installs an issued certificate on the hosting of its domain, once for
     * each certificate, and takes the validation record away again.
     *
     * @return array{success: bool, message: string}|null null when there is nothing to install or nowhere to install it
     */
    public function install(SslOrder $order): ?array
    {
        if ($order->status !== 'Completed' || blank($order->cert) || blank($order->private_key)
            || ($order->config_data['installed_remote_id'] ?? null) === (string) $order->remote_id) {
            return null;
        }

        [$hosting, $module] = $this->hostingFor($order, 'installSslCertificate');
        if (! $hosting) {
            return null;
        }

        $base = (string) preg_replace('/^\*\./', '', strtolower((string) $order->domain));
        $name = $base.(str_starts_with((string) $order->domain, '*.') ? '-wildcard' : '').'-'.($order->crt_expires?->format('Y-m-d') ?? now()->format('Y-m-d'));
        $previous = (string) ($order->config_data['installed_certificate'] ?? '');

        $result = $module->installSslCertificate(
            $hosting->server,
            strtolower((string) $hosting->domain),
            $name,
            (string) $order->private_key,
            (string) $order->cert,
            (string) $order->ca_cert,
            $previous !== '' ? [$previous] : [],
        );

        if ($result['success']) {
            $this->remember($order, ['installed_remote_id' => (string) $order->remote_id, 'installed_certificate' => $name]);
            $this->removeValidation($order);
        }
        Log::info('SSL certificate install', ['order' => $order->id, 'certificate' => $name, 'result' => $result['message']]);

        return $result;
    }

    private function removeValidation(SslOrder $order): void
    {
        $record = (array) ($order->config_data['dns_record'] ?? []);
        if (empty($record['id'])) {
            return;
        }

        $server = Server::find($record['server_id'] ?? null);
        $module = $server ? $this->registry->getServerModule((string) $server->type) : null;
        if ($module && method_exists($module, 'removeDnsRecord') && $module->removeDnsRecord($server, (int) $record['id'])) {
            $this->remember($order, ['dns_record' => null]);
        }
    }

    /**
     * The hosting that serves the certificate's domain, on a server whose
     * module can do what is asked: the customer's own first, then any.
     *
     * @return array{0: Service|null, 1: object|null}
     */
    private function hostingFor(SslOrder $order, string $ability): array
    {
        $domain = (string) preg_replace('/^(\*|www)\./', '', strtolower((string) $order->domain));

        $candidates = Service::with('server')
            ->where('domain', $domain)
            ->whereNotNull('server_id')
            ->whereNotIn('status', [ServiceStatus::Terminated->value, ServiceStatus::Cancelled->value, ServiceStatus::Fraud->value])
            ->orderByRaw('client_id = ? desc', [$order->client_id])
            ->orderBy('id')
            ->get();

        foreach ($candidates as $hosting) {
            $module = $hosting->server ? $this->registry->getServerModule((string) $hosting->server->type) : null;
            if ($module && method_exists($module, $ability)) {
                return [$hosting, $module];
            }
        }

        return [null, null];
    }

    /** @param  array<string, mixed>  $values  null removes the key */
    private function remember(SslOrder $order, array $values): void
    {
        $data = (array) ($order->config_data ?? []);
        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }
        }
        $order->update(['config_data' => $data]);
    }
}
