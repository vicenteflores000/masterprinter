<?php

namespace App\Jobs;

use App\Models\Printer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RevalidatePrintersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $maxConcurrent = 0,
        public ?string $subnet = null
    ) {}

    public function handle(): void
    {
        Printer::query()
            ->orderBy('id')
            ->chunk(100, function ($printers) {
                foreach ($printers as $printer) {
                    RevalidatePrinterJob::dispatch(
                        printerId: $printer->id,
                        subnet: $this->subnet,
                        maxConcurrent: $this->maxConcurrent
                    );
                }
            });
    }
}
