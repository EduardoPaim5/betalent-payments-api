<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $this->forceTestingEnvironment();

        return parent::createApplication();
    }

    private function forceTestingEnvironment(): void
    {
        $databaseConnection = (string) (getenv('TEST_DB_CONNECTION') ?: 'sqlite');
        $overrides = [
            'APP_ENV' => 'testing',
            'APP_MAINTENANCE_DRIVER' => 'file',
            'BCRYPT_ROUNDS' => '4',
            'BROADCAST_CONNECTION' => 'null',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => $databaseConnection,
            'DB_DATABASE' => $databaseConnection === 'sqlite'
                ? (string) (getenv('TEST_DB_DATABASE') ?: ':memory:')
                : (string) (getenv('TEST_DB_DATABASE') ?: getenv('DB_DATABASE') ?: 'betalent_test'),
            'DB_URL' => '',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
        ];

        if ($databaseConnection !== 'sqlite') {
            $overrides['DB_HOST'] = (string) (getenv('TEST_DB_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1');
            $overrides['DB_PORT'] = (string) (getenv('TEST_DB_PORT') ?: getenv('DB_PORT') ?: '3306');
            $overrides['DB_USERNAME'] = (string) (getenv('TEST_DB_USERNAME') ?: getenv('DB_USERNAME') ?: 'root');
            $overrides['DB_PASSWORD'] = (string) (getenv('TEST_DB_PASSWORD') ?: getenv('DB_PASSWORD') ?: '');
        }

        foreach ($overrides as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        if ($databaseConnection === 'sqlite') {
            foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
    }
}
