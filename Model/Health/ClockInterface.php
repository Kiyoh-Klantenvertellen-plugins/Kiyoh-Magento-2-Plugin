<?php

namespace Kiyoh\Reviews\Model\Health;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
