<?php

namespace App\Support;

use InvalidArgumentException;

final class RequestText
{
    public static function normalize(mixed $value, int $max, bool $multiline): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new InvalidArgumentException;
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // Reject title line breaks before trimming, including leading/trailing ones.
        if (! $multiline && str_contains($value, "\n")) {
            throw new InvalidArgumentException;
        }
        $value = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $value);
        if (mb_strlen($value, 'UTF-8') < 1 || mb_strlen($value, 'UTF-8') > $max) {
            throw new InvalidArgumentException;
        }

        return $value;
    }
}
