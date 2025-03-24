<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

class ApiConnectDatabaseController extends Controller
{
    public function connectAndFetch(Request $request)
    {
        // Validasi input dari user
        $request->validate([
            'host' => 'required|string',
            'port' => 'required|integer',
            'database' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        // Tangkap input dari user
        $host = $request->host;
        $port = $request->port;
        $database = $request->database;
        $username = $request->username;
        $password = $request->password;

        // Konfigurasi koneksi database secara dinamis
        $connectionName = 'dynamic_db';
        ConfiG::set("database.connections.$connectionName", [
            'driver' => 'pgsql', // Ganti ke 'mysql' jika menggunakan MySQL
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix' => '',
            'schema' => 'public',
        ]);

        try {
            // Pastikan koneksi berhasil
            DB::purge($connectionName);
            DB::connection($connectionName)->getPdo();

            // Ambil daftar tabel
            $tables = DB::connection($connectionName)
                ->select("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'");

            // Ubah hasil ke array sederhana
            $tableNames = array_map(fn ($t) => $t->tablename, $tables);

            // Ambil daftar kolom dan data per tabel
            $result = [];

            foreach ($tableNames as $table) {
                // Ambil kolom
                $columns = DB::connection($connectionName)
                    ->select("SELECT column_name FROM information_schema.columns WHERE table_name = ?", [$table]);

                // Ambil data (limit 5 agar tidak berat)
                $data = DB::connection($connectionName)->table($table)->limit(5)->get();

                $result[] = [
                    'table' => $table,
                    'columns' => array_map(fn ($c) => $c->column_name, $columns),
                    'data' => $data
                ];
            }

            return response()->json([
                'success' => true,
                'tables' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Koneksi gagal: ' . $e->getMessage()
            ], 500);
        }
    }
}
