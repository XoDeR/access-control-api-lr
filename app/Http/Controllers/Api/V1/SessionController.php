<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\SessionResource;
use App\Models\UserSession;
use App\Services\PermissionService;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(
        private SessionService $sessionService,
        private PermissionService $permissionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $sessions = $this->sessionService->listForUser($request->user());

        return response()->json([
            'data' => SessionResource::collection($sessions),
        ]);
    }

    public function destroy(Request $request, UserSession $session): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->query('organization_id');

        if ($session->user_id !== $user->id) {
            if ($organizationId) {
                $organization = \App\Models\Organization::query()->findOrFail($organizationId);
                if (! $this->permissionService->isOrganizationAdmin($user, $organization)) {
                    return response()->json(['message' => 'Forbidden.'], 403);
                }

                if ($session->isRevoked()) {
                    return response()->json(null, 204);
                }

                $session->update(['revoked_at' => now()]);

                if ($session->access_token_jti) {
                    app(\App\Services\TokenService::class)->blacklistAccessToken(
                        $session->access_token_jti,
                        (int) config('jwt.access_ttl'),
                    );
                }

                return response()->json(null, 204);
            }

            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $this->sessionService->revoke($session->user, $session, $request->bearerToken());

        return response()->json(null, 204);
    }
}
