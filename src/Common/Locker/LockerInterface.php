<?php
declare(strict_types=1);

namespace EonX\EasyLock\Common\Locker;

use Closure;
use EonX\EasyLock\Common\ValueObject\LockData;
use Symfony\Component\Lock\LockInterface;

interface LockerInterface
{
    public function createLock(string $resource, ?float $ttl = null): LockInterface;

    public function processWithLock(LockData $lockData, Closure $func): mixed;
}
