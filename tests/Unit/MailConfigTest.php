<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MailConfigTest extends TestCase
{
    #[DataProvider('legacyMailSchemeProvider')]
    public function test_mail_config_normalizes_legacy_smtp_scheme_values(
        string $configuredScheme,
        string $port,
        string $expectedScheme,
    ): void {
        $config = $this->mailConfigFor([
            'MAIL_SCHEME' => $configuredScheme,
            'MAIL_PORT' => $port,
        ]);

        $this->assertSame($expectedScheme, $config['mailers']['smtp']['scheme']);
    }

    public function test_mail_config_uses_mail_encryption_when_mail_scheme_is_not_set(): void
    {
        $config = $this->mailConfigFor([
            'MAIL_ENCRYPTION' => 'tls',
            'MAIL_PORT' => '587',
        ]);

        $this->assertSame('smtp', $config['mailers']['smtp']['scheme']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function legacyMailSchemeProvider(): array
    {
        return [
            'ssl on submission port' => ['ssl', '587', 'smtp'],
            'ssl on implicit tls port' => ['ssl', '465', 'smtps'],
            'tls on submission port' => ['tls', '587', 'smtp'],
            'starttls on submission port' => ['starttls', '587', 'smtp'],
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    private function mailConfigFor(array $overrides): array
    {
        $keys = ['MAIL_SCHEME', 'MAIL_ENCRYPTION', 'MAIL_PORT'];
        $originals = [];

        foreach ($keys as $key) {
            $originals[$key] = [
                'getenv' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env_value' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server_value' => $_SERVER[$key] ?? null,
            ];

            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        try {
            foreach ($overrides as $key => $value) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }

            /** @var array<string, mixed> $config */
            $config = require base_path('config/mail.php');

            return $config;
        } finally {
            foreach ($keys as $key) {
                $original = $originals[$key];

                if ($original['getenv'] === false) {
                    putenv($key);
                } else {
                    putenv("{$key}={$original['getenv']}");
                }

                if ($original['env_exists']) {
                    $_ENV[$key] = $original['env_value'];
                } else {
                    unset($_ENV[$key]);
                }

                if ($original['server_exists']) {
                    $_SERVER[$key] = $original['server_value'];
                } else {
                    unset($_SERVER[$key]);
                }
            }
        }
    }
}
