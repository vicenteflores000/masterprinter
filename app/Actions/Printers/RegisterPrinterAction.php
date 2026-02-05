<?php

namespace App\Actions\Printers;

use App\Models\Printer;
use App\Models\PrinterSnmpConfig;
use App\Services\Snmp\SnmpProbeService;
use App\Services\Snmp\SnmpDiscoveryService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RegisterPrinterAction
{
    public function __construct(
        protected SnmpProbeService $snmpProbe,
        protected SnmpDiscoveryService $snmpDiscovery
    ) {}

    public function execute(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $community = $data['community'] ?? 'public';
            $version = $data['snmp_version'] ?? '2c';

            $warning = null;
            $reachable = false;

            $printer = Printer::updateOrCreate(
                ['ip' => $data['ip']],
                [
                    'location' => $data['location'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]
            );

            PrinterSnmpConfig::updateOrCreate(
                ['printer_id' => $printer->id],
                [
                    'version' => $version,
                    'community' => $community,
                ]
            );

            try {
                $probeResult = $this->snmpProbe->probe(
                    ip: $data['ip'],
                    community: $community,
                    version: $version
                );

                $printer->capabilities()->updateOrCreate(
                    [],
                    [
                        'can_read_levels' => $probeResult['capabilities']['can_read_levels'],
                        'can_read_states' => $probeResult['capabilities']['can_read_states'],
                        'supports_printer_mib' => $probeResult['capabilities']['supports_printer_mib'],
                        'detected_at' => now(),
                    ]
                );

                $discover = $this->snmpDiscovery->discover($printer);
                $reachable = (bool) ($discover['reachable'] ?? true);

                if ($reachable === false) {
                    $warning = $discover['message'] ?? 'SNMP not reachable';
                }
            } catch (RuntimeException $e) {
                $warning = $e->getMessage();
                $printer->update(['is_active' => false]);
                $reachable = false;
            }

            return [
                'printer' => $printer->fresh(),
                'warning' => $warning,
                'reachable' => $reachable,
            ];
        });
    }
}
