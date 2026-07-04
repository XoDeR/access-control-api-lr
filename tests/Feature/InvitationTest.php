<?php

use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Support\TokenHasher;

it('invites and accepts a teammate', function () {
    $owner = User::factory()->create();
    $headers = authHeaders($owner);

    $orgResponse = $this->postJson('/api/v1/organizations', [
        'name' => 'Invite Org',
    ], $headers)->assertCreated();

    $orgUuid = $orgResponse->json('data.uuid');

    $invite = $this->postJson("/api/v1/organizations/{$orgUuid}/invitations", [
        'email' => 'invitee@example.com',
        'role' => 'member',
    ], $headers)->assertCreated();

    $token = $invite->json('invite_token');

    $this->postJson('/api/v1/invitations/accept', [
        'token' => $token,
        'name' => 'Invitee User',
        'email' => 'invitee@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertOk();

    $organization = Organization::query()->where('uuid', $orgUuid)->firstOrFail();

    expect(Membership::query()->where('organization_id', $organization->id)->count())->toBe(2);
    $this->assertDatabaseHas('audit_logs', ['action' => 'invitation.created']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'invitation.accepted']);
});

it('rejects expired invitation tokens', function () {
    $owner = User::factory()->create();
    $headers = authHeaders($owner);

    $org = $this->postJson('/api/v1/organizations', ['name' => 'Expired Org'], $headers)
        ->assertCreated();

    $organization = Organization::query()->where('uuid', $org->json('data.uuid'))->firstOrFail();
    $token = 'expired-token';

    Invitation::query()->create([
        'organization_id' => $organization->id,
        'email' => 'late@example.com',
        'role_id' => role('member')->id,
        'token_hash' => TokenHasher::hash($token),
        'invited_by' => $owner->id,
        'expires_at' => now()->subDay(),
    ]);

    $this->postJson('/api/v1/invitations/accept', [
        'token' => $token,
        'name' => 'Late User',
        'email' => 'late@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertUnprocessable();
});
