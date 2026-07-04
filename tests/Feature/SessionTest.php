<?php

use App\Models\User;

it('lists active sessions for the authenticated user', function () {
    $user = User::factory()->create();

    $signup = $this->postJson('/api/v1/auth/signup', [
        'name' => $user->name,
        'email' => 'sessions-new@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertCreated();

    $this->getJson('/api/v1/sessions', [
        'Authorization' => 'Bearer '.$signup->json('access_token'),
    ])->assertOk()->assertJsonCount(1, 'data');
});

it('revokes a session and rejects its refresh token', function () {
    $signup = $this->postJson('/api/v1/auth/signup', [
        'name' => 'Session User',
        'email' => 'session-revoke@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertCreated();

    $accessToken = $signup->json('access_token');
    $refreshToken = $signup->json('refresh_token');

    $sessions = $this->getJson('/api/v1/sessions', [
        'Authorization' => 'Bearer '.$accessToken,
    ])->assertOk();

    $sessionUuid = $sessions->json('data.0.uuid');

    $this->deleteJson("/api/v1/sessions/{$sessionUuid}", [], [
        'Authorization' => 'Bearer '.$accessToken,
    ])->assertNoContent();

    $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $refreshToken,
    ])->assertUnprocessable();
});
