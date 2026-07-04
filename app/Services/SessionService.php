<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use App\Services\TokenService;

class SessionService
{
    public function __construct(private TokenService $tokenService) {}

    public function listForUser(User $user)
    {
        return UserSession::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_used_at')
            ->get();
    }

    public function revoke(User $user, UserSession $session, ?string $accessToken = null): void
    {
        if ($session->user_id !== $user->id) {
            abort(403, 'You cannot revoke this session.');
        }

        if ($session->isRevoked()) {
            return;
        }

        $session->update(['revoked_at' => now()]);

        if ($session->access_token_jti) {
            $this->tokenService->blacklistAccessToken(
                $session->access_token_jti,
                max(0, $session->expires_at->diffInSeconds(now())),
            );
        }

        if ($accessToken) {
            try {
                $payload = $this->tokenService->verifyAccessToken($accessToken);
                if ($payload->jti === $session->access_token_jti) {
                    $this->tokenService->blacklistAccessToken(
                        $payload->jti,
                        $this->tokenService->remainingAccessTtl($payload),
                    );
                }
            } catch (\Throwable) {
                // Ignore invalid tokens.
            }
        }
    }
}
