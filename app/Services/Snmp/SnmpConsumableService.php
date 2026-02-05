<?php

namespace App\Services\Snmp;

use App\Models\Printer;

class SnmpConsumableService
{

    protected SnmpClient $snmpClient;

    public function __construct(SnmpClient $snmpClient)
    {
        $this->snmpClient = $snmpClient;
    }

    public function getConsumables(Printer $printer): array
    {
        $ip = $printer->ip;
        $community = $printer->snmpConfig->community;

        return match ($printer->monitoring_profile) {
            'level_real' => $this->getLevelBasedConsumables($ip, $community),
            'estado'     => $this->getStateBasedConsumables($ip, $community),
            default      => [
                'mode' => 'desconocido',
                'data' => []
            ],
        };
    }

    protected function normalize(
        array $levels,
        array $max,
        array $classes = [],
        array $types = [],
        array $descriptions = []
    ): array
    {
        $consumables = [];

        foreach ($levels as $index => $value) {
            $current  = $this->extractInt($value) ?? 0;
            $capacity = $this->extractInt($max[$index] ?? null) ?? 0;
            $classValue = $this->extractInt($classes[$index] ?? null);
            $typeValue = $this->extractInt($types[$index] ?? null);
            $description = $this->extractString($descriptions[$index] ?? null);
            $type = $this->resolveType($classValue, $typeValue, $description);

            // Caso SNMP especial
            if ($current < 0) {
                $consumables[] = [
                    'color'    => $this->resolveColor($type, $description),
                    'type'     => $type,
                    'description' => $description,
                    'current'  => $current,
                    'capacity' => $capacity > 0 ? $capacity : null,
                    'percent'  => null,
                    'status'   => $this->statusFromSnmpLevel($current),
                    'raw_class' => $classValue,
                    'raw_type' => $typeValue,
                ];
                continue;
            }

            // Nivel normal
            if ($capacity <= 0) {
                $consumables[] = [
                    'color'    => $this->resolveColor($type, $description),
                    'type'     => $type,
                    'description' => $description,
                    'current'  => $current,
                    'capacity' => null,
                    'percent'  => null,
                    'status'   => 'UNKNOWN',
                    'raw_class' => $classValue,
                    'raw_type' => $typeValue,
                ];
                continue;
            }

            $percent = (int) round(($current / $capacity) * 100);

            $consumables[] = [
                'color'    => $this->resolveColor($type, $description),
                'type'     => $type,
                'description' => $description,
                'current'  => $current,
                'capacity' => $capacity,
                'percent'  => $percent,
                'status'   => $this->statusFromPercent($percent),
                'raw_class' => $classValue,
                'raw_type' => $typeValue,
            ];
        }

        return $consumables;
    }

    protected function statusFromSnmpLevel(int $level): string
    {
        return match ($level) {
            -3 => 'OK',        // someRemaining
            -2, -1 => 'UNKNOWN',
            default => 'UNKNOWN',
        };
    }

    protected function statusFromPercent(int $percent): string
    {
        return match (true) {
            $percent <= 5  => 'empty',
            $percent <= 15 => 'low',
            default        => 'ok',
        };
    }

    protected function getLevelBasedConsumables(string $ip, string $community): array
    {
        $levelsRaw = $this->normalizeWalkResult(
            $this->snmpClient->walk(
                $ip,
                '.1.3.6.1.2.1.43.11.1.1.9',
                $community
            )
        );

        $maxRaw = $this->normalizeWalkResult(
            $this->snmpClient->walk(
                $ip,
                '.1.3.6.1.2.1.43.11.1.1.8',
                $community
            )
        );

        $classes = $this->mapByIndex($this->normalizeWalkResult(
            $this->snmpClient->walk($ip, '.1.3.6.1.2.1.43.11.1.1.4', $community)
        ));

        $types = $this->mapByIndex($this->normalizeWalkResult(
            $this->snmpClient->walk($ip, '.1.3.6.1.2.1.43.11.1.1.5', $community)
        ));

        $descriptions = $this->mapByIndex($this->normalizeWalkResult(
            $this->snmpClient->walk($ip, '.1.3.6.1.2.1.43.11.1.1.6', $community)
        ));

        return [
            'mode' => 'level_real',
            'consumables' => $this->normalize(
                $this->mapByIndex($levelsRaw),
                $this->mapByIndex($maxRaw),
                $classes,
                $types,
                $descriptions
            ),
        ];
    }

