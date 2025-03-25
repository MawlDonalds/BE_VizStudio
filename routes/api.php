<?php

use App\Http\Controllers\Api\ApiGetDataController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('kelola-dashboard')->group(function () {
    // Route::post('/convert-sql', [ApiKelolaDashboardController::class, 'convertSql']);
    Route::get('/tables', [ApiGetDataController::class, 'getAllTables']);
    Route::get('/columns/{table}', [ApiGetDataController::class, 'getTableColumns']);
    Route::post('/table-data/{table}', [ApiGetDataController::class, 'getTableDataByColumns']);
    Route::post('/execute-query', [ApiGetDataController::class, 'executeQuery']);
});
