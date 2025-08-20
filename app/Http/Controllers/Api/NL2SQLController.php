<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Services\NL2SQLService;
use App\Models\Datasource;
use App\Models\Visualization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NL2SQLController extends Controller
{
    protected $nl2sqlService;

    public function __construct(NL2SQLService $nl2sqlService)
    {
        $this->nl2sqlService = $nl2sqlService;
    }

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'prompt' => 'required|string|min:3',
            'id_datasource' => 'required|integer|exists:datasources,id_datasource',
            'table_names' => 'nullable|array',
            'table_names.*' => 'string',
            'execute' => 'boolean',
            'save_visualization' => 'boolean',
            'id_canvas' => 'required_if:save_visualization,true|integer|exists:public.canvas,id_canvas',
        ]);

        try {
            $datasource = Datasource::where('id_datasource', $validated['id_datasource'])
                ->where('is_deleted', false)
                ->firstOrFail();

            $requestData = [
                'prompt' => $validated['prompt'],
                'id_datasource' => $validated['id_datasource'],
                'table_names' => $validated['table_names'] ?? null,
            ];

            Log::info('NL2SQL Request', [
                'request_data' => $requestData,
                'id_datasource' => $validated['id_datasource'],
            ]);

            $fastApiResponse = $this->nl2sqlService->generateSql($requestData, $validated['id_datasource']);

            if (isset($fastApiResponse['error']) && $fastApiResponse['error']) {
                return response()->json([
                    'success' => false,
                    'message' => $fastApiResponse['message'] ?? 'Error from FastAPI',
                    'error_details' => $fastApiResponse
                ], 500);
            }

            if (!isset($fastApiResponse['sql_query'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid response from FastAPI - missing sql_query',
                    'fastapi_response' => $fastApiResponse
                ], 500);
            }

            $result = [
                'success' => true,
                'data' => [
                    'sql_query' => $fastApiResponse['sql_query'],
                    'confidence_score' => $fastApiResponse['confidence_score'] ?? 0.0,
                    'explanation' => $fastApiResponse['explanation'] ?? '',
                    'analysis' => $fastApiResponse['analysis'] ?? ''
                ],
                'executed_data' => null,
                'visualization_id' => null,
            ];

            if ($request->boolean('execute')) {
                try {
                    $executedData = $this->executeQuery($fastApiResponse['sql_query'], $validated['id_datasource']);
                    $result['executed_data'] = $executedData;

                    Log::info('SQL Executed successfully', [
                        'sql' => $fastApiResponse['sql_query'],
                        'rows_returned' => count($executedData),
                    ]);
                } catch (\Exception $e) {
                    Log::error('SQL Execution failed', [
                        'sql' => $fastApiResponse['sql_query'],
                        'error' => $e->getMessage(),
                    ]);

                    $result['execution_error'] = 'Failed to execute SQL: ' . $e->getMessage();
                }
            }

            if ($request->boolean('save_visualization')) {
                try {
                    $visualization = $this->saveVisualization($validated, $fastApiResponse);
                    $result['visualization_id'] = $visualization->id_visualization;

                    Log::info('Visualization saved', [
                        'visualization_id' => $visualization->id_visualization,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to save visualization', [
                        'error' => $e->getMessage(),
                    ]);

                    $result['save_error'] = 'Failed to save visualization: ' . $e->getMessage();
                }
            }

            return response()->json($result);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datasource not found'
            ], 404);

        } catch (\Exception $e) {
            Log::error('NL2SQL Controller Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error: ' . $e->getMessage()
            ], 500);
        }
    }

    private function executeQuery(string $sqlQuery, int $idDatasource): array
    {
        $dbConfig = $this->getConnectionDetails($idDatasource);
        $connectionName = "dynamic_nl2sql_{$idDatasource}";

        config(["database.connections.{$connectionName}" => $dbConfig]);

        $connection = DB::connection($connectionName);

        $results = $connection->select($sqlQuery);

        return array_map(function($row) {
            return (array) $row;
        }, $results);
    }

    private function saveVisualization(array $validated, array $fastApiResponse): Visualization
    {
        return Visualization::create([
            'id_canvas' => $validated['id_canvas'],
            'id_datasource' => $validated['id_datasource'],
            'name' => 'NL2SQL_' . time(),
            'visualization_type' => 'table',
            'query' => $fastApiResponse['sql_query'],
            'config' => [
                'prompt' => $validated['prompt'],
                'confidence_score' => $fastApiResponse['confidence_score'] ?? 0.0,
                'explanation' => $fastApiResponse['explanation'] ?? '',
                'analysis' => $fastApiResponse['analysis'] ?? '',
                'generated_at' => now()->format('Y-m-d H:i:s'),
                'colors' => ['#4CAF50', '#FF9800', '#2196F3'],
                'backgroundColor' => '#ffffff',
                'title' => 'NL2SQL_' . time(),
                'fontSize' => 14,
                'fontFamily' => 'Arial',
                'fontColor' => '#333',
            ],
            'width' => 800,
            'height' => 350,
            'position_x' => 0,
            'position_y' => 0,
            'created_by' => 'system',
            'created_time' => now(),
            'modified_by' => 'system',
            'modified_time' => now(),
        ]);
    }

    private function getConnectionDetails(int $idDatasource): array
    {
        $datasource = Datasource::findOrFail($idDatasource);

        return [
            'driver'    => $datasource->type,
            'host'      => $datasource->host,
            'port'      => $datasource->port,
            'database'  => $datasource->database_name,
            'username'  => $datasource->username,
            'password'  => $datasource->password,
            'charset'   => 'utf8',
            'prefix'    => '',
            'schema'    => 'public',
            'options'   => [
                \PDO::ATTR_TIMEOUT => 30,
            ]
        ];
    }

    public function testConnection()
    {
        try {
            $result = $this->nl2sqlService->testConnection();

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'FastAPI connection successful',
                    'details' => $result
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'FastAPI connection failed',
                    'details' => $result
                ], 503);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error testing FastAPI connection: ' . $e->getMessage()
            ], 500);
        }
    }
}