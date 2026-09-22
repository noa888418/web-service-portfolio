<?php

namespace App\Support;

use InvalidArgumentException;

final class EmailAddressValidator
{
    public static function validate(string $value): void
    {
        if (strlen($value) > 254
            || ! preg_match('/\A[!-~]+\z/D', $value)
            || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('メールアドレスの形式が不正です。');
        }
    }
}
