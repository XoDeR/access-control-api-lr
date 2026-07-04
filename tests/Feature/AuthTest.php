<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('registers a new user', function () {
    $response = $this->postJson('/api/v1/auth/signup', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['access_token', 'refresh_token', 'token_type', 'expires_in', 'user']);

    $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
});

it('logs in with valid credentials', function () {
    User::factory()->create([
        'email' => 'login@example.com',
        'password' => Hash::make('password123'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'login@example.com',
        'password' => 'password123',
    ]);

    $response->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login']);
});

it('rejects invalid login credentials', function () {
    User::factory()->create(['email' => 'user@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'user@example.com',
        'password' => 'wrong-password',
    ])->assertUnprocessable();
});

it('rotates refresh tokens', function () {
    $signup = $this->postJson('/api/v1/auth/signup', [
        'name' => 'Token User',
        'email' => 'token@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertCreated();

    $oldRefresh = $signup->json('refresh_token');

    $refresh = $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $oldRefresh,
    ])->assertOk();

    expect($refresh->json('refresh_token'))->not->toBe($oldRefresh);

    $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $oldRefresh,
    ])->assertUnprocessable();
});

it('logs out and blacklists the access token', function () {
    $signup = $this->postJson('/api/v1/auth/signup', [
        'name' => 'Logout User',
        'email' => 'logout@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertCreated();

    $accessToken = $signup->json('access_token');
    $refreshToken = $signup->json('refresh_token');

    $this->postJson('/api/v1/auth/logout', [
        'refresh_token' => $refreshToken,
    ], [
        'Authorization' => 'Bearer '.$accessToken,
    ])->assertNoContent();

    $this->getJson('/api/v1/sessions', [
        'Authorization' => 'Bearer '.$accessToken,
    ])->assertUnauthorized();
});
