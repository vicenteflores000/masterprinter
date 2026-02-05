<?php

namespace App\Services\Snmp;

use Symfony\Component\Process\Process;

class SnmpClient
{
    public function get(
        string $ip,
        string $oid,
        string $community = 'public',
        string $version = '2c',
        ?int $timeout = null,
        ?int $retries = null
    ): ?string
    {
        $args = [
            'snmpget',
            '-v',
            $version,
            '-c',
            $community,
        ];

        if ($timeout !== null) {
            $args[] = '-t';
            $args[] = (string) $timeout;
        }

        if ($retries !== null) {
            $args[] = '-r';
            $args[] = (string) $retries;
        }

        $args[] = $ip;
        $args[] = $oid;

        $process = new Process($args);

        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return $this->extractValue($process->getOutput());
    }

    public function walk(string $ip, string $oid, string $community = 'public', string $version = '2c'): ?string
    {
        $process = new Process([
            'snmpwalk',
            '-v',
            $version,
            '-c',
            $community,
            $ip,
            $oid
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return $process->getOutput();
    }

    protected function extractValue(string $output): string
    {
        // Divide por " = " y toma lo que viene después
        $parts = explode(' = ', trim($output), 2);

        return $parts[1] ?? trim($output);
    }
}
