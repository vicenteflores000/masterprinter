<?php

namespace App\Services\Printers;

use App\Models\Printer;

class MonitoringProfileResolver
{
    public function resolve(Printer $printer): string
    {
        return $this->resolveFromBrand($printer->brand);
    }

    public function resolveFromBrand(?string $brand): string
    {
        return match ($brand) {
            'hp'       => 'level_real',
            'brother'  => 'estado',
            'lexmark'  => 'estado',
            'samsung'  => 'level_real',
            default    => 'desconocido',
        };
    }
}
