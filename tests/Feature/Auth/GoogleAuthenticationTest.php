<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

test('users are redirected to google', function () {
    Socialite::fake('google');

    $response = $this->get(route('google.redirect'));

    $response->assertRedirect();
});

test('new users can authenticate using google', function () {
    Socialite::fake('google', (new SocialiteUser)->map([
        'id' => 'google-123',
        'name' => 'Lukas Martins',
        'email' => 'lukas@example.com',
        'avatar' => 'https://example.com/avatar.png',
    ]));

    $response = $this->get(route('google.callback'));

    $response->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();

    $this->assertDatabaseHas('users', [
        'email' => 'lukas@example.com',
        'google_id' => 'google-123',
        'auth_provider' => 'google',
    ]);
});

test('existing users are linked to google by email', function () {
    $user = User::factory()->create([
        'name' => 'Lukas Martins',
        'email' => 'lukas@example.com',
        'google_id' => null,
        'auth_provider' => null,
    ]);

    Socialite::fake('google', (new SocialiteUser)->map([
        'id' => 'google-456',
        'name' => 'Lukas Martins',
        'email' => 'lukas@example.com',
        'avatar' => 'https://example.com/avatar-2.png',
    ]));

    $response = $this->get(route('google.callback'));

    $response->assertRedirect(route('dashboard'));

    $user->refresh();

    expect($user->google_id)->toBe('google-456')
        ->and($user->auth_provider)->toBe('google')
        ->and($user->google_avatar)->toBe('https://example.com/avatar-2.png');
});
