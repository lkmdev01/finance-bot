<?php

use App\Services\PhoneNumberService;
use App\Services\WhatsAppActivationService;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

test('registration screen can be rendered', function () {
    get(route('register'))
        ->assertOk()
        ->assertSee('Dados da conta')
        ->assertSee('Enviar código no WhatsApp');
});

it('registers a new user with recommended categories after whatsapp activation', function () {
    get(route('register'))->assertOk();

    $activationService = app(WhatsAppActivationService::class);
    $phoneNumber = app(PhoneNumberService::class)->formatForStorage('(13) 97605-4715');
    $activation = $activationService->issueForClient(session()->getId());

    $activation->forceFill([
        'verified_phone_number' => $phoneNumber,
        'verified_at' => Carbon::now(),
    ])->save();

    post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'email_confirmation' => 'test@example.com',
        'phone_number' => '(13) 97605-4715',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
        'category_setup' => 'recommended',
        'activation_code' => $activation->code,
    ])->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    $user = auth()->user();

    expect($user->phone_number)->toBe('5513976054715')
        ->and($user->whatsapp_verified_at)->not->toBeNull()
        ->and($user->categories()->count())->toBeGreaterThan(0);
});

it('registers a new user without seeded categories when choosing custom setup', function () {
    get(route('register'))->assertOk();

    $activationService = app(WhatsAppActivationService::class);
    $phoneNumber = app(PhoneNumberService::class)->formatForStorage('(11) 99999-8888');
    $activation = $activationService->issueForClient(session()->getId());

    $activation->forceFill([
        'verified_phone_number' => $phoneNumber,
        'verified_at' => Carbon::now(),
    ])->save();

    post(route('register.store'), [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'email_confirmation' => 'jane@example.com',
        'phone_number' => '(11) 99999-8888',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
        'category_setup' => 'custom',
        'activation_code' => $activation->code,
    ])->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    $user = auth()->user();

    expect($user->categories()->count())->toBe(0);
});

it('blocks registration when the whatsapp activation code was not verified yet', function () {
    get(route('register'))->assertOk();

    $activation = app(WhatsAppActivationService::class)->issueForClient(session()->getId());

    post(route('register.store'), [
        'name' => 'No Verify',
        'email' => 'noverify@example.com',
        'email_confirmation' => 'noverify@example.com',
        'phone_number' => '(13) 97605-4715',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
        'category_setup' => 'recommended',
        'activation_code' => $activation->code,
    ])->assertSessionHasErrors(['activation_code']);
});
