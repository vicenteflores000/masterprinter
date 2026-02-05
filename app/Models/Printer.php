<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Printer extends Model
{
    protected $fillable = [
        'ip',
        'mac_address',
        'serial_number',
        'hostname',
        'brand',
        'model',
        'sys_object_id',
        'monitoring_profile',
        'location',
        'notes',
        'is_active',
        'last_checked_at',
        'last_check_duration_ms',
        'avg_check_duration_ms',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_checked_at' => 'datetime',
        'last_check_duration_ms' => 'integer',
        'avg_check_duration_ms' => 'integer',
    ];

    /**
     * Helpers de dominio (no lógica pesada)
     */
    public function usesLevelMonitoring(): bool
    {
        return $this->monitoring_profile === 'level_real';
    }

    public function usesStateMonitoring(): bool
    {
        return $this->monitoring_profile === 'estado';
    }

    public function hasUnknownMonitoring(): bool
    {
        return $this->monitoring_profile === 'desconocido';
    }

    public function capabilities(): HasOne
    {
        return $this->hasOne(PrinterCapability::class);
    }

    public function snmpConfig()
    {
        return $this->hasOne(PrinterSnmpConfig::class);
    }
}
