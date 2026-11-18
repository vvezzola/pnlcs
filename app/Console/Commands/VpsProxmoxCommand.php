<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Server;
use Illuminate\Console\Command;
use Modules\Servers\Proxmox\ProxmoxModule;

/**
 * Esalogica only: the six VPS of the 2027 price list, sold until now with
 * manual activation, made by the Proxmox module instead. Without
 * --template it lists what the Proxmox server has (templates, nodes,
 * bridges, storages) to choose from; with it, it shows the configuration
 * each product would get; --write applies it. Prices are not touched.
 */
class VpsProxmoxCommand extends Command
{
    protected $signature = 'esalogica:vps-proxmox
        {--template= : The KVM template every VPS is installed from (its VM id)}
        {--os=* : Further templates the customer may reinstall with (VM ids); default every template on the same node}
        {--node= : The node the VPS are made on; default the template\'s}
        {--storage=local-zfs : The storage of the disks}
        {--bridge= : The network bridge; default vmbr0 when there is one}
        {--ipv4= : pool (one address from the server\'s IPv4 pool) or dhcp; default pool when the server has one}
        {--ipv6=auto : auto (SLAAC) or none}
        {--write : Write to the database; without it nothing is changed}';

    protected $description = 'Esalogica: link the VPS products to the Proxmox module (dry run unless --write)';

    /**
     * The 2027 price list: slug => [vCPU, RAM GB, disk GB, monthly traffic GB].
     *
     * @var array<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    private const PLANS = [
        'vps46' => [4, 6, 100, 100],
        'vps612' => [6, 12, 200, 200],
        'vps816' => [8, 16, 300, 500],
        'vps1224' => [12, 24, 400, 1000],
        'vps1632' => [16, 32, 500, 1000],
        'vps1648' => [16, 48, 600, 1000],
    ];

    public function handle(): int
    {
        $server = Server::where('type', 'proxmox')->where('active', true)->orderBy('id')->first();
        if (! $server) {
            $this->error('Nessun server Proxmox attivo.');

            return self::FAILURE;
        }

        $module = new ProxmoxModule;
        $catalog = $module->catalog($server, (string) $this->option('node'));
        if (! ($catalog['ok'] ?? true) && isset($catalog['error'])) {
            $this->error('Proxmox: '.$catalog['error']);

            return self::FAILURE;
        }

        $templates = collect($catalog['templates'] ?? [])->where('type', 'qemu');
        $bridges = collect($catalog['bridges'] ?? [])->pluck('id');
        $storages = collect($catalog['storages'] ?? $catalog['disk_storages'] ?? [])->map(fn ($s) => is_array($s) ? ($s['id'] ?? $s['storage'] ?? '') : (string) $s);

        $template = $templates->firstWhere('id', (string) $this->option('template'));
        if (! $template) {
            $this->line("Server {$server->name} ({$server->hostname}). Scegli il template con --template=<id>:");
            foreach ($templates as $t) {
                $this->line('  '.$t['name']);
            }
            $this->line('Reti: '.$bridges->implode(', ').' | Storage: '.$storages->filter()->implode(', '));
            if ($this->option('template')) {
                $this->error('Il template '.$this->option('template').' non è tra quelli KVM visibili.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $node = (string) ($this->option('node') ?: $template['node']);
        if ($node !== $template['node']) {
            $this->warn("  ! il template #{$template['id']} è sul nodo {$template['node']}, non su {$node}: la clonazione potrebbe non riuscire");
        }
        $bridge = (string) ($this->option('bridge') ?: ($bridges->contains('vmbr0') ? 'vmbr0' : $bridges->first()));
        if ($bridge === '' || ! $bridges->contains($bridge)) {
            $this->error("La rete {$bridge} non c'è sul nodo {$node}: ".$bridges->implode(', '));

            return self::FAILURE;
        }
        $hasPool = trim((string) $server->setting('ipv4_pool', '')) !== '';
        $ipv4 = (string) ($this->option('ipv4') ?: ($hasPool ? 'pool' : 'dhcp'));
        if (! in_array($ipv4, ['pool', 'dhcp'], true) || ($ipv4 === 'pool' && ! $hasPool)) {
            $this->error($ipv4 === 'pool' ? 'Il server non ha un pool di indirizzi IPv4: impostalo o usa --ipv4=dhcp.' : '--ipv4 vale pool o dhcp.');

            return self::FAILURE;
        }
        if ($ipv4 === 'dhcp') {
            $this->warn('  ! IPv4 via DHCP: ogni VPS prende l\'indirizzo dal DHCP della rete, non uno dedicato dal pool di PNLCS');
        }
        $ipv6 = in_array($this->option('ipv6'), ['auto', 'none'], true) ? (string) $this->option('ipv6') : 'auto';

        $osIds = collect((array) $this->option('os'))->filter()->map(fn ($id) => (string) $id);
        $choices = ($osIds->isEmpty() ? $templates->where('node', $template['node']) : $templates->whereIn('id', $osIds->all()))
            ->map(fn ($t) => ['id' => $t['id'], 'name' => trim((string) preg_replace('/^#\d+\s+|\s+\(qemu.*$/', '', $t['name']))])
            ->values()->all();

        $write = (bool) $this->option('write');
        $changes = 0;
        foreach (self::PLANS as $slug => [$cores, $ramGb, $diskGb, $trafficGb]) {
            $product = Product::where('slug', $slug)->first();
            if (! $product) {
                $this->warn("  ! nessun prodotto {$slug}");

                continue;
            }

            $config = [
                'pve_type' => 'qemu', 'pve_node' => $node, 'pve_template' => (string) $template['id'], 'pve_ostemplate' => '', 'pve_iso' => '',
                'pve_storage' => (string) $this->option('storage'), 'pve_bridge' => $bridge, 'pve_vlan' => 0, 'pve_rate' => 0, 'pve_firewall' => 0,
                'pve_cores' => $cores, 'pve_sockets' => 1, 'pve_cpulimit' => 0, 'pve_memory' => $ramGb * 1024, 'pve_swap' => 512,
                'pve_disk' => $diskGb, 'pve_bandwidth' => $trafficGb, 'pve_snapshots' => 0, 'pve_backups' => 0,
                'pve_ipv4' => $ipv4, 'pve_ipv6' => $ipv6, 'pve_nesting' => 0, 'pve_protection' => 1, 'pve_ciuser' => 'root',
                'pve_ciupgrade' => 0, 'pve_nameserver' => '', 'pve_os_choices' => $choices,
            ];
            $current = (array) $product->config_options;
            $same = strtolower((string) $product->server_type) === 'proxmox'
                && $product->auto_setup === 'payment'
                && array_intersect_key($current, $config) == $config;
            if ($same) {
                $this->line("  = {$product->name}");

                continue;
            }

            $changes++;
            $this->line("  * {$product->name}: Proxmox, {$cores} vCPU, {$ramGb} GB RAM, {$diskGb} GB su {$config['pve_storage']}, {$trafficGb} GB/mese, "
                ."template #{$template['id']} sul nodo {$node}, rete {$bridge}, IPv4 {$ipv4}, IPv6 {$ipv6}, reinstallazione: ".collect($choices)->pluck('name')->implode(', '));
            if ($write) {
                $product->update([
                    'server_type' => 'proxmox',
                    'auto_setup' => 'payment',
                    'config_options' => array_merge($current, $config),
                ]);
            }
        }

        if ($changes === 0) {
            $this->info('Niente da cambiare.');
        } elseif ($write) {
            $this->info('Fatto.');
        } else {
            $this->line('Prova: nulla è stato scritto. Aggiungi --write per applicare.');
        }

        return self::SUCCESS;
    }
}
