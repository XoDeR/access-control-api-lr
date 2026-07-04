<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Services\PermissionService;

class OrganizationPolicy
{
    public function __construct(private PermissionService $permissionService) {}

    public function view(User $user, Organization $organization): bool
    {
        return $this->permissionService->getMembership($user, $organization) !== null;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->permissionService->isOrganizationAdmin($user, $organization);
    }
}
