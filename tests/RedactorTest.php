<?php

namespace SolutionForest\Boop\Tests;

use SolutionForest\Boop\Redactor;

class RedactorTest extends TestCase
{
    public function test_default_keys_are_redacted(): void
    {
        $out = Redactor::apply([
            'password' => 'pw',
            'password_confirmation' => 'pw',
            'secret' => 's',
            'token' => 't',
            'access_token' => 'at',
            'refresh_token' => 'rt',
            'api_key' => 'k',
            'authorization' => 'bearer x',
            'cookie' => 'c',
            'set-cookie' => 'sc',
            'private_key' => 'pk',
        ]);

        $this->assertSame(array_fill_keys(array_keys($out), '[REDACTED]'), $out);
    }

    public function test_matching_is_case_insensitive(): void
    {
        $this->assertSame(['Password' => '[REDACTED]'], Redactor::apply(['Password' => 'x']));
        $this->assertSame(['API_KEY' => '[REDACTED]'], Redactor::apply(['API_KEY' => 'x']));
    }

    public function test_dash_and_underscore_are_equivalent(): void
    {
        $this->assertSame(['api-key' => '[REDACTED]'], Redactor::apply(['api-key' => 'x']));
        $this->assertSame(['api_key' => '[REDACTED]'], Redactor::apply(['api_key' => 'x']));
        $this->assertSame(['set-cookie' => '[REDACTED]'], Redactor::apply(['set-cookie' => 'x']));
        $this->assertSame(['set_cookie' => '[REDACTED]'], Redactor::apply(['set_cookie' => 'x']));
        $this->assertSame(['access-token' => '[REDACTED]'], Redactor::apply(['access-token' => 'x']));
        $this->assertSame(['access_token' => '[REDACTED]'], Redactor::apply(['access_token' => 'x']));
    }

    public function test_recurses_into_nested_arrays(): void
    {
        $out = Redactor::apply([
            'meta' => [
                'request' => ['authorization' => 'x'],
                'nested' => ['deep' => ['token' => 'y']],
            ],
        ]);

        $this->assertSame([
            'meta' => [
                'request' => ['authorization' => '[REDACTED]'],
                'nested' => ['deep' => ['token' => '[REDACTED]']],
            ],
        ], $out);
    }

    public function test_extra_keys_are_redacted(): void
    {
        $out = Redactor::apply(['email' => 'a@b.c', 'phone' => '123'], ['email']);

        $this->assertSame(['email' => '[REDACTED]', 'phone' => '123'], $out);
    }

    public function test_non_sensitive_values_pass_through(): void
    {
        $out = Redactor::apply([
            'title' => 'hello',
            'count' => 3,
            'enabled' => true,
            'nil' => null,
            'list' => [1, 2, 3],
        ]);

        $this->assertSame([
            'title' => 'hello',
            'count' => 3,
            'enabled' => true,
            'nil' => null,
            'list' => [1, 2, 3],
        ], $out);
    }
}