    protected function getStateBasedConsumables(string $ip, string $community): array
    {
        $levels = $this->mapByIndex($this->normalizeWalkResult(
            $this->snmpClient->walk($ip, '.1.3.6.1.2.1.43.11.1.1.9', $community)
        ));

        $max = $this->mapByIndex($this->normalizeWalkResult(
            $this->snmpClient->walk($ip, '.1.3.6.1.2.1.43.11.1.1.8', $community)
        ));

        $classes = $this->mapByIndex($this->normalizeWalkResult(
            $this->snmpClient->walk($ip, '.1.3.6.1.2.1.43.11.1.1.4', $community)
        ));

        $types = $this->mapByIndex($this->normalizeWalkResult(
            $this->snmpClient->walk($ip, '.1.3.6.1.2.1.43.11.1.1.5', $community)
        ));

        $descriptions = $this->mapByIndex($this->normalizeWalkResult(
            $this->snmpClient->walk($ip, '.1.3.6.1.2.1.43.11.1.1.6', $community)
        ));

        return [
            'mode' => 'estado',
            'consumables' => $this->normalizeStateBasedConsumables(
                $levels,
                $max,
                $classes,
                $types,
                $descriptions
            ),
        ];
    }

    protected function normalizeStateBasedConsumables(
        array $levels,
        array $max,
        array $classes,
        array $types,
        array $descriptions
    ): array {
        $result = [];

        foreach ($levels as $index => $rawLevel) {
            $level = $this->extractInt($rawLevel);
            $capacity = $this->extractInt($max[$index] ?? null);
            $classValue = $this->extractInt($classes[$index] ?? null);
            $typeValue = $this->extractInt($types[$index] ?? null);
            $description = $this->extractString($descriptions[$index] ?? null);

            $result[] = [
                'type'  => $this->resolveType($classValue, $typeValue, $description),
                'state' => $this->resolveStateFromLevel($level, $capacity),
                'raw_class' => $classValue,
                'raw_type' => $typeValue,
                'raw_description' => $description,
                'raw_level' => $level,
                'raw_max' => $capacity,
                'index' => $index,
            ];
        }

        return $result;
    }

    protected function resolveType(?int $class, ?int $type, ?string $description): string
    {
        if ($type !== null) {
            $fromType = $this->mapSupplyType($type);
            if ($fromType !== 'unknown') {
                return $fromType;
            }
        }

        return match ($class) {
            3 => 'toner',
            4 => 'ink',
            7 => 'waste',
            8 => 'drum',
            default => $this->mapDescriptionToType($description),
        };
    }

    protected function mapSupplyType(int $type): string
    {
        return match ($type) {
            3  => 'toner',
            4  => 'waste',
            5, 6, 7 => 'ink',
            8  => 'waste',
            9  => 'drum',      // opc / photoconductor
            10 => 'developer',
            11 => 'fuser_oil',
            12, 13 => 'wax',
            14 => 'waste',
            15 => 'fuser',
            default => 'unknown',
        };
    }

