<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visualization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ApiVisualizationController extends Controller
{
    public function convertSql(Request $request)
{
    $sql = $request->input('sql');

    try {
        // Ambil koneksi database dari datasources
        $idDatasource = 1; // Ganti dengan input dari user jika mau dinamis
        $dbConfig = $this->getConnectionDetails($idDatasource);

        // Buat koneksi on-the-fly
        config(["database.connections.dynamic" => $dbConfig]);

        // Gunakan koneksi dynamic
        $connection = DB::connection('dynamic');

        // Jalankan query dari input user
        $data = $connection->select($sql);

        if (empty($data)) {
            return response()->json(['error' => 'Tidak ada data ditemukan.'], 400);
        }

        // Konversi data ke array grafik (kategori dan data)
        $categories = [];
        $seriesData = [];

        foreach ($data as $row) {
            $row = (array) $row;
            $keys = array_keys($row);

            // Kolom pertama sebagai kategori
            $category = $row[$keys[0]] ?? 'Tanpa Keterangan';
            if (is_null($category) || $category === '') {
                $category = 'Tanpa Keterangan';
            }
            if (!in_array($category, $categories)) {
                $categories[] = $category;
            }

            // Kolom kedua sebagai nilai
            $value = $row[$keys[1]] ?? 0;
            if (is_null($value) || $value === '') {
                $value = 0;
            }

            $seriesData[] = $value;
        }

        return response()->json([
            'categories' => $categories,
            'series' => [[
                'name' => 'Total',
                'data' => $seriesData
            ]]
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Query tidak valid: ' . $e->getMessage()], 400);
    }
}

private function getConnectionDetails($idDatasource)
    {
        $datasource = DB::table('datasources')->where('id_datasource', $idDatasource)->first();

        if (!$datasource) {
            throw new \Exception("Datasource dengan ID {$idDatasource} tidak ditemukan.");
        }

        return [
            'driver'    => $datasource->type,
            'host'      => $datasource->host,
            'port'      => $datasource->port,
            'database'  => $datasource->database_name,
            'username'  => $datasource->username,
            'password'  => $datasource->password,
            'charset'   => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix'    => '',
            'schema'    => 'public',
        ];
    }

    public function getAllVisualizations(Request $request)
    {
        try {
            // You may want to filter by canvas ID or user
            $visualizations = Visualization::where('is_deleted', 0)
                      ->orderBy('created_time', 'desc')
                      ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Visualizations retrieved successfully',
                'data' => $visualizations
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve visualizations: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save a new visualization
     */
    public function saveVisualization(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'id_canvas' => 'required|integer',
                'id_datasource' => 'required|integer',
                'name' => 'required|string|max:255',
                'visualization_type' => 'required|string|max:50',
                'query' => 'required|string',
                'config' => 'required|array',
                'width' => 'nullable|numeric',
                'height' => 'nullable|numeric',
                'position_x' => 'nullable|numeric',
                'position_y' => 'nullable|numeric',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $userId = 1; // Default to 1 if not authenticated
            $now = now();

            $visualization = Visualization::create([
                'id_canvas' => $request->id_canvas,
                'id_datasource' => $request->id_datasource,
                'name' => $request->name,
                'visualization_type' => $request->visualization_type,
                'query' => $request->query,
                'config' => $request->config,
                'width' => $request->width ?? 800,
                'height' => $request->height ?? 400,
                'position_x' => $request->position_x ?? 20,
                'position_y' => $request->position_y ?? 20,
                'created_by' => $userId,
                'created_time' => $now,
                'modified_by' => $userId,
                'modified_time' => $now,
                'is_deleted' => 0
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Visualization saved successfully',
                'data' => $visualization
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to save visualization: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an existing visualization
     */
    public function updateVisualization(Request $request, $id)
    {
        try {
            $visualization = visualization::findOrFail($id);
            
            // Validate request data
            $validator = Validator::make($request->all(), [
                'name' => 'string|max:255',
                'visualization_type' => 'string|max:50',
                'query' => 'string',
                'config' => 'array',
                'width' => 'numeric',
                'height' => 'numeric',
                'position_x' => 'numeric',
                'position_y' => 'numeric',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $userId = 1; // Default to 1 if not authenticated
            
            // Update only fields that are present in the request
            $updateData = array_filter($request->all(), function ($value) {
                return $value !== null;
            });
            
            $updateData['modified_by'] = $userId;
            $updateData['modified_time'] = now();
            
            $visualization->update($updateData);

            return response()->json([
                'status' => 'success',
                'message' => 'visualization updated successfully',
                'data' => $visualization
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update visualization: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a visualization (soft delete)
     */
    public function deleteVisualization($id)
    {
        try {
            $visualization = Visualization::findOrFail($id);
            
            $userId = 1; // Default to 1 if not authenticated
            
            // Soft delete
            $visualization->update([
                'is_deleted' => 1,
                'modified_by' => $userId,
                'modified_time' => now()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'visualization deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete visualization: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific visualization by ID
     */
    public function getVisualizationById($id)
    {
        try {
            $visualization = Visualization::findOrFail($id);
            
            if ($visualization->is_deleted) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'visualization not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'visualization retrieved successfully',
                'data' => $visualization
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve visualization: ' . $e->getMessage()
            ], 500);
        }
    }
}
