<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApiGetDataController extends Controller
{
    public function getAllTables()
    {
        try {
            // Query ke information_schema.tables untuk PostgreSQL
            $tables = DB::select("
                SELECT table_name
                FROM information_schema.tables
                WHERE table_schema = 'public'
            ");

            // Daftar tabel yang akan diabaikan
            $excludedTables = ['migrations', 'personal_access_tokens'];

            // Filter hasil query untuk mengabaikan tabel tertentu
            $tableNames = array_filter(
                array_map(fn($table) => $table->table_name, $tables),
                fn($tableName) => !in_array($tableName, $excludedTables)
            );

            return response()->json([
                'success' => true,
                'message' => 'Daftar tabel berhasil diambil.',
                'data' => array_values($tableNames), // Mengatur ulang indeks array
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil daftar tabel.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getTableColumns($table)
    {
        try {
            // Periksa apakah tabel ada di database
            $tableExists = DB::select("
                SELECT table_name
                FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = ?
            ", [$table]);

            if (empty($tableExists)) {
                return response()->json([
                    'success' => false,
                    'message' => "Tabel '{$table}' tidak ditemukan di database.",
                ], 404);
            }

            // Query untuk mendapatkan semua kolom dari tabel tertentu
            $columns = DB::select("
                SELECT column_name, data_type, is_nullable, ordinal_position
                FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = ?
            ", [$table]);

            // Format hasil untuk mempermudah pembacaan
            $formattedColumns = array_map(function ($column) {
                return [
                    'id' => $column->ordinal_position,
                    'name' => $column->column_name,
                    'type' => $column->data_type,
                    'nullable' => $column->is_nullable === 'YES',
                ];
            }, $columns);

            return response()->json([
                'success' => true,
                'message' => "Daftar kolom berhasil diambil dari tabel '{$table}'.",
                'data' => $formattedColumns,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil daftar kolom.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getTableDataByColumns(Request $request, $table)
    {
        try {
            // Terima input array 'dimensi' dan (opsional) string 'metriks'
            $dimensi = $request->input('dimensi', []);   // array
            $metriks = $request->input('metriks', null); // string atau null

            // Validasi dasar
            if (!is_array($dimensi) || count($dimensi) === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dimensi harus dikirim sebagai array dan minimal 1.',
                ], 400);
            }

            // Pastikan tabel ada di DB
            $tableExists = DB::select("
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = 'public' AND table_name = ?
        ", [$table]);

            if (empty($tableExists)) {
                return response()->json([
                    'success' => false,
                    'message' => "Tabel '{$table}' tidak ditemukan di database.",
                ], 404);
            }

            // Menghindari trailing comma dengan memeriksa dimensi
            $selectColumns = implode(', ', array_map(fn($column) => "\"{$column}\"", $dimensi));

            // Jika ada metriks, tambahkan ke select
            if ($metriks) {
                $selectColumns .= ", COUNT(DISTINCT {$metriks}) AS total_{$metriks}";
            }

            $query = DB::table('pelatihan')
                ->join('agenda_pelatihan', 'pelatihan.id_pelatihan', '=', 'agenda_pelatihan.id_pelatihan')
                ->join('pendaftaran_event', 'agenda_pelatihan.id_agenda', '=', 'pendaftaran_event.id_agenda')
                ->join('pendaftar', 'pendaftaran_event.id_peserta', '=', 'pendaftar.id_pendaftar')
                ->select(DB::raw($selectColumns));

            // Group by dimensi
            $query->groupBy($dimensi);

            // Order by dimensi atau metriks
            if ($metriks) {
                $query->orderBy(DB::raw("COUNT(DISTINCT {$metriks})"), 'desc');
            } else {
                $query->orderBy($dimensi[0], 'asc');
            }

            // Untuk debugging, bangun string query manual
            $sqlForDebug = sprintf(
                "SELECT %s FROM pelatihan
            JOIN agenda_pelatihan ON pelatihan.id_pelatihan = agenda_pelatihan.id_pelatihan
            JOIN pendaftaran_event ON agenda_pelatihan.id_agenda = pendaftaran_event.id_agenda
            JOIN pendaftar ON pendaftaran_event.id_peserta = pendaftar.id_pendaftar
            GROUP BY %s ORDER BY %s DESC",
                $selectColumns,
                implode(', ', $dimensi),
                $metriks ? "COUNT(DISTINCT {$metriks})" : implode(', ', $dimensi)
            );

            // Eksekusi
            $data = $query->get();

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil dihitung berdasarkan dimensi dan metriks.',
                'data' => $data,
                'query' => $sqlForDebug,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    public function executeQuery(Request $request)
    {
        try {
            // Ambil query dari input JSON
            $query = $request->input('query');

            // Validasi query untuk memastikan tidak kosong
            if (empty($query)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Query SQL tidak boleh kosong.',
                ], 400);
            }

            // Menjalankan query SQL yang diberikan
            $result = DB::select($query);

            // Mengembalikan hasil query
            return response()->json([
                'success' => true,
                'message' => 'Query berhasil dijalankan.',
                'data' => $result,
            ], 200);
        } catch (\Exception $e) {
            // Menangani error jika ada kesalahan saat menjalankan query
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menjalankan query.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }





    // public function getTableDataByColumns(Request $request, $table)
    // {
    //     try {
    //         // Terima input array 'dimensi' dan (opsional) string 'metriks'
    //         $dimensi = $request->input('dimensi', []);   // array
    //         $metriks = $request->input('metriks', null);  // string atau null
    //         $tables = $request->input('tables', []);      // array untuk tabel yang di-join

    //         // Validasi dasar
    //         if (!is_array($dimensi) || count($dimensi) === 0) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Dimensi harus dikirim sebagai array dan minimal 1.',
    //             ], 400);
    //         }

    //         // Pastikan tabel utama ada di DB
    //         $tableExists = DB::select("
    //             SELECT table_name
    //             FROM information_schema.tables
    //             WHERE table_schema = 'public' AND table_name = ?
    //         ", [$table]);

    //         if (empty($tableExists)) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => "Tabel '{$table}' tidak ditemukan di database.",
    //             ], 404);
    //         }

    //         // Memulai query dengan tabel utama
    //         $query = DB::table($table);

    //         // Jika ada tabel yang perlu di-join, lakukan join dinamis
    //         foreach ($tables as $joinTable) {
    //             if (isset($joinTable['table']) && isset($joinTable['on'])) {
    //                 $query->join($joinTable['table'], $joinTable['on'][0], '=', $joinTable['on'][1]);
    //             }
    //         }

    //         // Menambahkan kolom dimensi
    //         $query->select($dimensi);

    //         // Jika metriks diisi (tidak null/empty), lakukan COUNT DISTINCT
    //         if ($metriks) {
    //             $query->addSelect(DB::raw("COUNT(DISTINCT {$metriks}) AS total_{$metriks}"));
    //         }

    //         // Lakukan groupBy pada seluruh kolom dimensi
    //         $query->groupBy($dimensi);

    //         // Pengurutan
    //         if ($metriks) {
    //             // Urut desc berdasarkan COUNT DISTINCT metriks
    //             $query->orderBy(DB::raw("COUNT(DISTINCT {$metriks})"), 'desc');
    //         } else {
    //             // Jika tidak ada metriks, urutkan berdasarkan dimensi pertama
    //             $query->orderBy($dimensi[0], 'asc');
    //         }

    //         // Untuk debugging, bangun string query manual
    //         $joinClauses = [];
    //         foreach ($tables as $joinTable) {
    //             $joinClauses[] = "JOIN {$joinTable['table']} ON {$joinTable['on'][0]} = {$joinTable['on'][1]}";
    //         }

    //         $sqlForDebug = sprintf(
    //             "SELECT %s, %s FROM %s %s GROUP BY %s ORDER BY %s DESC",
    //             implode(', ', $dimensi),
    //             $metriks ? "COUNT(DISTINCT {$metriks}) as total_{$metriks}" : "",
    //             $table,
    //             implode(' ', $joinClauses),
    //             implode(', ', $dimensi),
    //             $metriks ? "COUNT(DISTINCT {$metriks})" : implode(', ', $dimensi)
    //         );

    //         // Eksekusi
    //         $data = $query->get();

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Data berhasil dihitung berdasarkan dimensi dan metriks.',
    //             'data' => $data,
    //             'query' => $sqlForDebug,
    //         ], 200);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Terjadi kesalahan saat mengambil data.',
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    // public function getTableDataByColumns(Request $request, $table)
    // {
    //     try {
    //         // Terima input array 'dimensi' dan (opsional) string 'metriks'
    //         $dimensi = $request->input('dimensi', []);   // array
    //         $metriks = $request->input('metriks', null);  // string atau null
    //         $tables = $request->input('tables', []);      // array untuk tabel yang di-join

    //         // Validasi dasar
    //         if (!is_array($dimensi) || count($dimensi) === 0) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Dimensi harus dikirim sebagai array dan minimal 1.',
    //             ], 400);
    //         }

    //         $tableExists = DB::select("
    //         SELECT table_name
    //         FROM information_schema.tables
    //         WHERE table_schema = 'public' AND table_name = ?
    //     ", [$table]);

    //         if (empty($tableExists)) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => "Tabel '{$table}' tidak ditemukan di database.",
    //             ], 404);
    //         }

    //         // Memulai query dengan tabel utama
    //         $query = DB::table($table);

    //         // Jika ada tabel yang perlu di-join, lakukan join dinamis
    //         foreach ($tables as $joinTable) {
    //             if (isset($joinTable['table']) && isset($joinTable['on'])) {
    //                 $query->join($joinTable['table'], $joinTable['on'][0], '=', $joinTable['on'][1]);
    //             }
    //         }

    // Menambahkan kolom dimensi
    // $query->select($dimensi);

    //         // Jika metriks diisi (tidak kosong), lakukan COUNT DISTINCT untuk setiap metriks
    //         foreach ($metriks as $metriksColumn) {
    //             $query->addSelect(DB::raw("COUNT(DISTINCT {$metriksColumn}) AS total_{$metriksColumn}"));
    //         }

    //         // Lakukan groupBy pada seluruh kolom dimensi
    //         $query->groupBy($dimensi);

    //         // Pengurutan
    //         if (count($metriks) > 0) {
    //             // Urut desc berdasarkan COUNT DISTINCT dari metriks pertama
    //             $query->orderBy(DB::raw("COUNT(DISTINCT {$metriks[0]})"), 'desc');
    //         } else {
    //             // Jika tidak ada metriks, urutkan berdasarkan dimensi pertama
    //             $query->orderBy($dimensi[0], 'asc');
    //         }

    // Untuk debugging, bangun string query manual
    // $joinClauses = [];
    // foreach ($tables as $joinTable) {
    //     $joinClauses[] = "JOIN {$joinTable['table']} ON {$joinTable['on'][0]} = {$joinTable['on'][1]}";
    // }

    // $sqlForDebug = sprintf(
    //     "SELECT %s, %s FROM %s %s GROUP BY %s ORDER BY %s DESC",
    //     implode(', ', $dimensi),
    //     $metriks ? "COUNT(DISTINCT {$metriks}) as total_{$metriks}" : "",
    //     $table,
    //     implode(' ', $joinClauses),
    //     implode(', ', $dimensi),
    //     $metriks ? "COUNT(DISTINCT {$metriks})" : implode(', ', $dimensi)
    // );

    //         // Eksekusi
    //         $data = $query->get();

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Data berhasil dihitung berdasarkan dimensi dan metriks.',
    //             'data' => $data,
    //             'query' => $sqlForDebug,
    //         ], 200);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Terjadi kesalahan saat mengambil data.',
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }
}
