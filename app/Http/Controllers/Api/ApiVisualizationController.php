<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
}
