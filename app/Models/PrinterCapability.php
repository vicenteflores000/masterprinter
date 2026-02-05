<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrinterCapability extends Model
{
    protected $fillable = [
        'printer_id',
        'can_read_levels',
        'can_read_states',
        'supports_printer_mib',
        'notes',
        'detected_at',
    ];

    protected $casts = [
        'can_read_levels' => 'boolean',
        'can_read_states' => 'boolean',
        'supports_printer_mib' => 'boolean',
        'detected_at' => 'datetime',
    ];

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }
}
