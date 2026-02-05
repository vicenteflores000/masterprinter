<?php

namespace App\Jobs;

use App\Models\Printer;
use App\Services\Snmp\SnmpDiscoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RevalidatePrinterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $printerId,
        public ?string $subnet = null,
        public int $maxConcurrent = 0
    ) {}

    public function handle(SnmpDiscoveryService $snmp): void
    {
        $this->applySubnetConcurrency();

        $printer = Printer::find($this->printerId);

        if (! $printer) {
            return;
        }

        $startedAt = microtime(true);

        try {
            $snmp->discover($printer);
        } catch (\Throwable $e) {
            // discover already marks is_active=false on unreachable
        } finally {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $avgMs = $this->calculateAverageDuration(
                $printer->avg_check_duration_ms,
                $durationMs
            );

            $printer->update([
                'last_checked_at' => now(),
                'last_check_duration_ms' => $durationMs,
                'avg_check_duration_ms' => $avgMs,
            ]);
            $this->releaseSubnetLock();
        }
    }

    protected function applySubnetConcurrency(): void
    {
        if (! $this->subnet || $this->maxConcurrent <= 0) {
            return;
        }

        $key = $this->lockKey();
        $current = cache()->increment($key, 1);
        cache()->put($key, $current, now()->addMinutes(30));

        if ($current > $this->maxConcurrent) {
            cache()->decrement($key, 1);
            $this->release(60);
        }
    }

    protected function releaseSubnetLock(): void
    {
        if (! $this->subnet || $this->maxConcurrent <= 0) {
            return;
        }

        cache()->decrement($this->lockKey(), 1);
    }

    protected function calculateAverageDuration(?int $currentAvg, int $durationMs): int
    {
        if ($currentAvg === null) {
            return $durationMs;
        }

        // Exponential moving average (80% previous, 20% new)
        return (int) round(($currentAvg * 0.8) + ($durationMs * 0.2));
    }

    protected function lockKey(): string
    {
        return 'printers:revalidate:subnet:' . $this->subnet;
    }
}
