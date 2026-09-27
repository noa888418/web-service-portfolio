<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\EmailAddressValidator;
use App\Support\EmailNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use InvalidArgumentException;

// Cookie authentication only; no API token or remember-me support.
class User extends Authenticatable
{
    protected $rememberTokenName = '';
    protected $guarded = ['*'];

    protected $visible = ['id', 'display_name', 'role'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'auth_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: function (string $value): string {
            $normalized = EmailNormalizer::normalize($value);
            EmailAddressValidator::validate($normalized);

            return $normalized;
        });
    }

    protected function displayName(): Attribute
    {
        return Attribute::make(set: function (string $value): string {
            $value = str_replace(["\r\n", "\r"], "\n", $value);
            $trimmed = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $value);
            if ($trimmed === null || str_contains($trimmed, "\0")
                || mb_strlen($trimmed, 'UTF-8') < 1 || mb_strlen($trimmed, 'UTF-8') > 50) {
                throw new InvalidArgumentException('表示名は1～50文字で指定してください。');
            }

            return $trimmed;
        });
    }
}
