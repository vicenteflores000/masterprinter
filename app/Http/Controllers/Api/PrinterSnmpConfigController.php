<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Printer;
use App\Models\PrinterSnmpConfig;
use Illuminate\Http\Request;

class PrinterSnmpConfigController extends Controller
{
    public function store(Request $request, Printer $printer)
    {
        $validated = $request->validate([
            'version'   => ['nullable', 'in:1,2c,3'],
            'community' => ['nullable', 'string'],
        ]);

        $config = PrinterSnmpConfig::updateOrCreate(
            ['printer_id' => $printer->id],
            [
                'version'   => $validated['version'] ?? '2c',
                'community' => $validated['community'] ?? 'public',
            ]
        );

        return response()->json([
            'message' => 'SNMP config saved',
            'data'    => $config
        ], 201);
    }
}