    protected function mapDescriptionToType(?string $description): string
    {
        if (! $description) {
            return 'unknown';
        }

        $d = $this->normalizeDescription($description);

        return match (true) {
            str_contains($d, 'black') && str_contains($d, 'toner') => 'black_toner',
            str_contains($d, 'cyan')                               => 'cyan_toner',
            str_contains($d, 'magenta')                            => 'magenta_toner',
            str_contains($d, 'yellow')                             => 'yellow_toner',
            str_contains($d, 'unidad imagen')                      => 'drum',
            str_contains($d, 'imagen')                             => 'drum',
            str_contains($d, 'manten')                             => 'maintenance',
            str_contains($d, 'fuser')                              => 'fuser',
            str_contains($d, 'transfer') && str_contains($d, 'roller') => 'transfer_roller',
            str_contains($d, 'retard') && str_contains($d, 'roller')   => 'retard_roller',
            str_contains($d, 'roller')                             => 'roller',
            str_contains($d, 'drum')                               => 'drum',
            str_contains($d, 'waste')                              => 'waste',
            default                                                => 'unknown',
        };
    }

    protected function extractString(?string $raw): ?string
    {
        if (! $raw) {
            return null;
        }

        if (str_starts_with(trim($raw), 'STRING:')) {
            return trim(substr($raw, 7));
        }

        return null;
    }

    protected function mapState(?int $value): string
    {
        return match ($value) {
            3       => 'OK',
            4       => 'LOW',
            5       => 'EMPTY',
            1       => 'OTHER',
            2, null => 'UNKNOWN',
            default => 'UNKNOWN',
        };
    }

    protected function resolveStateFromLevel(?int $level, ?int $capacity): string
    {
        if ($level === null) {
            return 'UNKNOWN';
        }

        if ($level < 0) {
            return $this->statusFromSnmpLevel($level);
        }

        if ($capacity !== null && $capacity > 0) {
            return $this->statusFromPercent((int) round(($level / $capacity) * 100));
        }

        return 'UNKNOWN';
    }

    protected function resolveColor(string $type, ?string $description): ?string
    {
        if (str_contains($type, 'black')) {
            return 'black';
        }

        if (str_contains($type, 'cyan')) {
            return 'cyan';
        }

        if (str_contains($type, 'magenta')) {
            return 'magenta';
        }

        if (str_contains($type, 'yellow')) {
            return 'yellow';
        }

        if (! $description) {
            return null;
        }

        $d = $this->normalizeDescription($description);

        return match (true) {
            str_contains($d, 'black') => 'black',
            str_contains($d, 'cyan') => 'cyan',
            str_contains($d, 'magenta') => 'magenta',
            str_contains($d, 'yellow') => 'yellow',
            default => null,
        };
    }

    protected function extractInt(?string $raw): ?int
    {
        if (! $raw) {
            return null;
        }

        if (preg_match('/(-?\d+)/', $raw, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    protected function extractIndex(string $oid): ?string
    {
        // Supplies tables usually use two indices (e.g. 1.1, 1.2)
        if (preg_match('/(\d+\.\d+)$/', $oid, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\.(\d+)$/', $oid, $matches)) {
            return $matches[1];
        }

        return null;
    }

    protected function normalizeWalkResult($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $lines = preg_split('/\r\n|\r|\n/', trim($raw));
            $result = [];

            foreach ($lines as $line) {
                if (str_contains($line, '=')) {
                    [$oid, $value] = array_map('trim', explode('=', $line, 2));
                    $result[$oid] = $value;
                }
            }

            return $result;
        }

        return [];
    }

    protected function normalizeDescription(string $description): string
    {
        $d = strtolower(trim($description));

        $replace = [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
            'Á' => 'a',
            'É' => 'e',
            'Í' => 'i',
            'Ó' => 'o',
            'Ú' => 'u',
            'Ü' => 'u',
            'Ñ' => 'n',
            '?' => 'o',
        ];

        return strtr($d, $replace);
    }

    protected function mapByIndex(array $oidMap): array
    {
        $byIndex = [];

        foreach ($oidMap as $oid => $value) {
            $index = $this->extractIndex($oid);

            if ($index === null) {
                continue;
            }

            $byIndex[$index] = $value;
        }

        return $byIndex;
    }
}
