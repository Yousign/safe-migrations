<?php

declare(strict_types=1);

namespace Yousign\SafeMigrations\Doctrine\DBAL\Driver\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Psr\Log\LoggerInterface;

final readonly class RetryLockTimeoutMiddleware implements Middleware
{
    public function __construct(
        private LoggerInterface $logger,
        private bool $isEnabled,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        if ($this->isEnabled) {
            return new RetryLockTimeoutDriver($driver, $this->logger);
        }

        return $driver;
    }
}
