<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrinterSnmpConfig extends Model
{
    protected $fillable = [
        'printer_id',
        'version',
        'community',
        'username',
        'auth_protocol',
        'auth_password',
        'priv_protocol',
        'priv_password',
    ];

    public function printer()
    {
        return $this->belongsTo(Printer::class);
    }
}
