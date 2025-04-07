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

    public function getTableDataByColumns(Request $request)
    {
        try {
            $table = $request->input('tabel');  // Nama tabel utama
            $dimensi = $request->input('dimensi', []);  // Array dimensi
            $metriks = $request->input('metriks', []);   // Array metriks
            $tabelJoin = $request->input('tabel_join', []); // Array of joins

            // Validasi bahwa tabel ada
            if (empty($table)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nama tabel tidak boleh kosong.',
                ], 400);
            }

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

            // Mulai membangun query dasar dengan tabel utama yang diterima
            $query = DB::table($table);

            // Set tabel sebelumnya untuk referensi dalam join
            $previousTable = $table;

            // Lakukan join berdasarkan urutan tabel yang diberikan dalam input
            foreach ($tabelJoin as $join) {
                $joinTable = isset($join['tabel']) ? $join['tabel'] : null;
                $joinType = strtoupper($join['join_type']); // pastikan join_type adalah 'inner', 'left', 'right', dll.

                // Cari foreign key antara tabel yang sedang di-join
                $foreignKey = $this->getForeignKey($previousTable, $joinTable);

                if ($foreignKey) {
                    // Gabungkan tabel menggunakan relasi foreign key yang ditemukan
                    $query->join(
                        $joinTable,
                        "{$previousTable}.{$foreignKey->foreign_column}",
                        '=',
                        "{$joinTable}.{$foreignKey->referenced_column}",
                        $joinType
                    );
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => "Relasi antara {$previousTable} dan {$joinTable} tidak ditemukan.",
                    ], 400);
                }

                // Perbarui previousTable agar selalu mengarah ke tabel yang baru di-join
                $previousTable = $joinTable;
            }

            // Pilih kolom yang dibutuhkan (dimensi)
            $query->select(DB::raw(implode(', ', $dimensi)));

            // Menambahkan metriks jika ada
            foreach ($metriks as $metriksColumn) {
                // Ambil nama kolom saja (hapus nama tabelnya) untuk alias yang lebih singkat
                $columnName = last(explode('.', $metriksColumn)); // Mengambil nama kolom setelah titik (jika ada)
                $query->addSelect(DB::raw("COUNT(DISTINCT {$metriksColumn}) AS total_{$columnName}"));
            }

            // Group by dimensi
            $query->groupBy($dimensi);

            // Order by dimensi atau metriks
            if (!empty($metriks)) {
                $query->orderBy(DB::raw("COUNT(DISTINCT {$metriks[0]})"), 'desc');
            } else {
                $query->orderBy($dimensi[0], 'asc');
            }

            // Bangun string query untuk debugging
            $sqlForDebug = $query->toSql();

            // Eksekusi query
            $data = $query->get();

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil dihitung berdasarkan dimensi dan metriks.',
                'data' => $data,
                'query' => $sqlForDebug,  // Menambahkan query untuk debugging
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function getForeignKey($table, $joinTable)
    {
        // Pertama, coba cari foreign key langsung dari table ke joinTable
        $foreignKeys = DB::select("
        SELECT kcu.column_name AS foreign_column, ccu.column_name AS referenced_column
        FROM information_schema.key_column_usage kcu
        JOIN information_schema.constraint_column_usage ccu
            ON kcu.constraint_name = ccu.constraint_name
        JOIN information_schema.table_constraints tc
            ON tc.constraint_name = kcu.constraint_name
        WHERE tc.constraint_type = 'FOREIGN KEY'
            AND lower(kcu.table_name) = lower(?) 
            AND lower(ccu.table_name) = lower(?)
    ", [strtolower($table), strtolower($joinTable)]);

        // Jika foreign key ditemukan, langsung kembalikan
        if (count($foreignKeys) > 0) {
            return $foreignKeys[0];  // Mengembalikan kolom foreign key langsung
        }

        // Jika tidak ditemukan, coba cari relasi dari joinTable ke table
        $foreignKeysReverse = DB::select(" 
        SELECT kcu.column_name AS foreign_column, ccu.column_name AS referenced_column
        FROM information_schema.key_column_usage kcu
        JOIN information_schema.constraint_column_usage ccu
            ON kcu.constraint_name = ccu.constraint_name
        JOIN information_schema.table_constraints tc
            ON tc.constraint_name = kcu.constraint_name
        WHERE tc.constraint_type = 'FOREIGN KEY'
            AND lower(kcu.table_name) = lower(?) 
            AND lower(ccu.table_name) = lower(?)
    ", [strtolower($joinTable), strtolower($table)]);

        // Jika ditemukan relasi balik, kembalikan
        if (count($foreignKeysReverse) > 0) {
            return $foreignKeysReverse[0]; // Mengembalikan foreign key yang ditemukan dari sisi sebaliknya
        }

        // Tidak ditemukan foreign key
        return null;
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
