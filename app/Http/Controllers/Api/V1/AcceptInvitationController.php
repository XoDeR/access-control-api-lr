<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invitation\AcceptInvitationRequest;
use App\Http\Resources\MembershipResource;
use App\Models\User;
use App\Services\AuthService;
use App\Services\InvitationService;
use App\Services\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class AcceptInvitationController extends Controller
{
    public function __construct(
        private InvitationService $invitationService,
        private AuthService $authService,
    ) {}

    public function __invoke(AcceptInvitationRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        if (! $user) {
            return response()->json(['message' => 'Authentication required or provide account details.'], 422);
        }

        $membership = $this->invitationService->accept(
            $request->validated('token'),
            $user,
            $request->ip(),
        );

        $tokens = null;
        if (! $request->user()) {
            $tokens = $this->authService->login([
                'email' => $user->email,
                'password' => $request->validated('password'),
            ], $request);
        }

        return response()->json([
            'data' => new MembershipResource($membership),
            'tokens' => $tokens ? [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'token_type' => $tokens['token_type'],
                'expires_in' => $tokens['expires_in'],
            ] : null,
        ]);
    }

    private function resolveUser(AcceptInvitationRequest $request): ?User
    {
        if ($request->bearerToken()) {
            try {
                $payload = app(TokenService::class)->verifyAccessToken($request->bearerToken());
                $user = User::query()->where('uuid', $payload->sub)->first();
                if ($user) {
                    return $user;
                }
            } catch (\Throwable) {
                // Fall through to credential-based resolution.
            }
        }

        if (! $request->filled('email')) {
            return null;
        }

        $user = User::query()->where('email', $request->validated('email'))->first();

        if ($user) {
            if (! Hash::check($request->validated('password'), $user->password)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'password' => ['Invalid credentials for existing account.'],
                ]);
            }

            return $user;
        }

        return User::query()->create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
        ]);
    }
}
