<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Printer;
use App\Services\Snmp\SnmpConsumableService;
use App\Services\Snmp\SnmpClient;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PrinterSnmpController extends Controller
{
    public function reachable(
        Request $request,
        SnmpClient $snmpClient
    ): JsonResponse {
        $data = $request->validate([
            'ip' => ['required', 'ip'],
            'community' => ['nullable', 'string'],
            'version' => ['nullable', 'string'],
        ]);

        $ip = $data['ip'];
        $community = $data['community'] ?? 'public';
        $version = $data['version'] ?? '2c';

        $sysDescr = $snmpClient->get($ip, '1.3.6.1.2.1.1.1.0', $community, $version);

        return response()->json([
            'ip' => $ip,
            'reachable' => $sysDescr !== null,
            'sys_descr' => $sysDescr,
        ]);
    }

    public function consumables(
        Printer $printer,
        SnmpConsumableService $service
    ): JsonResponse {
        if (! $printer->snmpConfig) {
            return response()->json([
                'message' => 'SNMP config not defined for this printer'
            ], 422);
        }

        $data = $service->getConsumables($printer);

        return response()->json([
            'printer_id' => $printer->id,
            'data' => $data
        ]);
    }
}
