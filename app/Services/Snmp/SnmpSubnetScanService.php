<?php

namespace App\Services\Snmp;

use InvalidArgumentException;

class SnmpSubnetScanService
{
    public function __construct(
        protected SnmpClient $snmpClient
    ) {}

    public function scan(string $subnet, string $community = 'public', string $version = '2c', int $maxHosts = 256): array
    {
        [$networkIp, $maskBits] = $this->parseCidr($subnet);
        $hostCount = $this->countHosts($maskBits);

        if ($hostCount > $maxHosts) {
            throw new InvalidArgumentException('Subnet too large for scan. Reduce range or increase max_hosts.');
        }

        $detected = [];
        $scanned = 0;

        foreach ($this->iterateIps($networkIp, $maskBits) as $ip) {
            $scanned++;

            $sysDescr = $this->snmpClient->get($ip, '1.3.6.1.2.1.1.1.0', $community, $version);
            if (! $sysDescr) {
                continue;
            }

            $sysObjectId = $this->snmpClient->get($ip, '1.3.6.1.2.1.1.2.0', $community, $version);

            if (! $this->looksLikePrinter($ip, $sysDescr, $sysObjectId, $community, $version)) {
                continue;
            }

            $detected[] = [
                'ip' => $ip,
                'sys_descr' => $sysDescr,
                'sys_object_id' => $sysObjectId,
                'vendor_guess' => $this->guessVendor($sysDescr),
            ];
        }

        return [
            'subnet' => $subnet,
            'scanned' => $scanned,
            'detected' => $detected,
        ];
    }

    protected function looksLikePrinter(string $ip, string $sysDescr, ?string $sysObjectId, string $community, string $version): bool
    {
        $d = strtolower($sysDescr);

        if (str_contains($d, 'printer') || str_contains($d, 'laserjet') || str_contains($d, 'mfp')) {
            return true;
        }

        if (preg_match('/hp|brother|lexmark|ricoh|kyocera|canon|xerox|epson|konica|minolta/', $d)) {
            return true;
        }

        $serial = $this->snmpClient->get($ip, '1.3.6.1.2.1.43.5.1.1.17.1', $community, $version);
        if ($serial) {
            return true;
        }

        $supplies = $this->snmpClient->get($ip, '1.3.6.1.2.1.43.11.1.1.4.1.1', $community, $version);

        return $supplies !== null;
    }

    protected function guessVendor(string $sysDescr): ?string
    {
        $d = strtolower($sysDescr);

        return match (true) {
            str_contains($d, 'hp') => 'hp',
            str_contains($d, 'brother') => 'brother',
            str_contains($d, 'lexmark') => 'lexmark',
            str_contains($d, 'ricoh') => 'ricoh',
            str_contains($d, 'kyocera') => 'kyocera',
            str_contains($d, 'canon') => 'canon',
            str_contains($d, 'xerox') => 'xerox',
            str_contains($d, 'epson') => 'epson',
            str_contains($d, 'konica') || str_contains($d, 'minolta') => 'konica_minolta',
            default => null,
        };
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
