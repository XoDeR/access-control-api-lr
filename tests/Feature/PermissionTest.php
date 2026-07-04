<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

it('blocks viewers from inviting teammates', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $headers = authHeaders($owner);

    $orgUuid = $this->postJson('/api/v1/organizations', ['name' => 'RBAC Org'], $headers)
        ->assertCreated()
        ->json('data.uuid');

    $organization = Organization::query()->where('uuid', $orgUuid)->firstOrFail();

    Membership::query()->create([
        'user_id' => $viewer->id,
        'organization_id' => $organization->id,
        'role_id' => role('viewer')->id,
    ]);

    $this->postJson("/api/v1/organizations/{$orgUuid}/invitations", [
        'email' => 'blocked@example.com',
        'role' => 'member',
    ], authHeaders($viewer))->assertForbidden();
});

it('allows members to read organization members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $ownerHeaders = authHeaders($owner);

    $orgUuid = $this->postJson('/api/v1/organizations', ['name' => 'Read Org'], $ownerHeaders)
        ->assertCreated()
        ->json('data.uuid');

    $organization = Organization::query()->where('uuid', $orgUuid)->firstOrFail();

    Membership::query()->create([
        'user_id' => $member->id,
        'organization_id' => $organization->id,
        'role_id' => role('member')->id,
    ]);

    $this->getJson("/api/v1/organizations/{$orgUuid}/members", authHeaders($member))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('allows admins to change member roles', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $ownerHeaders = authHeaders($owner);

    $orgUuid = $this->postJson('/api/v1/organizations', ['name' => 'Role Org'], $ownerHeaders)
        ->assertCreated()
        ->json('data.uuid');

    $organization = Organization::query()->where('uuid', $orgUuid)->firstOrFail();

    Membership::query()->create([
        'user_id' => $admin->id,
        'organization_id' => $organization->id,
        'role_id' => role('admin')->id,
    ]);

    Membership::query()->create([
        'user_id' => $member->id,
        'organization_id' => $organization->id,
        'role_id' => role('member')->id,
    ]);

    $this->patchJson(
        "/api/v1/organizations/{$orgUuid}/members/{$member->uuid}",
        ['role' => 'viewer'],
        authHeaders($admin),
    )->assertOk()->assertJsonPath('data.role.slug', 'viewer');

    $this->assertDatabaseHas('audit_logs', ['action' => 'membership.role_changed']);
});
