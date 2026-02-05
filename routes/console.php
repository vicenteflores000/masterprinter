<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\Printer;
use App\Services\Snmp\SnmpDiscoveryService;
use App\Jobs\RevalidatePrintersJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('printers:refresh-identities {--only-empty : Only update printers without serial_number or mac_address}', function () {
    $this->comment('Refreshing printer identities (serial_number, mac_address)...');

    $onlyEmpty = (bool) $this->option('only-empty');

    $query = Printer::query();

    if ($onlyEmpty) {
        $query->where(function ($q) {
            $q->whereNull('serial_number')
              ->orWhereNull('mac_address');
        });
    }

    $total = $query->count();
    $this->comment("Found {$total} printers to process.");

    if ($total === 0) {
        $this->comment('Nothing to do.');
        return;
    }

    $snmp = app(SnmpDiscoveryService::class);
    $processed = 0;
    $errors = 0;

    $query->orderBy('id')->chunk(25, function ($printers) use (&$processed, &$errors, $snmp) {
        foreach ($printers as $printer) {
            try {
                $snmp->discover($printer);
                $processed++;
                $this->info("OK  Printer #{$printer->id} {$printer->ip}");
            } catch (\Throwable $e) {
                $errors++;
                $this->error("ERR Printer #{$printer->id} {$printer->ip} - {$e->getMessage()}");
            }
        }
    });

    $this->comment("Done. Processed: {$processed}, Errors: {$errors}");
})->purpose('Refresh serial_number and mac_address for existing printers');

Artisan::command('printers:revalidate-queue {--subnet= : Subnet label for concurrency locks} {--max-concurrent=0 : Max concurrent jobs per subnet}', function () {
    $subnet = $this->option('subnet') ?: null;
    $maxConcurrent = (int) ($this->option('max-concurrent') ?? 0);

    RevalidatePrintersJob::dispatch($maxConcurrent, $subnet);

    $this->comment('Revalidation jobs dispatched.');
})->purpose('Enqueue printer revalidation jobs');

Schedule::job(new RevalidatePrintersJob())
    ->dailyAt('02:00');

Schedule::call(function () {
    $days = (int) env('PRINTER_INACTIVE_DAYS', 7);
    $threshold = now()->subDays($days);

    Printer::query()
        ->where(function ($q) use ($threshold) {
            $q->whereNotNull('last_checked_at')
              ->where('last_checked_at', '<', $threshold);
        })
        ->orWhere(function ($q) use ($threshold) {
            $q->whereNull('last_checked_at')
              ->where('created_at', '<', $threshold);
        })
        ->update([
            'is_active' => false,
        ]);
})->dailyAt('03:00')
  ->name('printers:mark-stale');
