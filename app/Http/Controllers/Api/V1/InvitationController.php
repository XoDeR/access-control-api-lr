<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invitation\StoreInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Models\Role;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvitationController extends Controller
{
    public function __construct(private InvitationService $invitationService) {}

    public function store(StoreInvitationRequest $request): JsonResponse
    {
        $organization = $request->attributes->get('organization');
        $role = Role::query()->where('slug', $request->validated('role'))->firstOrFail();

        $result = $this->invitationService->create(
            $organization,
            $request->user(),
            $request->validated('email'),
            $role,
            $request->ip(),
        );

        return response()->json([
            'data' => new InvitationResource($result['invitation']),
            'invite_token' => $result['token'],
        ], 201);
    }
}
