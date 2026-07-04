<?php

namespace App\Services;

use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\TokenHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvitationService
{
    public function __construct(
        private AuditService $auditService,
        private PermissionService $permissionService,
    ) {}

    public function create(
        Organization $organization,
        User $inviter,
        string $email,
        Role $role,
        string $ipAddress,
    ): array {
        $token = Str::random(64);

        $invitation = Invitation::query()->create([
            'organization_id' => $organization->id,
            'email' => strtolower($email),
            'role_id' => $role->id,
            'token_hash' => TokenHasher::hash($token),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->auditService->log(
            action: 'invitation.created',
            user: $inviter,
            organization: $organization,
            resourceType: 'invitation',
            resourceId: $invitation->id,
            metadata: ['email' => $email, 'role' => $role->slug],
            ipAddress: $ipAddress,
        );

        return [
            'invitation' => $invitation->load(['role', 'organization']),
            'token' => $token,
        ];
    }

    public function accept(string $token, User $user, string $ipAddress): Membership
    {
        $hash = TokenHasher::hash($token);

        return DB::transaction(function () use ($hash, $user, $ipAddress) {
            $invitation = Invitation::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if (! $invitation || ! $invitation->isPending()) {
                throw ValidationException::withMessages([
                    'token' => ['Invalid or expired invitation token.'],
                ]);
            }

            if (strcasecmp($invitation->email, $user->email) !== 0) {
                throw ValidationException::withMessages([
                    'token' => ['This invitation was sent to a different email address.'],
                ]);
            }

            $existing = Membership::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $invitation->organization_id)
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages([
                    'token' => ['You are already a member of this organization.'],
                ]);
            }

            $membership = Membership::query()->create([
                'user_id' => $user->id,
                'organization_id' => $invitation->organization_id,
                'role_id' => $invitation->role_id,
            ]);

            $invitation->update(['accepted_at' => now()]);

            $this->auditService->log(
                action: 'invitation.accepted',
                user: $user,
                organization: $invitation->organization,
                resourceType: 'membership',
                resourceId: $membership->id,
                metadata: ['role' => $invitation->role->slug],
                ipAddress: $ipAddress,
            );

            return $membership->load(['role', 'organization', 'user']);
        });
    }
}
