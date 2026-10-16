<?php

declare(strict_types=1);

namespace LockSend\Support;

class Clock
{
    public function now(): int
    {
        return time();
    }
}
