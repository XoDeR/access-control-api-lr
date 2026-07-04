<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;

class MembershipPolicy
{
    public function __construct(private PermissionService $permissionService) {}

    public function updateRole(User $actor, Organization $organization, User $target, Role $newRole): bool
    {
        if (! $this->permissionService->isOrganizationAdmin($actor, $organization)) {
            return false;
        }

        $actorMembership = $this->permissionService->getMembership($actor, $organization);
        $targetMembership = Membership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $target->id)
            ->first();

        if (! $actorMembership || ! $targetMembership) {
            return false;
        }

        if ($targetMembership->role->slug === 'owner') {
            return false;
        }

        if ($newRole->slug === 'owner') {
            return $actorMembership->role->slug === 'owner';
        }

        if ($actorMembership->role->slug === 'admin' && in_array($newRole->slug, ['owner', 'admin'], true)) {
            return false;
        }

        return true;
    }
}
