<?php

namespace App\Support;

final class StorefrontImage
{
    public static function isUrl(mixed $value): bool
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($value);

        return ($parts['scheme'] ?? null) === 'https'
            && strtolower($parts['host'] ?? '') === 'namenecklace.ru'
            && ($parts['port'] ?? 443) === 443
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    public static function isPathOrUrl(mixed $value): bool
    {
        return self::isUrl($value)
            || (is_string($value)
                && str_starts_with($value, '/')
                && ! str_starts_with($value, '//')
                && ! str_contains($value, '\\')
                && ! preg_match('/[\x00-\x20]/', $value));
    }
}
