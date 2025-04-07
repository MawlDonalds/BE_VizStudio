<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Datasource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class ApiConnectDatabaseController extends Controller
{
    public function connectDB(Request $request)
    {
        try {
            // Validasi input
            $request->validate([
                'id_project' => 'required|integer',
                'name' => 'required|string|max:255',
                'type' => 'required|string|max:8',
                'host' => 'required|string|max:255',
                'port' => 'required|integer',
                'database_name' => 'required|string|max:255',
                'username' => 'required|string|max:255',
                'password' => 'required|string|max:255',
            ]);

            // Simpan koneksi ke database
            $datasource = Datasource::create([
                'id_project'    => $request->id_project,
                'name'          => $request->name,
                'type'          => strtolower($request->type),
                'host'          => $request->host,
                'port'          => $request->port,
                'database_name' => $request->database_name,
                'username'      => $request->username,
                'password'      => $request->password,
                'created_by'    => 1, //auth()->id(),
                'created_time'  => now(),
                'is_deleted'    => 0
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Database telah ditambahkan',
                'data' => $datasource
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan koneksi database: ' . $e->getMessage()
            ], 500);
        }
    }

    public function fetchTables($idDatasource)
    {
        try {
            // Ambil konfigurasi database dari tabel `datasources`
            $staticId = 1;
            $datasource = Datasource::find($staticId);

            if (!$datasource) {
                return response()->json([
                    'success' => false,
                    'message' => 'Datasource tidak ditemukan.'
                ], 404);
            }

            // Set konfigurasi koneksi dinamis
            Config::set("database.connections.{$datasource->name}", [
                'driver'    => $datasource->type,
                'host'      => $datasource->host,
                'port'      => $datasource->port,
                'database'  => $datasource->database_name,
                'username'  => $datasource->username,
                'password'  => $datasource->password,
                'charset'   => 'utf8',
                'collation' => 'utf8_unicode_ci',
                'prefix'    => '',
                'schema'    => $datasource->type === 'pgsql' ? 'public' : null,
            ]);

            // Purge & connect ke database
            DB::purge($datasource->name);
            DB::connection($datasource->name)->getPdo();

            // Ambil daftar tabel
            $tables = $this->getTablesFromDatabase($datasource->name, $datasource->type);

            return response()->json([
                'success' => true,
                'data' => $tables
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil daftar tabel: ' . $e->getMessage()
            ], 500);
        }
    }

    private function getTablesFromDatabase($connectionName, $dbType)
    {
        try {
            $tables = [];

            if ($dbType === 'pgsql') {
                $tables = DB::connection($connectionName)
                    ->select("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'");
                $tables = array_map(fn($t) => $t->tablename, $tables);
            } elseif ($dbType === 'mysql' || $dbType === 'mariadb') {
                $tables = DB::connection($connectionName)->select("SHOW TABLES");
                $tables = array_map(fn($t) => array_values((array) $t)[0], $tables);
            } elseif ($dbType === 'sqlsrv') {
                $tables = DB::connection($connectionName)
                    ->select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'");
                $tables = array_map(fn($t) => $t->TABLE_NAME, $tables);
            }

            return $tables;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil daftar tabel dari database: ' . $e->getMessage()
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

    public function getTableColumns($table)
    {
        try {
            // Ambil koneksi database dari datasources
            $idDatasource = 1;
            $dbConfig = $this->getConnectionDetails($idDatasource);

            // Buat koneksi on-the-fly
            config(["database.connections.dynamic" => $dbConfig]);

            // Gunakan koneksi yang baru dibuat
            $connection = DB::connection('dynamic');

            // Periksa apakah tabel ada
            $tableExists = $connection->select("
            SELECT table_name FROM information_schema.tables 
            WHERE table_schema = 'public' AND table_name = ?
        ", [$table]);

            if (empty($tableExists)) {
                return response()->json([
                    'success' => false,
                    'message' => "Tabel '{$table}' tidak ditemukan di database."
                ], 404);
            }

            // Ambil daftar kolom
            $columns = $connection->select("
            SELECT column_name, data_type, is_nullable, ordinal_position
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = ?
        ", [$table]);

            $formattedColumns = array_map(function ($column) {
                return [
                    'id'       => $column->ordinal_position,
                    'name'     => $column->column_name,
                    'type'     => $column->data_type,
                    'nullable' => $column->is_nullable === 'YES',
                ];
            }, $columns);

            return response()->json([
                'success' => true,
                'message' => "Daftar kolom berhasil diambil dari tabel '{$table}'.",
                'data'    => $formattedColumns,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil daftar kolom.',
                'error'   => $e->getMessage(),
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

    // public function connectAndFetch(Request $request)
    // {
    //     // Validasi input dari user
    //     $request->validate([
    //         'name' => 'required|string|max:255',
    //         'type' => 'required|string|max:8',
    //         'host' => 'required|string|max:255',
    //         'port' => 'required|integer',
    //         'databaseName' => 'required|string|max:255',
    //         'username' => 'required|string|max:255',
    //         'password' => 'required|string|max:255',
    //     ]);

    //     // Tangkap input dari user
    //     $name = $request->name;
    //     $type = strtolower($request->type); // Pastikan lowercase untuk perbandingan
    //     $host = $request->host;
    //     $port = $request->port;
    //     $databaseName = $request->databaseName;
    //     $username = $request->username;
    //     $password = $request->password;

    //     // Konfigurasi koneksi database secara dinamis
    //     Config::set("database.connections.$name", [
    //         'driver' => $type,
    //         'host' => $host,
    //         'port' => $port,
    //         'database' => $databaseName,
    //         'username' => $username,
    //         'password' => $password,
    //         'charset' => 'utf8',
    //         'collation' => 'utf8_unicode_ci',
    //         'prefix' => '',
    //         'schema' => $type === 'pgsql' ? 'public' : null, // Hanya untuk PostgreSQL
    //     ]);

    //     try {
    //         // Pastikan koneksi berhasil
    //         DB::purge($name);
    //         DB::connection($name)->getPdo();

    //         // Ambil daftar tabel sesuai jenis database
    //         $tables = [];
    //         if ($type === 'pgsql') {
    //             $tables = DB::connection($name)
    //                 ->select("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'");
    //             $tables = array_map(fn($t) => $t->tablename, $tables);
    //         } elseif ($type === 'mysql' || $type === 'mariadb') {
    //             $tables = DB::connection($name)->select("SHOW TABLES");
    //             $tables = array_map(fn($t) => array_values((array) $t)[0], $tables);
    //         } elseif ($type === 'sqlsrv') {
    //             $tables = DB::connection($name)
    //                 ->select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'");
    //             $tables = array_map(fn($t) => $t->TABLE_NAME, $tables);
    //         }

    //         // Ambil daftar kolom dan data per tabel
    //         $result = [];

    //         foreach ($tables as $table) {
    //             // Ambil kolom sesuai jenis database
    //             $columnsQuery = match ($type) {
    //                 'pgsql', 'mysql', 'mariadb' => "SELECT column_name FROM information_schema.columns WHERE table_name = ?",
    //                 'sqlsrv' => "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ?",
    //                 default => null
    //             };

    //             $columns = DB::connection($name)->select($columnsQuery, [$table]);
    //             $columns = array_map(fn($c) => $c->column_name ?? $c->COLUMN_NAME, $columns);

    //             // Ambil data (limit 5)
    //             $data = DB::connection($name)->table($table)->limit(5)->get();

    //             $result[] = [
    //                 'table' => $table,
    //                 'columns' => $columns,
    //                 'data' => $data
    //             ];
    //         }

    //         return response()->json([
    //             'success' => true,
    //             'tables' => $result
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Koneksi gagal: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }
}
