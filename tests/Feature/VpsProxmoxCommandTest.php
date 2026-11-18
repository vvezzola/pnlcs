<?php

use App\Models\Product;
use Tests\Support\FakeProxmox;

require_once __DIR__.'/../Support/proxmox_helpers.php';

/*
 * Esalogica only: the six VPS of the price list, sold with manual
 * activation, are linked to the Proxmox module with the price list's
 * resources and the template, node and bridge chosen.
 */

function vpsProducts(): void
{
    foreach (['vps46', 'vps612', 'vps816', 'vps1224', 'vps1632', 'vps1648'] as $slug) {
        Product::factory()->create(['slug' => $slug, 'name' => strtoupper($slug), 'type' => 'vps', 'server_type' => null, 'auto_setup' => 'manual']);
    }
}

test('without a template it lists what the Proxmox server has, and changes nothing', function () {
    FakeProxmox::install()->template(9000)->template(9001, 'ubuntu2404-cloud');
    pveServer(['pool' => 'pnlcs']);
    vpsProducts();

    $this->artisan('esalogica:vps-proxmox')
        ->expectsOutputToContain('#9000')
        ->expectsOutputToContain('#9001')
        ->expectsOutputToContain('vmbr0')
        ->assertSuccessful();

    expect(Product::where('server_type', 'proxmox')->count())->toBe(0);
});

test('--write links every VPS with the price list\'s resources, once', function () {
    FakeProxmox::install()->template(9000)->template(9001, 'ubuntu2404-cloud');
    pveServer(['pool' => 'pnlcs']);
    vpsProducts();

    $this->artisan('esalogica:vps-proxmox --template=9000 --ipv4=dhcp --write')->assertSuccessful();

    $vps = Product::where('slug', 'vps1224')->first();
    expect($vps->server_type)->toBe('proxmox')
        ->and($vps->auto_setup)->toBe('payment')
        ->and($vps->config_options)->toMatchArray([
            'pve_type' => 'qemu', 'pve_template' => '9000', 'pve_node' => 'pve', 'pve_bridge' => 'vmbr0', 'pve_storage' => 'local-zfs',
            'pve_cores' => 12, 'pve_memory' => 24576, 'pve_disk' => 400, 'pve_bandwidth' => 1000, 'pve_ipv4' => 'dhcp', 'pve_ipv6' => 'auto',
        ])
        ->and(collect($vps->config_options['pve_os_choices'])->pluck('id')->all())->toBe(['9000', '9001']);

    $this->artisan('esalogica:vps-proxmox --template=9000 --ipv4=dhcp')
        ->expectsOutputToContain('Niente da cambiare')
        ->assertSuccessful();
});

test('a dedicated address from a pool the server does not have is refused', function () {
    FakeProxmox::install()->template(9000);
    pveServer(['pool' => 'pnlcs']);
    vpsProducts();

    $this->artisan('esalogica:vps-proxmox --template=9000 --ipv4=pool --write')
        ->expectsOutputToContain('pool di indirizzi IPv4')
        ->assertFailed();

    expect(Product::where('server_type', 'proxmox')->count())->toBe(0);
});
