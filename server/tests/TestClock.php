<?php

declare(strict_types=1);

namespace LockSend\Tests;

use LockSend\Support\Clock;

final class TestClock extends Clock
{
    public int $t = 1_800_000_000;

    public function now(): int
    {
        return $this->t;
    }
}
