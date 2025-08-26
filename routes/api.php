<?php

use App\Http\Controllers\Api\ApiCanvasController;
use App\Http\Controllers\Api\ApiLakeReaderController;
use App\Http\Controllers\Api\ApiELTController;
use App\Http\Controllers\Api\ApiGetDataController;
use App\Http\Controllers\Api\ApiVisualizationController;
use App\Http\Controllers\Api\ApiOtentikasiController;
use App\Http\Controllers\Api\ChatSessionController;
use App\Http\Controllers\Api\NL2SQLController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('otentikasi')->group(function () {
    Route::post('/register', [ApiOtentikasiController::class, 'registerUser']);
    Route::post('/login', [ApiOtentikasiController::class, 'loginUser']);
    Route::get('/logout', [ApiOtentikasiController::class, 'logoutUser'])->middleware('auth:sanctum');
    Route::get('/get-user', [ApiOtentikasiController::class, 'getUser'])->middleware('auth:sanctum');
    Route::post('/update-access', [ApiOtentikasiController::class, 'updateAccess'])->middleware('auth:sanctum');
});

Route::prefix('kelola-dashboard')->group(function () {
    // Route::get('/tables', [ApiGetDataController::class, 'getAllTables']);
    // Route::get('/columns/{table}', [ApiGetDataController::class, 'getTableColumns']);
    Route::post('/execute-query', [ApiGetDataController::class, 'executeQuery']);

    Route::post('/fetch-database', [ApiLakeReaderController::class, 'connectDatasource']);
    Route::get('/fetch-tables', [ApiLakeReaderController::class, 'fetchTables']);
    Route::get('/fetch-column/{table}', [ApiLakeReaderController::class, 'fetchTableColumns']);
    
    Route::post('/fetch-data', [ApiGetDataController::class, 'getTableDataByColumns']);
    Route::post('/check-date', [ApiGetDataController::class, 'checkDateColumn']);

    Route::post('/check-foreign-key', [ApiLakeReaderController::class, 'checkIfForeignKey']);
    Route::post('/visualisasi-data', [ApiGetDataController::class, 'getVisualisasiData']);
    Route::post('/convert-sql', [ApiVisualizationController::class, 'convertSql']);
    Route::post('/get-joinable-tables', [ApiGetDataController::class, 'getJoinableTables']);

    Route::get('/latest', [ApiCanvasController::class, 'getLatestVisualization']);

    Route::get('/visualizations/{id}', [ApiVisualizationController::class, 'getVisualizationById']);
    Route::post('/save-visualization', [ApiVisualizationController::class, 'saveVisualization']);
    Route::put('/visualizations/{id}', [ApiVisualizationController::class, 'updateVisualization']);
    Route::delete('/delete-visualization/{id}', [ApiVisualizationController::class, 'deleteVisualization']);

    Route::get('/canvas/{id_canvas}/visualizations', [ApiCanvasController::class, 'getAllVisualizations']);
    Route::post('/canvas', [ApiCanvasController::class, 'createCanvas']);
    Route::put('/canvas/update/{id_canvas}', [ApiCanvasController::class, 'updateCanvas']);
    Route::put('/canvas/delete/{id_canvas}', [ApiCanvasController::class, 'deleteCanvas']);
    Route::get('/canvas/{id_canvas}', [ApiCanvasController::class, 'getCanvas']);
    Route::get('/project/{id_project}/canvases', [ApiCanvasController::class, 'getCanvasByProject']);
    Route::get('/first-canvas', [ApiCanvasController::class, 'getFirstCanvas']);

    Route::post('/etl/run', [ApiELTController::class, 'connectDatasource']);
    Route::post('/etl/refresh', [ApiELTController::class, 'refresh']);
    Route::post('/etl/full-refresh', [ApiELTController::class, 'fullRefresh']);
    Route::post('/etl/delete', [ApiELTController::class, 'delete']);
    Route::get('/etl/stats', [ApiELTController::class, 'getLakeStats']);

    Route::post('/nl2sql/generate', [NL2SQLController::class, 'generate']);
    Route::get('/nl2sql/test-connection', [NL2SQLController::class, 'testConnection']);
});

// Chat Session Management Routes
Route::prefix('chat')->middleware('auth:sanctum')->group(function () {
    Route::post('/sessions', [ChatSessionController::class, 'createSession']);
    Route::get('/sessions', [ChatSessionController::class, 'listUserSessions']);
    Route::get('/sessions/{sessionId}', [ChatSessionController::class, 'getSessionHistory']);
    Route::put('/sessions/{sessionId}', [ChatSessionController::class, 'updateSession']);
    Route::delete('/sessions/{sessionId}', [ChatSessionController::class, 'deleteSession']);
    Route::delete('/sessions/{sessionId}/history', [ChatSessionController::class, 'clearSessionHistory']);
});