<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private TokenService $tokenService,
        private AuditService $auditService,
    ) {}

    public function signup(array $data, Request $request): array
    {
        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        return $this->issueTokensForUser($user, $request);
    }

    public function login(array $credentials, Request $request): array
    {
        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $tokens = $this->issueTokensForUser($user, $request);

        $this->auditService->log(
            action: 'auth.login',
            user: $user,
            ipAddress: $request->ip(),
        );

        return $tokens;
    }

    public function refresh(string $refreshToken, Request $request): array
    {
        $hash = $this->tokenService->hashRefreshToken($refreshToken);

        return DB::transaction(function () use ($hash, $refreshToken, $request) {
            $session = UserSession::query()
                ->where('refresh_token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if (! $session || ! $session->isActive()) {
                throw ValidationException::withMessages([
                    'refresh_token' => ['Invalid or expired refresh token.'],
                ]);
            }

            if ($session->access_token_jti) {
                $this->tokenService->blacklistAccessToken(
                    $session->access_token_jti,
                    (int) config('jwt.access_ttl'),
                );
            }

            $session->update(['revoked_at' => now()]);

            $user = $session->user;

            return $this->issueTokensForUser($user, $request);
        });
    }

    public function logout(User $user, ?string $accessToken, ?string $refreshToken, Request $request): void
    {
        if ($refreshToken) {
            $hash = $this->tokenService->hashRefreshToken($refreshToken);
            UserSession::query()
                ->where('user_id', $user->id)
                ->where('refresh_token_hash', $hash)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }

        if ($accessToken) {
            try {
                $payload = $this->tokenService->verifyAccessToken($accessToken);
                $this->tokenService->blacklistAccessToken(
                    $payload->jti,
                    $this->tokenService->remainingAccessTtl($payload),
                );
            } catch (\Throwable) {
                // Ignore invalid tokens on logout.
            }
        }

        $this->auditService->log(
            action: 'auth.logout',
            user: $user,
            ipAddress: $request->ip(),
        );
    }

    private function issueTokensForUser(User $user, Request $request): array
    {
        $access = $this->tokenService->createAccessToken($user);
        $refreshToken = $this->tokenService->generateRefreshToken();
        $refreshTtl = config('jwt.refresh_ttl');

        UserSession::query()->create([
            'user_id' => $user->id,
            'refresh_token_hash' => $this->tokenService->hashRefreshToken($refreshToken),
            'access_token_jti' => $access['jti'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_used_at' => now(),
            'expires_at' => now()->addSeconds($refreshTtl),
        ]);

        return [
            'access_token' => $access['token'],
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $access['expires_in'],
            'user' => $user,
        ];
    }
}
