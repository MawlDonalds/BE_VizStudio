<?php

use App\Http\Controllers\Api\ApiCanvasController;
use App\Http\Controllers\Api\ApiConnectDatabaseController;
use App\Http\Controllers\Api\ApiGetDataController;
use App\Http\Controllers\Api\ApiVisualizationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('kelola-dashboard')->group(function () {
    // Route::post('/convert-sql', [ApiKelolaDashboardController::class, 'convertSql']);
    Route::get('/tables', [ApiGetDataController::class, 'getAllTables']);
    // Route::post('/tables', [ApiGetDataController::class, 'getAllTables']);
    Route::get('/columns/{table}', [ApiGetDataController::class, 'getTableColumns']);
    Route::post('/execute-query', [ApiGetDataController::class, 'executeQuery']);

    Route::post('/fetch-database', [ApiConnectDatabaseController::class, 'connectDB']);
    Route::get('/fetch-table/{id}', [ApiConnectDatabaseController::class, 'fetchTables']);
    Route::get('/fetch-column/{table}', [ApiConnectDatabaseController::class, 'getTableColumns']);
    // Route::post('/fetch-data/{table}', [ApiConnectDatabaseController::class, 'getTableDataByColumns']);

    Route::post('/fetch-data', [ApiGetDataController::class, 'getTableDataByColumns']);

    Route::post('/check-foreign-key', [ApiConnectDatabaseController::class, 'checkIfForeignKey']);
    // Route::post('/table-data', [ApiGetDataController::class, 'getTableDataByColumns']);
    Route::post('/visualisasi-data', [ApiGetDataController::class, 'getVisualisasiData']);
    Route::post('/convert-sql', [ApiVisualizationController::class, 'convertSql']);

    // Route::post('/save-chart', [ApiGetDataController::class, 'saveChart']);
    Route::get('/latest', [ApiCanvasController::class, 'getLatestVisualization']);

    // Route::post('/visualisasi-data', [VisualisasiController::class, 'getData']);

    // New chart routes
    Route::get('/visualizations', [ApiVisualizationController::class, 'getAllVisualizations']);
    Route::get('/visualizations/{id}', [ApiVisualizationController::class, 'getVisualizationById']);
    Route::post('/save-visualization', [ApiVisualizationController::class, 'saveVisualization']);
    Route::put('/visualizations/{id}', [ApiVisualizationController::class, 'updateVisualization']);
    Route::delete('/delete-visualization/{id}', [ApiVisualizationController::class, 'deleteVisualization']);

    // Get Visualization
    Route::get('/get-visualizations', [ApiCanvasController::class, 'getAllVisualizations']);
    Route::post('/canvas', [ApiCanvasController::class, 'createCanvas']);
    Route::put('/canvas/{id_canvas}', [ApiCanvasController::class, 'updateCanvasName']);
    Route::delete('/canvas/{id_canvas}', [ApiCanvasController::class, 'deleteCanvas']);
});
