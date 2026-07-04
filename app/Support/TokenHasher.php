<?php

namespace App\Support;

class TokenHasher
{
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
