<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    //     public function getAllTables(Request $request)
    // {
    //     // Validasi input dari user
    //     $request->validate([
    //         'host' => 'required|string',
    //         'port' => 'required|integer',
    //         'database' => 'required|string',
    //         'username' => 'required|string',
    //         'password' => 'required|string',
    //     ]);

    //     // Tangkap input dari user
    //     $host = $request->host;
    //     $port = $request->port;
    //     $database = $request->database;
    //     $username = $request->username;
    //     $password = $request->password;

    //     // Konfigurasi koneksi database secara dinamis
    //     $connectionName = 'dynamic_db';
    //     Config::set("database.connections.$connectionName", [
    //         'driver' => 'pgsql', // Ganti ke 'mysql' jika menggunakan MySQL
    //         'host' => $host,
    //         'port' => $port,
    //         'database' => $database,
    //         'username' => $username,
    //         'password' => $password,
    //         'charset' => 'utf8',
    //         'collation' => 'utf8_unicode_ci',
    //         'prefix' => '',
    //         'schema' => 'public', // Hanya untuk PostgreSQL
    //     ]);

    //     try {
    //         // Pastikan koneksi berhasil
    //         DB::purge($connectionName);
    //         DB::connection($connectionName)->getPdo();

    //         // Cek driver yang digunakan (PostgreSQL atau MySQL)
    //         $driver = DB::connection($connectionName)->getDriverName();

    //         // Query untuk mendapatkan daftar tabel berdasarkan driver
    //         if ($driver === 'pgsql') {
    //             $query = "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'";
    //         } elseif ($driver === 'mysql') {
    //             $query = "SHOW TABLES";
    //         } else {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => "Driver '$driver' tidak didukung."
    //             ], 400);
    //         }

    //         // Ambil daftar tabel
    //         $tables = DB::connection($connectionName)->select($query);

    //         // Konversi hasil berdasarkan driver
    //         if ($driver === 'pgsql') {
    //             $tableNames = array_map(fn($table) => $table->table_name, $tables);
    //         } elseif ($driver === 'mysql') {
    //             $tableNames = array_map(fn($table) => reset((array) $table), $tables);
    //         }

    //         // Daftar tabel yang akan diabaikan
    //         $excludedTables = ['migrations', 'personal_access_tokens'];

    //         // Filter tabel yang akan ditampilkan
    //         $filteredTables = array_values(array_filter($tableNames, fn($table) => !in_array($table, $excludedTables)));

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Daftar tabel berhasil diambil.',
    //             'data' => $filteredTables,
    //         ], 200);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Terjadi kesalahan saat mengambil daftar tabel.',
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }


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
            $filters = $request->input('filters', []);

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

            // Filter
            $query = $this->applyFilters($query, $filters);

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
            %s
            GROUP BY %s ORDER BY %s DESC",
                $selectColumns,
                $this->buildWhereClause($filters),
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

    public function applyFilters($query, $filters)
    {
        try {
            if (!is_array($filters) || empty($filters)) {
                return $query;
            }

            $query->where(function ($q) use ($filters) {
                foreach ($filters as $filter) {
                    $column = $filter['column'] ?? null;
                    $operator = strtolower($filter['operator'] ?? '=');
                    $value = $filter['value'] ?? null;
                    $logic = strtolower($filter['logic'] ?? 'and'); // and (default) atau or
                    $mode = strtolower($filter['mode'] ?? 'include'); // include atau exclude

                    if (!$column || !$value) {
                        continue;
                    }

                    switch ($operator) {
                        case 'like':
                            $condition = [$column, 'LIKE', "%{$value}%"];
                            break;

                        case 'between':
                            if (is_array($value) && count($value) === 2) {
                                if ($mode === 'exclude') {
                                    $q = ($logic === 'or') ? $q->orWhereNotBetween($column, $value) : $q->orWhereNotBetween($column, $value);
                                } else {
                                    $q = ($logic === 'or') ? $q->orWhereBetween($column, $value) : $q->whereBetween($column, $value);
                                }
                                continue 2;
                            }
                            continue 2;

                        default:
                            $condition = [$column, $operator, $value];
                            break;
                    }

                    if ($mode === 'exclude') {
                        if ($logic === 'or') {
                            $q->orWhereNot(...$condition);
                        } else {
                            $q->whereNot(...$condition);
                        }
                    } else {
                        if ($logic === 'or') {
                            $q->orWhere(...$condition);
                        } else {
                            $q->where(...$condition);
                        }
                    }
                }
            });

            return $query;
        } catch (\Exception $e) {
            Log::error('Error in applyFilters: ' . $e->getMessage());
            return $query;
        }
    }


    /**
     * Membuat WHERE clause untuk debugging query SQL
     */
    public function buildWhereClause($filters)
    {
        try {
            if (!is_array($filters) || empty($filters)) {
                return '';
            }

            $clauses = [];
            foreach ($filters as $filter) {
                $column = $filter['column'] ?? null;
                $operator = strtoupper($filter['operator'] ?? '=');
                $value = $filter['value'] ?? null;
                $logic = strtoupper($filter['logic'] ?? 'AND'); // AND atau OR
                $mode = strtoupper($filter['mode'] ?? 'INCLUDE');

                if (!$column || $value === null) {
                    continue;
                }

                if ($operator === 'LIKE') {
                    $value = "'%{$value}%'";
                } elseif ($operator === 'BETWEEN' && is_array($value) && count($value) === 2) {
                    $value = "{$value[0]} AND {$value[1]}";
                } else {
                    $value = "'{$value}'";
                }
                if ($mode === 'EXCLUDE') {
                    $clauses[] = "{$logic} NOT {$column} {$operator} {$value}";
                } else {
                    $clauses[] = "{$logic} {$column} {$operator} {$value}";
                }
            }

            return empty($clauses) ? '' : 'WHERE ' . preg_replace('/^AND |^OR /', '', implode(' ', $clauses));
        } catch (\Exception $e) {
            Log::error('Error in buildWhereClause: ' . $e->getMessage());
            return '';
        }
    }
}
