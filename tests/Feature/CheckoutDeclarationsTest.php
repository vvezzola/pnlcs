<?php

use App\Http\Controllers\LegalController;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Setting;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Support\Facades\Mail;

/*
 * Italian law wants onerous clauses approved one by one (arts. 1341-1342
 * Civil Code), and a consumer who wants a service before the 14-day
 * withdrawal period ends has to ask for it (Directive 2011/83/EU). The
 * operator can make checkout ask for both; each is a required box, and what
 * was ticked is kept on the order with the date of the terms accepted.
 */

function declaringShopper(): User
{
    $user = User::factory()->create();
    $client = Client::factory()->create(['tax_exempt' => true]);
    $user->clients()->attach($client->id);

    $product = Product::factory()->create([
        'group_id' => ProductGroup::factory()->create()->id,
        'server_type' => '', 'type' => 'other', 'hidden' => false, 'retired' => false, 'tax' => false,
    ]);
    Pricing::create([
        'type' => 'product',
        'rel_id' => $product->id,
        'currency_id' => Currency::firstOrCreate(['code' => 'EUR'], ['prefix' => '€', 'suffix' => '', 'rate' => 1, 'is_default' => true])->id,
        'monthly' => 10,
    ]);

    $cart = app(CartService::class)->getOrCreateCart($client->id);
    app(CartService::class)->addProduct($cart, $product, 'monthly', 'declaring.example');

    return $user;
}

function askForDeclarations(): void
{
    Setting::set('CheckoutClauseApproval', '1');
    Setting::set('CheckoutImmediateStart', '1');
}

test('checkout shows the boxes the operator asks for', function () {
    askForDeclarations();
    $user = declaringShopper();

    $this->actingAs($user)->get(route('client.cart.checkout'))->assertOk()
        ->assertSee('name="clause_approval"', false)
        ->assertSee('name="immediate_start"', false)
        ->assertSee(__('client.checkout.clause_approval'));
});

test('an order without the required declarations is refused', function () {
    Mail::fake();
    askForDeclarations();

    $this->actingAs(declaringShopper())->post(route('client.cart.process'), [
        'payment_method' => 'banktransfer',
        'terms' => 'on',
    ])->assertSessionHasErrors(['clause_approval', 'immediate_start']);

    expect(Order::count())->toBe(0);
});

test('the declarations and the date of the operator\'s documents are kept on the order', function () {
    Mail::fake();
    askForDeclarations();
    Setting::set('LegalDocumentsRevised', '2026-11-17');

    $this->actingAs(declaringShopper())->post(route('client.cart.process'), [
        'payment_method' => 'banktransfer',
        'terms' => 'on',
        'clause_approval' => 'on',
        'immediate_start' => 'on',
    ])->assertRedirect();

    expect(Order::sole())
        ->terms_declarations->toBe(['clause_approval', 'immediate_start'])
        ->terms_version->toBe('2026-11-17');
});

test('with the boxes off checkout asks for the terms alone, as before', function () {
    Mail::fake();
    $user = declaringShopper();

    $this->actingAs($user)->get(route('client.cart.checkout'))->assertOk()
        ->assertDontSee('name="clause_approval"', false);

    $this->actingAs($user)->post(route('client.cart.process'), [
        'payment_method' => 'banktransfer',
        'terms' => 'on',
    ])->assertRedirect();

    expect(Order::sole())
        ->terms_declarations->toBeNull()
        ->terms_version->toBe(LegalController::REVISED);
});
