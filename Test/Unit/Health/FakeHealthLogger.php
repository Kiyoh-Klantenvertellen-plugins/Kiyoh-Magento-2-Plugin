<?php

namespace Kiyoh\Reviews\Test\Unit\Health;

use Kiyoh\Reviews\Model\Health\HealthLoggerInterface;

final class FakeHealthLogger implements HealthLoggerInterface
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public $entries = [];

    public function info(string $message, array $context = []): void
    {
        $this->entries[] = ['level' => 'info', 'message' => $message, 'context' => $context];
    }

    public function warning(string $message, array $context = []): void
    {
        $this->entries[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
    }

    public function error(string $message, array $context = []): void
    {
        $this->entries[] = ['level' => 'error', 'message' => $message, 'context' => $context];
    }

    public function containsMessage(string $message): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry['message'] === $message) {
                return true;
            }
        }

        return false;
    }
}
