<?php
declare(strict_types=1);

namespace EonX\EasyLock;

use Closure;
use Doctrine\DBAL\Driver\PDO\Exception as PdoException;
use EonX\EasyAsync\Common\Exception\ShouldKillWorkerExceptionInterface;
use EonX\EasyLock\Bridge\EasyAsync\Exceptions\LockAcquiringException;
use EonX\EasyLock\Exceptions\ShouldRetryException;
use EonX\EasyLock\Interfaces\LockDataInterface;
use EonX\EasyLock\Interfaces\LockServiceInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\Exception\LockAcquiringException as BaseLockAcquiringException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\PersistingStoreInterface;

final class LockService implements LockServiceInterface
{
    private ?LockFactory $factory = null;

    public function __construct(
        private PersistingStoreInterface $store,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function createLock(string $resource, ?float $ttl = null): LockInterface
    {
        return $this->getFactory()
            ->createLock($resource, $ttl ?? 300.0);
    }

    public function processWithLock(LockDataInterface $lockData, Closure $func): mixed
    {
        $lock = $this->createLock($lockData->getResource(), $lockData->getTtl());

        try {
            $lockAcquired = $lock->acquire();
        } catch (BaseLockAcquiringException $exception) {
            $previous = $exception->getPrevious();
            $easyAsyncInstalled = \interface_exists(ShouldKillWorkerExceptionInterface::class);

            // If eonx-com/easy-async installed, and previous is because SQL connection not ok, kill worker
            if ($easyAsyncInstalled && $previous instanceof PdoException && $previous->getCode() === 0) {
                throw new LockAcquiringException($exception->getMessage(), $exception->getCode(), $previous);
            }

            throw $exception;
        }

        if ($lockAcquired === false) {
            // Throw exception to indicate we want ot retry
            if ($lockData->shouldRetry()) {
                throw new ShouldRetryException(\sprintf('Should retry "%s"', $lockData->getResource()));
            }

            return null;
        }

        try {
            return $func();
        } finally {
            $lock->release();
        }
    }

    private function getFactory(): LockFactory
    {
        if ($this->factory !== null) {
            return $this->factory;
        }

        $this->factory = new LockFactory($this->store);
        $this->factory->setLogger($this->logger);

        return $this->factory;
    }
}
