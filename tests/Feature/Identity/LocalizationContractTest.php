<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Feature\Identity;

use Ulaxys\Identity\Tests\TestCase;

final class LocalizationContractTest extends TestCase
{
    public function test_identity_message_files_exist_for_en_tr_ar(): void
    {
        foreach (['en', 'tr', 'ar'] as $locale) {
            $path = base_path('lang/'.$locale.'/identity.php');
            $this->assertFileExists($path);
            $messages = require $path;
            $this->assertArrayHasKey('auth', $messages);
            $this->assertArrayHasKey('credentials', $messages);
        }
    }

    public function test_identity_message_key_sets_are_aligned_across_locales(): void
    {
        $en = require base_path('lang/en/identity.php');
        foreach (['tr', 'ar'] as $locale) {
            $messages = require base_path('lang/'.$locale.'/identity.php');
            $this->assertSame($this->flatten($en), $this->flatten($messages), 'Localization key mismatch for '.$locale);
        }
    }

    public function test_all_message_values_are_nonempty_and_untranslated_identifiers_stay_stable(): void
    {
        foreach (['en', 'tr', 'ar'] as $locale) {
            foreach ($this->flatten(require base_path('lang/'.$locale.'/identity.php')) as $key => $value) {
                $this->assertIsString($value, $key.' must map to a string for '.$locale);
                $this->assertNotSame('', trim($value), $key.' must not be empty for '.$locale);
            }
        }
    }

    public function test_validation_subsets_exist_for_tr_and_ar(): void
    {
        foreach (['tr', 'ar'] as $locale) {
            $messages = require base_path('lang/'.$locale.'/validation.php');
            foreach (['required', 'string', 'max', 'min', 'in', 'email', 'timezone', 'regex'] as $rule) {
                $this->assertArrayHasKey($rule, $messages, 'Missing validation message '.$rule.' for '.$locale);
            }
        }
    }

    /** @return array<string, string> */
    private function flatten(array $messages, string $prefix = ''): array
    {
        $flat = [];
        foreach ($messages as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $flat += $this->flatten($value, $path);
                continue;
            }
            $flat[$path] = $value;
        }

        return $flat;
    }
}
