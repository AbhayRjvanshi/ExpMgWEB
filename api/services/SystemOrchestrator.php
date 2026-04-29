<?php

declare(strict_types=1);

require_once __DIR__ . '/PredictiveHealthService.php';

/**
 * SystemOrchestrator
 *
 * Central decision point for adaptive system behavior.
 * No caller should read predictive health directly when this orchestrator is available.
 */
class SystemOrchestrator
{
    public function getSystemMode(): string
    {
        $score = PredictiveHealthService::getScore();

        if ($score >= 80) {
            return 'normal';
        }

        if ($score >= 60) {
            return 'cautious';
        }

        if ($score >= 40) {
            return 'degraded';
        }

        return 'critical';
    }

    public function getRetryPolicy(): array
    {
        return match ($this->getSystemMode()) {
            'normal' => ['retries' => 2, 'delay' => 100],
            'cautious' => ['retries' => 3, 'delay' => 300],
            'degraded' => ['retries' => 5, 'delay' => 800],
            'critical' => ['retries' => 1, 'delay' => 1500],
            default => ['retries' => 2, 'delay' => 100],
        };
    }

    public function shouldFallback(): bool
    {
        return $this->getSystemMode() === 'critical';
    }

    public function getConcurrencyLimit(): int
    {
        return match ($this->getSystemMode()) {
            'normal' => 10,
            'cautious' => 6,
            'degraded' => 3,
            'critical' => 1,
            default => 6,
        };
    }

    public function getModeSnapshot(): array
    {
        return [
            'mode' => $this->getSystemMode(),
            'score' => PredictiveHealthService::getScore(),
            'retry_policy' => $this->getRetryPolicy(),
            'concurrency_limit' => $this->getConcurrencyLimit(),
            'fallback' => $this->shouldFallback(),
        ];
    }
}