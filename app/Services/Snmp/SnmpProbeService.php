<?php

namespace App\Services\Snmp;

use RuntimeException;
use Illuminate\Support\Str;

class SnmpProbeService
{
    protected string $timeout = '1';
    protected string $retries = '1';

    public function probe(string $ip, string $community, string $version = '2c'): array
    {
        $sysDescr = $this->getSysDescr($ip, $community, $version);
        $sysObjectId = $this->getSysObjectId($ip, $community, $version);

        $supportsPrinterMib = $this->supportsPrinterMib($ip, $community, $version);
        $suppliesTest = $this->testSuppliesLevel($ip, $community, $version);

        $capabilities = [
            'supports_printer_mib' => $supportsPrinterMib,
            'can_read_levels' => $suppliesTest === 'level',
            'can_read_states' => $supportsPrinterMib,
        ];

        return [
            'hostname' => $this->extractHostname($sysDescr),
            'brand' => $this->detectBrand($sysDescr),
            'model' => $this->detectModel($sysDescr),
            'sys_object_id' => $sysObjectId,

            'capabilities' => $capabilities,
            'monitoring_profile' => $this->decideMonitoringProfile($capabilities),
        ];
    }

    /* ---------------- SNMP BASE ---------------- */

    protected function snmpGet(string $ip, string $community, string $version, string $oid): ?string
    {
        $cmd = sprintf(
            'snmpget -v%s -c %s -t %s -r %s %s %s 2>/dev/null',
            escapeshellarg($version),
            escapeshellarg($community),
            $this->timeout,
            $this->retries,
            escapeshellarg($ip),
            escapeshellarg($oid)
        );

        $output = shell_exec($cmd);

        if (!$output) {
            return null;
        }

        return trim($output);
    }

    protected function snmpWalk(string $ip, string $community, string $version, string $oid): ?string
    {
        $cmd = sprintf(
            'snmpwalk -v%s -c %s -t %s -r %s %s %s 2>/dev/null',
            escapeshellarg($version),
            escapeshellarg($community),
            $this->timeout,
            $this->retries,
            escapeshellarg($ip),
            escapeshellarg($oid)
        );

        return shell_exec($cmd);
    }

    /* ---------------- DETECCIONES ---------------- */

    protected function getSysDescr(string $ip, string $community, string $version): string
    {
        $result = $this->snmpGet($ip, $community, $version, '1.3.6.1.2.1.1.1.0');

        if (!$result) {
            throw new RuntimeException('SNMP no responde (sysDescr)');
        }

        return $result;
    }

    protected function getSysObjectId(string $ip, string $community, string $version): ?string
    {
        return $this->snmpGet($ip, $community, $version, '1.3.6.1.2.1.1.2.0');
    }

    protected function supportsPrinterMib(string $ip, string $community, string $version): bool
    {
        $walk = $this->snmpWalk(
            $ip,
            $community,
            $version,
            '1.3.6.1.2.1.43.11.1.1.8'
        );

        return !empty($walk);
    }

    /**
     * Determina si los niveles son reales o basura (-2, -3)
     */
    protected function testSuppliesLevel(string $ip, string $community, string $version): string
    {
        $result = $this->snmpGet(
            $ip,
            $community,
            $version,
            '1.3.6.1.2.1.43.11.1.1.8.1'
        );

        if (!$result) {
            return 'unknown';
        }

        if (Str::contains($result, ['INTEGER: -2', 'INTEGER: -3'])) {
            return 'state';
        }

        if (Str::contains($result, 'INTEGER:')) {
            return 'level';
        }

        return 'unknown';
    }

    /* ---------------- INTERPRETACIÓN LIVIANA ---------------- */

    protected function decideMonitoringProfile(array $capabilities): string
    {
        if ($capabilities['can_read_levels']) {
            return 'level_real';
        }

        if ($capabilities['can_read_states']) {
            return 'estado';
        }

        return 'desconocido';
    }

    protected function extractHostname(string $sysDescr): ?string
    {
        return null; // opcional, no confiable
    }

    protected function detectBrand(string $sysDescr): ?string
    {
        if (Str::contains(Str::lower($sysDescr), 'hp')) {
            return 'HP';
        }

        if (Str::contains(Str::lower($sysDescr), 'brother')) {
            return 'Brother';
        }

        if (Str::contains(Str::lower($sysDescr), 'samsung')) {
            return 'Samsung';
        }

        return null;
    }

    protected function detectModel(string $sysDescr): ?string
    {
        return null; // mejorarlo después
    }
}
