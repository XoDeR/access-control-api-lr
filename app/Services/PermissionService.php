<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class PermissionService
{
    public function getMembership(User $user, Organization $organization): ?Membership
    {
        return Membership::query()
            ->with('role.permissions')
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->first();
    }

    public function userHasPermission(User $user, Organization $organization, string $permission): bool
    {
        $permissions = $this->getUserPermissions($user, $organization);

        return in_array($permission, $permissions, true);
    }

    public function getUserPermissions(User $user, Organization $organization): array
    {
        $cacheKey = "permissions:{$user->id}:{$organization->id}";

        return Cache::remember($cacheKey, 60, function () use ($user, $organization) {
            $membership = $this->getMembership($user, $organization);

            if (! $membership) {
                return [];
            }

            return $membership->role
                ->permissions
                ->pluck('slug')
                ->all();
        });
    }

    public function clearCache(User $user, Organization $organization): void
    {
        Cache::forget("permissions:{$user->id}:{$organization->id}");
    }

    public function isOrganizationAdmin(User $user, Organization $organization): bool
    {
        $membership = $this->getMembership($user, $organization);

        if (! $membership) {
            return false;
        }

        return in_array($membership->role->slug, ['owner', 'admin'], true);
    }
}
