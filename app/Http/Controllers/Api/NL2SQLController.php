<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Services\NL2SQLService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Visualization;
use App\Models\Datasource;

class NL2SQLController extends Controller
{
    protected $nl2sqlService;

    public function __construct(NL2SQLService $nl2sqlService)
    {
        $this->nl2sqlService = $nl2sqlService;
    }

    /**
     * Generate SQL from natural language and optionally execute it or save to Visualization.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generate(Request $request)
    {
        // Validasi request
        $validated = $request->validate([
            'prompt' => 'required|string|min:3',
            'id_datasource' => 'required|integer|exists:datasources,id_datasource',
            'database_name' => 'nullable|string',
            'table_names' => 'nullable|array',
            'table_names.*' => 'string',
            'execute' => 'boolean',
            'save_visualization' => 'boolean',
        ]);

        try {
            // Siapkan data untuk FastAPI
            $requestData = [
                'prompt' => $validated['prompt'],
                'database_name' => $validated['database_name'] ?? null,
                'table_names' => $validated['table_names'] ?? null,
            ];

            // Log request untuk debugging
            Log::info('NL2SQL Request', [
                'request_data' => $requestData,
                'id_datasource' => $validated['id_datasource']
            ]);

            // Panggil FastAPI melalui service
            $fastApiResponse = $this->nl2sqlService->generateSql($requestData, $validated['id_datasource']);

            // Cek jika ada error dari FastAPI
            if (isset($fastApiResponse['error']) && $fastApiResponse['error']) {
                return response()->json([
                    'success' => false, 
                    'message' => $fastApiResponse['message'] ?? 'Error from FastAPI',
                    'error_details' => $fastApiResponse
                ], 500);
            }

            // Pastikan response memiliki struktur yang benar
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

            // Execute SQL jika diminta
            if ($request->boolean('execute')) {
                try {
                    $executedData = $this->executeQuery($fastApiResponse['sql_query'], $validated['id_datasource']);
                    $result['executed_data'] = $executedData;
                    
                    Log::info('SQL Executed successfully', [
                        'sql' => $fastApiResponse['sql_query'],
                        'rows_returned' => count($executedData)
                    ]);
                } catch (\Exception $e) {
                    Log::error('SQL Execution failed', [
                        'sql' => $fastApiResponse['sql_query'],
                        'error' => $e->getMessage()
                    ]);
                    
                    $result['execution_error'] = 'Failed to execute SQL: ' . $e->getMessage();
                }
            }

            // Simpan ke Visualization jika diminta
            if ($request->boolean('save_visualization')) {
                try {
                    $visualization = $this->saveVisualization($validated, $fastApiResponse);
                    $result['visualization_id'] = $visualization->id_visualization;
                    
                    Log::info('Visualization saved', [
                        'visualization_id' => $visualization->id_visualization
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to save visualization', [
                        'error' => $e->getMessage()
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
            
        } catch (\Exception $e) {
            Log::error('NL2SQL Controller Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false, 
                'message' => 'Internal server error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Execute SQL query on the specified datasource.
     *
     * @param string $sqlQuery
     * @param int $idDatasource
     * @return array
     */
    private function executeQuery(string $sqlQuery, int $idDatasource): array
    {
        $dbConfig = $this->getConnectionDetails($idDatasource);
        $connectionName = "dynamic_nl2sql_{$idDatasource}";
        
        // Set konfigurasi database dinamis
        config(["database.connections.{$connectionName}" => $dbConfig]);
        
        // Gunakan koneksi untuk menjalankan query
        $connection = DB::connection($connectionName);
        
        // Eksekusi query dengan timeout
        $results = $connection->select($sqlQuery);
        
        // Convert ke array biasa untuk konsistensi response
        return array_map(function($row) {
            return (array) $row;
        }, $results);
    }

    /**
     * Save visualization based on NL2SQL result.
     *
     * @param array $validated
     * @param array $fastApiResponse
     * @return Visualization
     */
    private function saveVisualization(array $validated, array $fastApiResponse): Visualization
    {
        return Visualization::create([
            'id_datasource' => $validated['id_datasource'],
            'visualization_name' => 'NL2SQL_' . time(),
            'visualization_type' => 'table', // Default type
            'sql' => $fastApiResponse['sql_query'],
            'config' => json_encode([
                'prompt' => $validated['prompt'],
                'confidence_score' => $fastApiResponse['confidence_score'] ?? 0.0,
                'explanation' => $fastApiResponse['explanation'] ?? '',
                'analysis' => $fastApiResponse['analysis'] ?? '',
                'generated_at' => now(),
            ]),
            'created_by' => 1, // TODO: Gunakan ID user yang sebenarnya dari auth
            'created_at' => now(),
        ]);
    }

    /**
     * Get dynamic DB connection details from Datasource.
     *
     * @param int $idDatasource
     * @return array
     */
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
                \PDO::ATTR_TIMEOUT => 30, // 30 second timeout
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