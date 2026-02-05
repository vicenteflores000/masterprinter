<?php

namespace App\Jobs;

use App\Services\Snmp\SnmpClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class ScanSubnetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $scanId,
        public string $subnet,
        public string $community = 'public',
        public string $version = '2c',
        public int $maxHosts = 1024,
        public bool $usePing = false
    ) {}

    public function handle(SnmpClient $snmp): void
    {
        try {
            $this->updateScan(['status' => 'running', 'started_at' => now()->toIso8601String()]);

            [$networkIp, $maskBits] = $this->parseCidr($this->subnet);
            $hostCount = $this->countHosts($maskBits);

            if ($hostCount > $this->maxHosts) {
                throw new InvalidArgumentException('Subnet too large for scan. Reduce range or increase max_hosts.');
            }

            $detected = [];
            $scanned = 0;

            foreach ($this->iterateIps($networkIp, $maskBits) as $ip) {
                $scanned++;

                if ($this->usePing && ! $this->safePingHost($ip)) {
                    $this->updateProgress($scanned, $hostCount, $detected);
                    continue;
                }

                $sysDescr = $snmp->get($ip, '1.3.6.1.2.1.1.1.0', $this->community, $this->version, 1, 0);
                if (! $sysDescr) {
                    $this->updateProgress($scanned, $hostCount, $detected);
                    continue;
                }

                $sysObjectId = $snmp->get($ip, '1.3.6.1.2.1.1.2.0', $this->community, $this->version, 1, 0);

                if (! $this->looksLikePrinter($ip, $sysDescr, $sysObjectId, $snmp)) {
                    $this->updateProgress($scanned, $hostCount, $detected);
                    continue;
                }

                $detected[] = [
                    'ip' => $ip,
                    'sys_descr' => $sysDescr,
                    'sys_object_id' => $sysObjectId,
                    'vendor_guess' => $this->guessVendor($sysDescr),
                ];

                $this->updateProgress($scanned, $hostCount, $detected);
            }

            $this->updateScan([
                'status' => 'done',
                'finished_at' => now()->toIso8601String(),
                'subnet' => $this->subnet,
                'scanned' => $scanned,
                'total' => $hostCount,
                'detected' => $detected,
            ]);
        } catch (\Throwable $e) {
            $this->updateScan([
                'status' => 'failed',
                'finished_at' => now()->toIso8601String(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function updateProgress(int $scanned, int $total, array $detected): void
    {
        if ($scanned % 10 !== 0) {
            return;
        }

        $this->updateScan([
            'subnet' => $this->subnet,
            'scanned' => $scanned,
            'total' => $total,
            'detected' => $detected,
        ]);
    }

    protected function updateScan(array $data): void
    {
        $key = $this->scanCacheKey();
        $current = cache()->get($key, []);
        $merged = array_merge($current, $data);
        cache()->put($key, $merged, now()->addHours(6));
    }

    protected function scanCacheKey(): string
    {
        return 'printers:scan:' . $this->scanId;
    }

    protected function safePingHost(string $ip): bool
    {
        $command = $this->pingCommand($ip);
        $process = new Process($command);
        $process->setTimeout(null);
        try {
            $process->run();
        } catch (\Throwable $e) {
            return false;
        }

        return $process->isSuccessful();
    }

    protected function pingCommand(string $ip): array
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return ['ping', '-c', '1', '-o', '-W', '1000', $ip];
        }

        return ['ping', '-c', '1', '-W', '1', $ip];
    }

    protected function looksLikePrinter(string $ip, string $sysDescr, ?string $sysObjectId, SnmpClient $snmp): bool
    {
        $d = strtolower($sysDescr);

        if (str_contains($d, 'printer') || str_contains($d, 'laserjet') || str_contains($d, 'mfp')) {
            return true;
        }

        if (preg_match('/hp|brother|lexmark|ricoh|kyocera|canon|xerox|epson|konica|minolta/', $d)) {
            return true;
        }

        $serial = $snmp->get($ip, '1.3.6.1.2.1.43.5.1.1.17.1', $this->community, $this->version, 1, 0);
        if ($serial) {
            return true;
        }

        $supplies = $snmp->get($ip, '1.3.6.1.2.1.43.11.1.1.4.1.1', $this->community, $this->version, 1, 0);

        return $supplies !== null;
    }

    protected function guessVendor(string $sysDescr): ?string
    {
        $d = strtolower($sysDescr);

        return match (true) {
            str_contains($d, 'hp') => 'hp',
            str_contains($d, 'brother') => 'brother',
            str_contains($d, 'lexmark') => 'lexmark',
            str_contains($d, 'samsung') => 'samsung',
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
