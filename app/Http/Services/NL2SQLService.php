<?php

namespace App\Http\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Log;
use App\Models\Datasource;

class NL2SQLService
{
    protected $client;
    protected $baseUrl;
    protected $timeout;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 120, // 2 minutes untuk LLM processing
            'connect_timeout' => 10,
            'verify' => false, // Disable SSL verification jika ada masalah
        ]);
        
        $this->baseUrl = env('FASTAPI_URL', 'http://localhost:8000');
        $this->timeout = env('FASTAPI_TIMEOUT', 120);
    }

    /**
     * Generate SQL from natural language prompt via FastAPI.
     *
     * @param array $data Data to send: ['prompt' => string, 'database_name' => string|null, 'table_names' => array|null]
     * @param int $id_datasource ID datasource untuk validasi
     * @return array Response from FastAPI or error
     * @throws \Exception Jika datasource invalid
     */
    public function generateSql(array $data, int $id_datasource): array
    {
        // Validasi datasource
        $datasource = Datasource::find($id_datasource);
        if (!$datasource) {
            throw new \Exception("Datasource dengan ID $id_datasource tidak ditemukan.");
        }

        // Set database_name dari datasource jika tidak ada
        if (empty($data['database_name'])) {
            $data['database_name'] = $datasource->database_name;
        }

        // Pastikan prompt tidak kosong
        if (empty($data['prompt'])) {
            return [
                'error' => true,
                'message' => 'Prompt tidak boleh kosong',
            ];
        }

        // Endpoint sesuai dengan FastAPI yang Anda buat
        $endpoint = '/api/v1/nl2sql/convert';
        $url = $this->baseUrl . $endpoint;

        Log::info('Calling FastAPI NL2SQL', [
            'url' => $url,
            'data' => $data,
            'datasource_id' => $id_datasource,
        ]);

        try {
            $response = $this->client->post($url, [
                'json' => $data,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'timeout' => $this->timeout,
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            Log::info('FastAPI Response received', [
                'status_code' => $statusCode,
                'response_size' => strlen($body),
                'datasource_id' => $id_datasource,
            ]);

            if ($statusCode !== 200) {
                Log::warning('FastAPI returned non-200 status', [
                    'status_code' => $statusCode,
                    'response' => $body,
                ]);
                
                return [
                    'error' => true,
                    'message' => "FastAPI returned status {$statusCode}: {$body}",
                ];
            }

            $decodedResponse = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Failed to decode FastAPI response', [
                    'json_error' => json_last_error_msg(),
                    'response' => $body,
                ]);
                
                return [
                    'error' => true,
                    'message' => 'Invalid JSON response from FastAPI: ' . json_last_error_msg(),
                ];
            }

            // Validasi struktur response
            $requiredFields = ['sql_query'];
            foreach ($requiredFields as $field) {
                if (!isset($decodedResponse[$field])) {
                    Log::error('Missing required field in FastAPI response', [
                        'missing_field' => $field,
                        'response' => $decodedResponse,
                    ]);
                    
                    return [
                        'error' => true,
                        'message' => "Missing required field '{$field}' in FastAPI response",
                    ];
                }
            }

            Log::info('NL2SQL API success', [
                'sql_generated' => !empty($decodedResponse['sql_query']),
                'confidence_score' => $decodedResponse['confidence_score'] ?? 'not_provided',
                'datasource_id' => $id_datasource,
                'prompt' => substr($data['prompt'], 0, 100) . (strlen($data['prompt']) > 100 ? '...' : ''),
            ]);

            return $decodedResponse;

        } catch (ConnectException $e) {
            $errorMessage = 'Cannot connect to FastAPI server: ' . $e->getMessage();
            Log::error('FastAPI Connection Error', [
                'error' => $errorMessage,
                'url' => $url,
                'datasource_id' => $id_datasource,
            ]);

            return [
                'error' => true,
                'message' => $errorMessage,
                'error_type' => 'connection_error',
            ];

        } catch (RequestException $e) {
            $errorMessage = 'FastAPI Request Error';
            $errorDetails = $e->getMessage();
            
            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                $responseBody = (string) $e->getResponse()->getBody();
                $errorMessage .= " (HTTP {$statusCode})";
                $errorDetails = $responseBody ?: $errorDetails;
            }

            Log::error('FastAPI Request Error', [
                'error' => $errorDetails,
                'data' => $data,
                'datasource_id' => $id_datasource,
                'url' => $url,
            ]);

            return [
                'error' => true,
                'message' => $errorMessage . ': ' . $errorDetails,
                'error_type' => 'request_error',
            ];

        } catch (\Exception $e) {
            Log::error('Unexpected error in NL2SQLService', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
                'datasource_id' => $id_datasource,
            ]);

            return [
                'error' => true,
                'message' => 'Unexpected error: ' . $e->getMessage(),
                'error_type' => 'unexpected_error',
            ];
        }
    }

    /**
     * Test connection to FastAPI server.
     *
     * @return array
     */
    public function testConnection(): array
    {
        try {
            $response = $this->client->get($this->baseUrl . '/', [
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => true,
                'status_code' => $statusCode,
                'message' => 'FastAPI server is reachable',
                'server_info' => $body,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Cannot reach FastAPI server: ' . $e->getMessage(),
                'url' => $this->baseUrl,
            ];
        }
    }
}