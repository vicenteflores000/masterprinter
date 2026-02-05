<?php

namespace App\Services\Snmp;

use App\Models\Printer;
use InvalidArgumentException;

class SnmpIpResolverService
{
    public function __construct(
        protected SnmpClient $snmpClient
    ) {}

    public function resolveBySerial(string $subnet, string $community = 'public', string $version = '2c', int $maxHosts = 1024): array
    {
        [$networkIp, $maskBits] = $this->parseCidr($subnet);

        $hostCount = $this->countHosts($maskBits);

        if ($hostCount > $maxHosts) {
            throw new InvalidArgumentException('Subnet too large for resolve. Reduce range or increase max_hosts.');
        }

        $serialMap = $this->buildSerialMap();
        $matches = [];
        $scanned = 0;

        foreach ($this->iterateIps($networkIp, $maskBits) as $ip) {
            $scanned++;
            $serial = $this->getSerialNumber($ip, $community, $version);

            if (! $serial) {
                continue;
            }

            $key = $this->normalizeSerial($serial);

            if (! isset($serialMap[$key])) {
                continue;
            }

            $printer = $serialMap[$key];
            $printer->update([
                'ip' => $ip,
                'is_active' => true,
            ]);

            $matches[] = [
                'printer_id' => $printer->id,
                'serial_number' => $printer->serial_number,
                'ip' => $ip,
            ];
        }

        return [
            'subnet' => $subnet,
            'scanned' => $scanned,
            'matches' => $matches,
        ];
    }

    protected function buildSerialMap(): array
    {
        return Printer::query()
            ->whereNotNull('serial_number')
            ->get()
            ->keyBy(fn (Printer $printer) => $this->normalizeSerial($printer->serial_number))
            ->all();
    }

    protected function getSerialNumber(string $ip, string $community, string $version): ?string
    {
        $raw = $this->snmpClient->get(
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

    protected function normalizeSerial(string $serial): string
    {
        return strtolower(trim($serial));
    }

    protected function parseCidr(string $subnet): array
    {
        if (! str_contains($subnet, '/')) {
            throw new InvalidArgumentException('Subnet must be CIDR like 192.168.1.0/24');
        }

        [$ip, $mask] = explode('/', $subnet, 2);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('Subnet IP must be IPv4');
        }

        $maskBits = (int) $mask;

        if ($maskBits < 0 || $maskBits > 32) {
            throw new InvalidArgumentException('Subnet mask must be between 0 and 32');
        }

        return [$ip, $maskBits];
    }

    protected function countHosts(int $maskBits): int
    {
        if ($maskBits >= 31) {
            return 2 ** (32 - $maskBits);
        }

        return max(0, (2 ** (32 - $maskBits)) - 2);
    }

    protected function iterateIps(string $ip, int $maskBits): \Generator
    {
        $ipLong = ip2long($ip);
        $mask = $maskBits === 0 ? 0 : (-1 << (32 - $maskBits));
        $network = $ipLong & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);

        $start = $maskBits >= 31 ? $network : $network + 1;
        $end = $maskBits >= 31 ? $broadcast : $broadcast - 1;

        for ($current = $start; $current <= $end; $current++) {
            yield long2ip($current);
        }
    }
}
