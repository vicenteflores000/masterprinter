<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Actions\Printers\RegisterPrinterAction;
use App\Http\Requests\StorePrinterRequest;
use App\Services\Snmp\SnmpIpResolverService;
use App\Services\Snmp\SnmpDiscoveryService;
use App\Jobs\ScanSubnetJob;
use App\Models\Printer;
use InvalidArgumentException;
use RuntimeException;
use Illuminate\Support\Str;

class PrinterController extends Controller
{

    public function index()
    {
        $printers = Printer::orderBy('id')->get();

        return response()->json([
            'data' => $printers
        ]);
    }

    public function show(Printer $printer)
    {
        return response()->json([
            'data' => $printer
        ]);
    }

    public function store(
        StorePrinterRequest $request,
        RegisterPrinterAction $action
    ) {
        try {
            $result = $action->execute($request->validated());
            $printer = $result['printer'];
            $warning = $result['warning'] ?? null;
            $reachable = $result['reachable'] ?? null;

            return response()->json([
                'status' => 'ok',
                'data' => [
                    'id' => $printer->id,
                    'ip' => $printer->ip,
                    'mac_address' => $printer->mac_address,
                    'serial_number' => $printer->serial_number,
                    'brand' => $printer->brand,
                    'model' => $printer->model,
                    'monitoring_profile' => $printer->monitoring_profile,
                    'capabilities' => $printer->capabilities,
                ],
                'warning' => $warning,
                'reachable' => $reachable,
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function discover(Printer $printer, SnmpDiscoveryService $snmp)
    {
        $result = $snmp->discover($printer);

        return response()->json([
            'data' => $result
        ]);
    }

    public function resolveIp(
        \Illuminate\Http\Request $request,
        SnmpIpResolverService $resolver
    ) {
        $data = $request->validate([
            'subnet' => ['required', 'string'],
            'community' => ['nullable', 'string'],
            'version' => ['nullable', 'in:1,2c'],
            'max_hosts' => ['nullable', 'integer', 'min:1', 'max:65536'],
            'use_ping' => ['nullable', 'boolean'],
        ]);

        try {
            $result = $resolver->resolveBySerial(
                $data['subnet'],
                $data['community'] ?? 'public',
                $data['version'] ?? '2c',
                $data['max_hosts'] ?? 256
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'data' => $result,
        ]);
    }

    public function scanSubnet(
        \Illuminate\Http\Request $request
    ) {
        $data = $request->validate([
            'subnet' => ['required', 'string'],
            'community' => ['nullable', 'string'],
            'version' => ['nullable', 'in:1,2c'],
            'max_hosts' => ['nullable', 'integer', 'min:1', 'max:65536'],
        ]);

        $scanId = (string) Str::uuid();

        cache()->put('printers:scan:' . $scanId, [
            'status' => 'queued',
            'subnet' => $data['subnet'],
            'scanned' => 0,
            'total' => null,
            'detected' => [],
            'started_at' => null,
            'finished_at' => null,
        ], now()->addHours(6));

        ScanSubnetJob::dispatch(
            scanId: $scanId,
            subnet: $data['subnet'],
            community: $data['community'] ?? 'public',
            version: $data['version'] ?? '2c',
            maxHosts: $data['max_hosts'] ?? 1024,
            usePing: (bool) ($data['use_ping'] ?? false)
        );

        return response()->json([
            'status' => 'ok',
            'data' => [
                'scan_id' => $scanId,
                'status' => 'queued',
            ],
        ], 202);
    }

    public function scanStatus(string $scanId)
    {
        $data = cache()->get('printers:scan:' . $scanId);

        if (! $data) {
            return response()->json([
                'status' => 'error',
                'message' => 'Scan not found or expired',
            ], 404);
        }

        return response()->json([
            'status' => 'ok',
            'data' => $data,
        ]);
    }
}
