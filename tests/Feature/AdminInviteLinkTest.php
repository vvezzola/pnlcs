<?php

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/*
 * Staff create an invitation link on the client page and hand it over
 * themselves: nothing is mailed. An account brought in without a login gets
 * its owner this way.
 */

function inviteLinkStaff(array $permissions): Admin
{
    return Admin::factory()->create(['role_id' => AdminRole::create(['name' => 'Desk '.uniqid(), 'permissions' => $permissions])->id]);
}

test('the link is shown once, nothing is mailed, and its first login owns the account', function () {
    Mail::fake();
    $client = Client::factory()->create(['email' => 'rossi@example.test']);

    $this->actingAs(inviteLinkStaff(['view_clients', 'edit_clients']), 'admin')
        ->post(route('admin.clients.invite-link', $client), ['email' => 'Rossi@Example.test'])
        ->assertSessionHas('invite_link', fn (array $shown) => $shown['email'] === 'rossi@example.test');

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
    $path = parse_url(session('invite_link')['link'], PHP_URL_PATH);
    auth('admin')->logout();

    $this->get($path)->assertOk()->assertSee('rossi@example.test');
    $this->post($path, ['first_name' => 'Mario', 'last_name' => 'Rossi', 'password' => 'long-enough-1', 'password_confirmation' => 'long-enough-1'])
        ->assertRedirect(route('client.home'));

    $login = User::where('email', 'rossi@example.test')->first();
    expect($client->fresh()->owner()?->id)->toBe($login->id);
});

test('a second login invited later is not the owner', function () {
    Mail::fake();
    $client = Client::factory()->create();
    $owner = User::factory()->create();
    $client->users()->attach($owner->id, ['owner' => true, 'permissions' => json_encode([])]);

    $this->actingAs(inviteLinkStaff(['view_clients', 'edit_clients']), 'admin')
        ->post(route('admin.clients.invite-link', $client), ['email' => 'helper@example.test']);
    $path = parse_url(session('invite_link')['link'], PHP_URL_PATH);
    auth('admin')->logout();
    $this->post($path, ['first_name' => 'Hel', 'last_name' => 'Per', 'password' => 'long-enough-1', 'password_confirmation' => 'long-enough-1']);

    expect($client->fresh()->owner()?->id)->toBe($owner->id);
});

test('a link needs the edit_clients permission, and none is made for an address that has a login', function () {
    $client = Client::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.test']);
    $client->users()->attach($member->id, ['owner' => true, 'permissions' => json_encode([])]);

    $this->actingAs(inviteLinkStaff(['view_clients']), 'admin')
        ->post(route('admin.clients.invite-link', $client), ['email' => 'new@example.test'])->assertForbidden();

    $this->actingAs(inviteLinkStaff(['view_clients', 'edit_clients']), 'admin')
        ->post(route('admin.clients.invite-link', $client), ['email' => 'member@example.test'])
        ->assertSessionHas('error')->assertSessionMissing('invite_link');
});
