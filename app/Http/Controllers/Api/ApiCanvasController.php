<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visualization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApiCanvasController extends Controller
{
    public function getLatestVisualization()
    {
        try {
            // Ambil ID Datasource dari visualization yang akan digunakan
            $visualization = Visualization::where('is_deleted', 0)
                ->whereNotNull('created_time')
                ->orderByDesc('created_time')
                ->first();

            if (!$visualization) {
                return response()->json([
                    'success' => false,
                    'message' => 'Visualisasi terbaru tidak ditemukan.',
                    'visualisazation' => null,
                    'data' => []
                ], 404);
            }

            // Ambil connection details berdasarkan idDatasource (gunakan ID yang sesuai)
            $connectionDetails = $this->getConnectionDetails($visualization->id_datasource);

            // Ubah koneksi dinamis
            config([
                'database.connections.dynamic' => $connectionDetails,
            ]);

            // Gunakan koneksi dynamic untuk menjalankan query
            $data = [];

            if ($visualization->visualization_type === 'table' && !empty($visualization->query)) {
                // Jalankan query dinamis menggunakan koneksi 'dynamic'
                $results = DB::connection('dynamic')->select($visualization->query);

                // Ubah jadi array associative
                $data = json_decode(json_encode($results), true);
            }

            return response()->json([
                'success' => true,
                'message' => 'Visualisasi terbaru berhasil diambil.',
                'visualization' => [
                    'id_visualization' => $visualization->id_visualization,
                    'query' => $visualization->query,
                    'visualization_type' => $visualization->visualization_type,
                    'config' => $visualization->config ?? [],
                ],
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil visualisasi.',
                'error' => $e->getMessage()
            ], 500);
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
}
