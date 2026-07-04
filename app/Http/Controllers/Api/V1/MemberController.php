<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\UpdateMemberRoleRequest;
use App\Http\Resources\MembershipResource;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Policies\MembershipPolicy;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    public function __construct(
        private OrganizationService $organizationService,
        private MembershipPolicy $membershipPolicy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('organization');

        $memberships = $organization->memberships()
            ->with(['user', 'role'])
            ->get();

        return response()->json([
            'data' => MembershipResource::collection($memberships),
        ]);
    }

    public function update(
        UpdateMemberRoleRequest $request,
        Organization $organization,
        User $member,
    ): JsonResponse {
        $organization = $request->attributes->get('organization');
        $newRole = Role::query()->where('slug', $request->validated('role'))->firstOrFail();

        if (! $this->membershipPolicy->updateRole($request->user(), $organization, $member, $newRole)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $membership = $this->organizationService->updateMemberRole(
            $organization,
            $request->user(),
            $member,
            $newRole,
            $request->ip(),
        );

        return response()->json([
            'data' => new MembershipResource($membership),
        ]);
    }
}
