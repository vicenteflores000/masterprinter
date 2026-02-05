<?php

namespace App\Services\Snmp;

use App\Models\Printer;
use App\Services\Printers\MonitoringProfileResolver;

class SnmpDiscoveryService
{
    public function __construct(
        protected SnmpClient $client
    ) {}

    public function discover(Printer $printer): array
    {
        // 1️⃣ Validar config SNMP
        $config = $printer->snmpConfig;

        if (! $config) {
            return [
                'reachable' => false,
                'message'   => 'SNMP config not defined'
            ];
        }

        // 2️⃣ sysDescr
        $sysDescr = $this->client->get(
            $printer->ip,
            '1.3.6.1.2.1.1.1.0',
            $config->community,
            $config->version
        );

        if (! $sysDescr) {
            $printer->update(['is_active' => false]);

            return [
                'reachable' => false,
                'message'   => 'SNMP not reachable'
            ];
        }

        // 3️⃣ sysObjectID
        $sysObjectId = $this->client->get(
            $printer->ip,
            '1.3.6.1.2.1.1.2.0',
            $config->community,
            $config->version
        );

        // 3.1️⃣ MAC address (best effort)
        $macAddress = $this->getMacAddress(
            $printer->ip,
            $config->community,
            $config->version
        );

        // 3.2️⃣ Serial number (best effort)
        $serialNumber = $this->getSerialNumber(
            $printer->ip,
            $config->community,
            $config->version
        );

        // 4️⃣ Detectar marca / modelo (simple por ahora)
        [$brand, $model] = $this->detectBrandAndModel($sysDescr, $sysObjectId);

        $profile = app(MonitoringProfileResolver::class)->resolveFromBrand($brand);

        $printer->update([
            'brand'               => $brand,
            'model'               => $model,
            'sys_object_id'       => $sysObjectId,
            'mac_address'         => $macAddress,
            'serial_number'       => $serialNumber,
            'monitoring_profile'  => $profile,
            'is_active'           => true,
        ]);


        return [
            'brand'               => $brand,
            'model'               => $model,
            'sys_object_id'       => $sysObjectId,
            'mac_address'         => $macAddress,
            'serial_number'       => $serialNumber,
            'monitoring_profile'  => $profile,
            'is_active'           => true,
        ];
    }

    /**
     * Detección básica, se refina después
     */
    protected function detectBrandAndModel(string $sysDescr, ?string $sysObjectId): array
    {
        if ($sysObjectId && str_contains($sysObjectId, 'enterprises.641')) {
            return ['lexmark', $this->extractLexmarkModel($sysDescr)];
        }

        if ($sysObjectId && str_contains($sysObjectId, 'enterprises.236')) {
            return ['samsung', $this->extractModel($sysDescr)];
        }

        $normalized = strtolower($sysDescr);

        if (str_contains($normalized, 'hp')) {
            return ['hp', $this->extractModel($sysDescr)];
        }

        if (str_contains($normalized, 'brother')) {
            return ['brother', $this->extractModel($sysDescr)];
        }

        if (str_contains($normalized, 'samsung')) {
            return ['samsung', $this->extractModel($sysDescr)];
        }

        return ['unknown', null];
    }

    protected function extractLexmarkModel(string $sysDescr): ?string
    {
        // Ej: "Lexmark MX611dhe version ..."
        if (preg_match('/Lexmark\s+([A-Z0-9]+)/i', $sysDescr, $matches)) {
            return $matches[1];
        }

        return null;
    }

    protected function extractModel(string $sysDescr): ?string
    {
        // versión MUY simple, intencional
        return trim($sysDescr);
    }

    protected function getSerialNumber(string $ip, string $community, string $version): ?string
    {
        $raw = $this->client->get(
            $ip,
            '1.3.6.1.2.1.43.5.1.1.17.1',
            $community,
            $version
        );

        if (! $raw) {
            return null;
        }

        return $this->extractSnmpString($raw);
    }

    protected function getMacAddress(string $ip, string $community, string $version): ?string
    {
        $walk = $this->client->walk(
            $ip,
            '1.3.6.1.2.1.2.2.1.6',
            $community,
            $version
        );

        $macs = [];

        if ($walk) {
            $macs = $this->extractMacsFromWalk($walk);
        }

        $best = $this->pickBestMac($macs);

        if ($best) {
            return $best;
        }

        $bridgeMac = $this->getBridgeMac($ip, $community, $version);

        if ($bridgeMac) {
            return $bridgeMac;
        }

        $arpMac = $this->getArpMac($ip, $community, $version);

        return $arpMac;
    }

    protected function getBridgeMac(string $ip, string $community, string $version): ?string
    {
        $raw = $this->client->get(
            $ip,
            '1.3.6.1.2.1.17.1.1.0',
            $community,
            $version
        );

        if (! $raw) {
            return null;
        }

        return $this->parseMacFromValue($raw);
    }

    protected function getArpMac(string $ip, string $community, string $version): ?string
    {
        $walk = $this->client->walk(
            $ip,
            '1.3.6.1.2.1.4.22.1.2',
            $community,
            $version
        );

        if (! $walk) {
            return null;
        }

        $macs = $this->extractMacsFromWalk($walk);

        return $this->pickBestMac($macs);
    }

    protected function extractMacsFromWalk(string $walk): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($walk));
        $macs = [];

        foreach ($lines as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [, $value] = array_map('trim', explode('=', $line, 2));
            $mac = $this->parseMacFromValue($value);

            if ($mac) {
                $macs[] = $mac;
            }
        }

        return $macs;
    }

    protected function parseMacFromValue(string $value): ?string
    {
        if (str_contains($value, 'Hex-STRING:')) {
            $hex = trim(str_replace('Hex-STRING:', '', $value));
            $parts = preg_split('/\s+/', $hex);
            $parts = array_filter($parts, fn ($p) => $p !== '');

            if (count($parts) >= 6) {
                $parts = array_map(fn ($p) => strtolower(str_pad($p, 2, '0', STR_PAD_LEFT)), $parts);
                return implode(':', $parts);
            }
        }

        if (preg_match('/([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}/', $value, $matches)) {
            return strtolower(str_replace('-', ':', $matches[0]));
        }

        return null;
    }

    protected function extractSnmpString(string $value): ?string
    {
        $trimmed = trim($value);

        if (str_starts_with($trimmed, 'STRING:')) {
            $trimmed = trim(substr($trimmed, 7));
        }

        if ($trimmed === '') {
            return null;
        }

        return trim($trimmed, '"');
    }

    protected function pickBestMac(array $macs): ?string
    {
        foreach ($macs as $mac) {
            if ($mac !== '00:00:00:00:00:00') {
                return $mac;
            }
        }

        return $macs[0] ?? null;
    }
}
