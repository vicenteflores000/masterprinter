<?php

use App\Http\Controllers\Api\PrinterController;
use App\Http\Controllers\Api\PrinterSnmpConfigController;
use App\Http\Controllers\Api\PrinterSnmpController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/printers', [PrinterController::class, 'index']);
    Route::get('/printers/{printer}', [PrinterController::class, 'show']);
    Route::post('/printers', [PrinterController::class, 'store']);
    Route::post('/printers/{printer}/snmp/discover', [PrinterController::class, 'discover']);
    Route::post('/printers/resolve-ip', [PrinterController::class, 'resolveIp']);
    Route::post('/printers/scan', [PrinterController::class, 'scanSubnet']);
    Route::get('/printers/scan/{scanId}', [PrinterController::class, 'scanStatus']);
    Route::post('/printers/{printer}/snmp-config', [PrinterSnmpConfigController::class, 'store']);
    Route::get(
        'printers/{printer}/snmp/consumables',
        [PrinterSnmpController::class, 'consumables']
    );
    Route::get('/snmp/reachable', [PrinterSnmpController::class, 'reachable']);
});
