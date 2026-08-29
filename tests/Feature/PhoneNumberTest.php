<?php

namespace Tests\Feature;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * App\Support\PhoneNumber reconciles the shapes a mobile number arrives in
 * (bare national rows, Firebase E.164, user-typed spacing / +CC / leading 0)
 * so a Firebase-verified number matches the rows this codebase already has.
 */
class PhoneNumberTest extends TestCase
{
    #[DataProvider('nationalCases')]
    public function test_national_collapses_every_shape_to_bare_ten_digits(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::national($input));
    }

    public static function nationalCases(): array
    {
        return [
            'already national' => ['9876543210', '9876543210'],
            'spaces' => ['98765 43210', '9876543210'],
            'dashes' => ['98765-43210', '9876543210'],
            'e164' => ['+919876543210', '9876543210'],
            '00 prefix' => ['00919876543210', '9876543210'],
            'bare 91 prefix' => ['919876543210', '9876543210'],
            'leading trunk zero' => ['09876543210', '9876543210'],
            'parens and dots' => ['(98765).43210', '9876543210'],
            'empty' => ['', ''],
        ];
    }

    public function test_national_is_idempotent(): void
    {
        $once = PhoneNumber::national('+91 98765-43210');
        $this->assertSame($once, PhoneNumber::national($once));
    }

    public function test_e164_round_trips(): void
    {
        $this->assertSame('+919876543210', PhoneNumber::e164('98765 43210'));
        $this->assertSame('+919876543210', PhoneNumber::e164('+919876543210'));
        $this->assertSame('', PhoneNumber::e164(''));
    }

    public function test_looks_valid_requires_ten_national_digits(): void
    {
        $this->assertTrue(PhoneNumber::looksValid('+91 98765 43210'));
        $this->assertFalse(PhoneNumber::looksValid('12345'));
        $this->assertFalse(PhoneNumber::looksValid('98765432100000'));
    }

    public function test_the_country_code_is_configurable(): void
    {
        config(['services.sms.country_code' => '1']);
        $this->assertSame('4155551234', PhoneNumber::national('+14155551234'));
    }
}
