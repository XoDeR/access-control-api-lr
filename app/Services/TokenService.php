<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use App\Support\TokenHasher;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;

class TokenService
{
    public function createAccessToken(User $user, ?string $jti = null): array
    {
        $jti = $jti ?? (string) Str::uuid();
        $now = time();
        $ttl = config('jwt.access_ttl');

        $payload = [
            'iss' => config('jwt.issuer'),
            'sub' => $user->uuid,
            'jti' => $jti,
            'iat' => $now,
            'exp' => $now + $ttl,
        ];

        return [
            'token' => JWT::encode($payload, $this->secret(), config('jwt.algorithm')),
            'jti' => $jti,
            'expires_in' => $ttl,
        ];
    }

    public function verifyAccessToken(string $token): object
    {
        $payload = JWT::decode($token, new Key($this->secret(), config('jwt.algorithm')));

        if ($this->isAccessTokenBlacklisted($payload->jti)) {
            throw new \RuntimeException('Token has been revoked.');
        }

        return $payload;
    }

    public function generateRefreshToken(): string
    {
        return Str::random(64);
    }

    public function hashRefreshToken(string $token): string
    {
        return TokenHasher::hash($token);
    }

    public function blacklistAccessToken(string $jti, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }

        Cache::put($this->blacklistKey($jti), true, $ttlSeconds);
    }

    public function isAccessTokenBlacklisted(string $jti): bool
    {
        return Cache::has($this->blacklistKey($jti));
    }

    public function remainingAccessTtl(object $payload): int
    {
        return max(0, (int) $payload->exp - time());
    }

    private function blacklistKey(string $jti): string
    {
        return 'jwt:blacklist:'.$jti;
    }

    private function secret(): string
    {
        $secret = config('jwt.secret');

        if (empty($secret)) {
            throw new \RuntimeException('JWT secret is not configured.');
        }

        return $secret;
    }
}
