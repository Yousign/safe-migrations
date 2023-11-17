<?php

declare(strict_types=1);

namespace Yousign\SafeMigrations\Doctrine\DBAL\Driver\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Psr\Log\LoggerInterface;

final class RetryLockTimeoutDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($driver);
    }

    public function connect(
        #[\SensitiveParameter] array $params
    ): RetryLockTimeoutConnection {
        return new RetryLockTimeoutConnection(parent::connect($params), $this->logger);
    }
}
