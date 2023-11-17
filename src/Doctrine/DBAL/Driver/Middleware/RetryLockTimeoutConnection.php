<?php

declare(strict_types=1);

namespace Yousign\SafeMigrations\Doctrine\DBAL\Driver\Middleware;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Psr\Log\LoggerInterface;

final class RetryLockTimeoutConnection extends AbstractConnectionMiddleware
{
    private const RETRY_DELAY_SECONDS = 10;
    private const RETRY_MAX_ATTEMPT = 3;

    public function __construct(
        Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($connection);
    }

    // In migration context, only this method is used, that's why the retry behavior is not implemented for
    // prepare() and exec() methods.
    public function query(string $sql): Result
    {
        static $counterLockTimeoutReached = 0;

        try {
            $result = parent::query($sql);
        } catch (DriverException $e) {
            // See https://www.postgresql.org/docs/current/errcodes-appendix.html
            // 55P03 	lock_not_available
            if ($counterLockTimeoutReached < self::RETRY_MAX_ATTEMPT && '55P03' === $e->getSQLState()) {
                ++$counterLockTimeoutReached;
                if (($nativeConnection = $this->getNativeConnection()) instanceof \PDO && $nativeConnection->inTransaction()) {
                    throw new LockWaitTimeoutException($e);
                }

                $this->logger->warning(sprintf(
                    '(%s/%d) Lock timeout reached: retrying in %d seconds...',
                    $counterLockTimeoutReached,
                    self::RETRY_MAX_ATTEMPT,
                    self::RETRY_DELAY_SECONDS,
                ), ['sql' => $sql]);
                sleep(self::RETRY_DELAY_SECONDS);

                return $this->query($sql);
            }

            throw $e;
        }

        $counterLockTimeoutReached = 0;

        return $result;
    }
}
