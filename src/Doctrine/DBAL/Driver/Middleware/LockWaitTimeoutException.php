<?php

declare(strict_types=1);

namespace Yousign\SafeMigrations\Doctrine\DBAL\Driver\Middleware;

use Doctrine\DBAL\Driver\Exception as DriverException;

final class LockWaitTimeoutException extends \RuntimeException implements DriverException
{
    public function __construct(private readonly DriverException $driverException)
    {
        parent::__construct(
            'Cannot retry lock timeout from a SQL transaction: '.$driverException->getMessage(),
            $driverException->getCode(),
            $driverException
        );
    }

    public function getSQLState(): ?string
    {
        return $this->driverException->getSQLState();
    }
}
