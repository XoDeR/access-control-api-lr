<?php

use App\Models\User;

it('records audit logs for login and organization actions', function () {
    $user = User::factory()->create();
    $headers = authHeaders($user);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    $orgUuid = $this->postJson('/api/v1/organizations', ['name' => 'Audit Org'], $headers)
        ->assertCreated()
        ->json('data.uuid');

    $this->getJson("/api/v1/organizations/{$orgUuid}/audit-logs", $headers)
        ->assertOk()
        ->assertJsonFragment(['action' => 'organization.created']);
});
