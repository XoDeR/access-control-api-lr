<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class OrganizationService
{
    public function __construct(
        private AuditService $auditService,
        private PermissionService $permissionService,
    ) {}

    public function create(User $user, string $name, ?string $slug = null): Organization
    {
        $slug = $slug ?? Str::slug($name);
        $baseSlug = $slug;
        $counter = 1;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return DB::transaction(function () use ($user, $name, $slug) {
            $organization = Organization::query()->create([
                'name' => $name,
                'slug' => $slug,
                'owner_id' => $user->id,
            ]);

            $ownerRole = Role::query()->where('slug', 'owner')->firstOrFail();

            Membership::query()->create([
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'role_id' => $ownerRole->id,
            ]);

            $this->auditService->log(
                action: 'organization.created',
                user: $user,
                organization: $organization,
                resourceType: 'organization',
                resourceId: $organization->id,
            );

            return $organization->load('owner');
        });
    }

    public function updateMemberRole(
        Organization $organization,
        User $actor,
        User $target,
        Role $newRole,
        string $ipAddress,
    ): Membership {
        return DB::transaction(function () use ($organization, $actor, $target, $newRole, $ipAddress) {
            $membership = Membership::query()
                ->where('organization_id', $organization->id)
                ->where('user_id', $target->id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldRole = $membership->role->slug;

            $membership->update(['role_id' => $newRole->id]);

            $this->permissionService->clearCache($target, $organization);

            $this->auditService->log(
                action: 'membership.role_changed',
                user: $actor,
                organization: $organization,
                resourceType: 'membership',
                resourceId: $membership->id,
                metadata: [
                    'target_user_id' => $target->id,
                    'from_role' => $oldRole,
                    'to_role' => $newRole->slug,
                ],
                ipAddress: $ipAddress,
            );

            return $membership->load(['role', 'user']);
        });
    }
}
