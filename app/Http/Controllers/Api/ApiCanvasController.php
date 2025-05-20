<?php

namespace App\Http\Controllers\Api;

use Exception;
use App\Models\Canvas;
use Illuminate\Http\Request;
use App\Models\Visualization;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

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
            throw new Exception("Datasource dengan ID {$idDatasource} tidak ditemukan.");
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

    public function createCanvas(Request $request)
    {
        try {
            // Validasi data request
            $validatedData = $request->validate([
                'id_project' => 'required|exists:public.projects,id_project',
                'name' => 'required|string|max:255',
                'created_by' => 'nullable|string|max:255',
                'created_time' => 'nullable|date',
                'modified_by' => 'nullable|string|max:255',
                'modified_time' => 'nullable|date',
                'is_deleted' => 'nullable|boolean',
            ]);

            // Membuat canvas baru
            $canvas = Canvas::create([
                'id_project' => $validatedData['id_project'],
                'name' => $validatedData['name'],
                'created_by' => $validatedData['created_by'] ?? null,
                'created_time' => $validatedData['created_time'] ?? now(),
                'modified_by' => $validatedData['modified_by'] ?? null,
                'modified_time' => $validatedData['modified_time'] ?? now(),
                'is_deleted' => $validatedData['is_deleted'] ?? false,
            ]);

            // Mengembalikan response sukses dengan data canvas
            return response()->json([
                'success' => true,
                'message' => 'Canvas berhasil ditambahkan.',
                'canvas' => $canvas
            ], 201);
        } catch (\Exception $e) {
            // Menangani error dan mengembalikan response gagal
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambah canvas.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateCanvas(Request $request, $id_canvas)
    {
        try {
            // Validasi input
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
            ]);

            // Cari canvas berdasarkan id_canvas
            $canvas = Canvas::find($id_canvas);

            if (!$canvas) {
                return response()->json([
                    'success' => false,
                    'message' => 'Canvas dengan ID tersebut tidak ditemukan.',
                ], 404);
            }

            // Update nama canvas
            $canvas->name = $validatedData['name'];
            $canvas->modified_by = $request->user() ? $request->user()->name : 'admin';  // Bisa diganti sesuai user yang mengubah
            $canvas->modified_time = now();  // Set waktu modifikasi

            // Simpan perubahan ke database
            $canvas->save();

            return response()->json([
                'success' => true,
                'message' => 'Nama canvas berhasil diperbarui.',
                'canvas' => $canvas,  // Kembalikan data canvas yang telah diperbarui
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui nama canvas.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteCanvas($id_canvas)
    {
        try {
            // Cari canvas berdasarkan id_canvas
            $canvas = Canvas::find($id_canvas);

            if (!$canvas) {
                return response()->json([
                    'success' => false,
                    'message' => 'Canvas dengan ID tersebut tidak ditemukan.',
                ], 404);
            }

            // Hapus canvas
            $canvas->delete();

            return response()->json([
                'success' => true,
                'message' => 'Canvas berhasil dihapus.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus canvas.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
