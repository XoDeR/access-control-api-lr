<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

function authHeaders(User $user): array
{
    $response = test()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    return ['Authorization' => 'Bearer '.$response->json('access_token')];
}

function role(string $slug): Role
{
    return Role::query()->where('slug', $slug)->firstOrFail();
}
