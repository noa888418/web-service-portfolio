<?php

namespace App\Support;

use InvalidArgumentException;

final class EmailNormalizer
{
    public static function normalize(string $value): string
    {
        $trimmed = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $value);

        if ($trimmed === null) {
            throw new InvalidArgumentException('メールアドレスの文字コードが不正です。');
        }

        // ASCII case folding only; normalization does not validate an address.
        return strtolower($trimmed);
    }
}
