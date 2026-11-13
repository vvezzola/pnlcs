<?php

use App\Mail\TicketOpenedMail;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Client;
use App\Models\Language;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketDepartmentTranslation;
use App\Models\User;

/*
 * Support departments in the customer's language.
 *
 * A department held one name, and the installer seeds them in English, so an
 * Italian customer choosing where to send a ticket read "Technical Support"
 * and "Billing". The department's own name is the default language's; a
 * translation holds another active language's, and a customer without one in
 * their language reads the default name.
 */

function deptLanguages(): void
{
    Language::updateOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'is_active' => true, 'is_default' => true]);
    Language::updateOrCreate(['code' => 'it'], ['name' => 'Italian', 'native_name' => 'Italiano', 'is_active' => true, 'is_default' => false]);
}

function deptTranslated(): TicketDepartment
{
    $department = TicketDepartment::create(['name' => 'Technical Support', 'hidden' => false, 'sort_order' => 1]);
    TicketDepartmentTranslation::create(['ticket_department_id' => $department->id, 'language' => 'it', 'name' => 'Assistenza tecnica']);

    return $department;
}

/** @return array{0: User, 1: Client} */
function deptCustomer(string $language = 'it'): array
{
    $user = User::factory()->create();
    $client = Client::factory()->create(['language' => $language]);
    $user->clients()->attach($client->id);

    return [$user, $client];
}

test('a customer chooses among departments named in their language', function () {
    deptLanguages();
    deptTranslated();
    TicketDepartment::create(['name' => 'Sales', 'hidden' => false, 'sort_order' => 2]);
    [$user] = deptCustomer();

    $this->actingAs($user)->get(route('client.tickets.create').'?lang=it')->assertOk()
        ->assertSee('Assistenza tecnica')->assertDontSee('Technical Support')
        ->assertSee('Sales');

    $this->get(route('client.contact').'?lang=it')->assertOk()->assertSee('Assistenza tecnica');
});

test('the ticket list and the ticket show the department in the customer\'s language', function () {
    deptLanguages();
    $department = deptTranslated();
    [$user, $client] = deptCustomer();
    $ticket = Ticket::factory()->create(['client_id' => $client->id, 'department_id' => $department->id, 'status' => 'Open']);

    $this->actingAs($user)->get(route('client.tickets.index').'?lang=it')->assertOk()->assertSee('Assistenza tecnica');
    $this->actingAs($user)->get(route('client.tickets.show', $ticket).'?lang=it')->assertOk()->assertSee('Assistenza tecnica');
    $this->actingAs($user)->get(route('client.tickets.show', $ticket).'?lang=en')->assertOk()->assertSee('Technical Support');
});

test('the ticket-opened email names the department in the customer\'s language', function () {
    deptLanguages();
    $department = deptTranslated();
    [, $client] = deptCustomer();
    $ticket = Ticket::factory()->create(['client_id' => $client->id, 'department_id' => $department->id]);

    expect((new TicketOpenedMail($ticket))->render())->toContain('Assistenza tecnica')->not->toContain('Technical Support');
});

test('the admin saves, changes and removes a department\'s translation', function () {
    deptLanguages();
    $admin = Admin::factory()->create(['role_id' => AdminRole::factory()->fullAdmin()->create()->id]);
    $department = TicketDepartment::create(['name' => 'Billing', 'hidden' => false, 'sort_order' => 1]);
    $update = fn (string $italian) => $this->actingAs($admin, 'admin')
        ->put(route('admin.config.ticket-departments.update', $department), ['name' => 'Billing', 'translations' => ['it' => ['name' => $italian]]])
        ->assertRedirect();

    $update('Fatturazione');
    expect($department->translations()->where('language', 'it')->value('name'))->toBe('Fatturazione');

    $update('Amministrazione');
    expect($department->translations()->where('language', 'it')->value('name'))->toBe('Amministrazione')
        ->and($department->translations()->count())->toBe(1);

    $update('');
    expect($department->translations()->count())->toBe(0);
});

test('a department created with a translation keeps it', function () {
    deptLanguages();
    $admin = Admin::factory()->create(['role_id' => AdminRole::factory()->fullAdmin()->create()->id]);

    $this->actingAs($admin, 'admin')
        ->post(route('admin.config.ticket-departments.store'), ['name' => 'Sales', 'translations' => ['it' => ['name' => 'Commerciale']]])
        ->assertRedirect();

    expect(TicketDepartment::where('name', 'Sales')->sole()->translations()->value('name'))->toBe('Commerciale');
});

test('staff read the ticket priority in their own language', function () {
    deptLanguages();
    $admin = Admin::factory()->create(['role_id' => AdminRole::factory()->fullAdmin()->create()->id]);
    $ticket = Ticket::factory()->create(['priority' => 'High']);

    $this->actingAs($admin, 'admin')->get(route('admin.tickets.index').'?lang=it')->assertOk()->assertSee('>Alta<', false);
    $this->actingAs($admin, 'admin')->get(route('admin.tickets.show', $ticket).'?lang=it')->assertOk()->assertSee('>Alta<', false);
});
