<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;

it('creates an organization and assigns owner membership', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/v1/organizations', [
        'name' => 'Acme Inc',
    ], authHeaders($user))->assertCreated();

    $organization = Organization::query()->where('slug', 'acme-inc')->first();

    expect($organization)->not->toBeNull();
    expect($organization->owner_id)->toBe($user->id);

    $membership = Membership::query()
        ->where('organization_id', $organization->id)
        ->where('user_id', $user->id)
        ->first();

    expect($membership->role->slug)->toBe('owner');
    $response->assertJsonPath('data.name', 'Acme Inc');
});

it('shows organization details for members', function () {
    $user = User::factory()->create();

    $create = $this->postJson('/api/v1/organizations', [
        'name' => 'Member Org',
    ], authHeaders($user))->assertCreated();

    $uuid = $create->json('data.uuid');

    $this->getJson("/api/v1/organizations/{$uuid}", authHeaders($user))
        ->assertOk()
        ->assertJsonPath('data.slug', 'member-org');
});
