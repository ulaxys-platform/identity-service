<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Feature\Identity;

use Ulaxys\Identity\Services\Identity\PasswordPolicy;
use Ulaxys\Identity\Tests\TestCase;

final class PasswordPolicyTest extends TestCase
{
    private PasswordPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new PasswordPolicy;
    }

    public function test_accepts_a_strong_password_within_bounds(): void
    {
        $this->assertSame([], $this->policy->violations('s3ntence-Staple-Calm-9'));
    }

    public function test_rejects_passwords_below_the_minimum_length(): void
    {
        $this->assertContains(PasswordPolicy::TOO_SHORT, $this->policy->violations('short-pass-'));
    }

    public function test_rejects_passwords_above_the_maximum_length(): void
    {
        $this->assertContains(PasswordPolicy::TOO_LONG, $this->policy->violations(str_repeat('a', 129)));
    }

    public function test_rejects_blocklisted_common_passwords_case_insensitively(): void
    {
        $this->assertContains(PasswordPolicy::BLOCKLISTED, $this->policy->violations('Password123'));
    }

    public function test_rejects_passwords_containing_identifier_seeds(): void
    {
        $violations = $this->policy->violations('ahmet-super-secret-99', ['ahmet']);
        $this->assertContains(PasswordPolicy::IDENTIFIER_SIMILAR, $violations);
        $this->assertSame([], $this->policy->violations('s3ntence-Staple-Calm-9', ['ahmet']));
    }

    public function test_short_seeds_never_trigger_identifier_similarity(): void
    {
        $this->assertSame([], $this->policy->violations('s3ntence-Staple-Calm-9', ['ab']));
    }
}
