<?php

namespace Tests\Unit;

use App\Support\EmailAddressValidator;
use App\Support\EmailNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    public function test_normalization_is_reusable_and_idempotent(): void
    {
        $input = " \t\u{3000}Employee-A@EXAMPLE.TEST\u{00a0}\r\n";
        $normalized = EmailNormalizer::normalize($input);
        self::assertSame('employee-a@example.test', $normalized);
        self::assertSame($normalized, EmailNormalizer::normalize($normalized));
        EmailAddressValidator::validate($normalized);
    }

    public function test_normalization_does_not_pretend_to_validate_format(): void
    {
        self::assertSame('not-an-address', EmailNormalizer::normalize(' NOT-AN-ADDRESS '));
        $this->expectException(InvalidArgumentException::class);
        EmailAddressValidator::validate('not-an-address');
    }

    #[DataProvider('invalidAddresses')]
    public function test_format_validation_rejects_without_silently_fixing(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        EmailAddressValidator::validate($input);
    }

    public static function invalidAddresses(): array
    {
        return [
            [''], ['employee-a@example.test '], ['a b@example.test'],
            ['社員@example.test'], ['a@'], [str_repeat('a', 255).'@example.test'],
            ["a\0@example.test"],
        ];
    }
}
