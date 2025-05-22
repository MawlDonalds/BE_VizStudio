<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visualization;
use Carbon\Carbon;
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

    public function getTableDataByColumns(Request $request)
    {
        try {
            // Ambil koneksi database dari datasources
            $idDatasource = 1; // Hardcode datasource ID 1
            $dbConfig = $this->getConnectionDetails($idDatasource);

            // Buat koneksi on-the-fly
            config(["database.connections.dynamic" => $dbConfig]);

            // Gunakan koneksi yang baru dibuat
            $connection = DB::connection('dynamic');

            $table = $request->input('tabel');  // Nama tabel utama
            $dimensi = $request->input('dimensi', []);  // Array dimensi, bisa kosong
            $metriks = $request->input('metriks', []);   // Array metriks, bisa kosong
            $tabelJoin = $request->input('tabel_join', []); // Array of joins
            $filters = $request->input('filters', []); // Array of filters

            // Validasi bahwa tabel ada
            if (empty($table)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nama tabel tidak boleh kosong.',
                ], 400);
            }

            // Pastikan tabel ada di DB
            $tableExists = $connection->select("
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
            $query = $connection->table($table);

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

            // Pilih kolom yang dibutuhkan (dimensi) jika ada
            if (!empty($dimensi)) {
                $query->select(DB::raw(implode(', ', $dimensi)));
            }

            // Menambahkan metriks jika ada
            if (!empty($metriks)) {
                foreach ($metriks as $metriksColumn) {
                    // Ambil nama kolom dan jenis agregasi
                    $columnParts = explode('|', $metriksColumn);
                    $columnName = $columnParts[0]; // Kolom yang digunakan
                    $aggregationType = isset($columnParts[1]) ? strtoupper($columnParts[1]) : 'COUNT'; // Tipe agregasi default COUNT

                    // Hilangkan nama tabel dari alias
                    $columnAlias = last(explode('.', $columnName)); // Ambil nama kolom saja, hilangkan nama tabel

                    // Tentukan jenis agregasi (COUNT, SUM, AVERAGE)
                    switch ($aggregationType) {
                        case 'SUM':
                            $query->addSelect(DB::raw("SUM({$columnName}) AS total_{$columnAlias}"));
                            break;
                        case 'AVERAGE':
                            $query->addSelect(DB::raw("AVG({$columnName}) AS avg_{$columnAlias}"));
                            break;
                        case 'COUNT':
                        default:
                            $query->addSelect(DB::raw("COUNT(DISTINCT {$columnName}) AS total_{$columnAlias}"));
                            break;
                    }
                }
            }

            // Group by dimensi hanya jika dimensi ada
            if (!empty($dimensi)) {
                $query->groupBy($dimensi);
            }

            // Order by dimensi atau metriks (perbaiki bagian orderBy)
            if (!empty($metriks)) {
                // Ambil kolom pertama dari metriks dan identifikasi agregasi untuk sorting
                $metriksParts = explode('|', $metriks[0]);
                $metriksColumn = $metriksParts[0];
                $aggregationType = isset($metriksParts[1]) ? strtoupper($metriksParts[1]) : 'COUNT';

                // Tentukan agregasi yang benar untuk orderBy
                switch ($aggregationType) {
                    case 'SUM':
                        $query->orderBy(DB::raw("SUM({$metriksColumn})"), 'desc');
                        break;
                    case 'AVERAGE':
                        $query->orderBy(DB::raw("AVG({$metriksColumn})"), 'desc');
                        break;
                    case 'COUNT':
                    default:
                        $query->orderBy(DB::raw("COUNT(DISTINCT {$metriksColumn})"), 'desc');
                        break;
                }
            } elseif (!empty($dimensi)) {
                // Jika tidak ada metriks, defaultnya berdasarkan dimensi
                $query->orderBy($dimensi[0], 'asc');
            }

            // Apply filters ke query builder
            $query = $this->applyFilters($query, $filters);
            $whereClause = $this->buildWhereClause($filters);

            // Bangun string query untuk debugging
            //$sqlForDebug = $query->toSql();
            $sqlForDebug = vsprintf(str_replace('?', "'%s'", $query->toSql()), $query->getBindings());

            // Eksekusi query
            $data = $query->get();

            // $savevisualization = $this->savevisualizationData($request, $sqlForDebug);

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil dihitung berdasarkan dimensi dan metriks.',
                'data' => $data,
                'query' => $sqlForDebug,
                // 'visualization_status' => $savevisualization,
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
        try {
            // Pastikan koneksi yang digunakan adalah koneksi yang telah dikonfigurasi dinamis
            $connection = DB::connection('dynamic');

            // Log untuk memeriksa input
            Log::info("Mencari foreign key antara {$table} dan {$joinTable}");

            // Pertama, coba cari foreign key langsung dari table ke joinTable
            $foreignKeys = $connection->select("
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

            // Log hasil foreign key pertama
            Log::info("Hasil pencarian foreign key pertama: ", ['foreignKeys' => $foreignKeys]);

            // Jika foreign key ditemukan, langsung kembalikan
            if (count($foreignKeys) > 0) {
                return $foreignKeys[0];  // Mengembalikan kolom foreign key langsung
            }

            // Jika tidak ditemukan, coba cari relasi dari joinTable ke table
            $foreignKeysReverse = $connection->select(" 
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

            // Log hasil foreign key kedua (reverse)
            Log::info("Hasil pencarian foreign key reverse: ", ['foreignKeysReverse' => $foreignKeysReverse]);

            // Jika ditemukan relasi balik, kembalikan
            if (count($foreignKeysReverse) > 0) {
                return $foreignKeysReverse[0]; // Mengembalikan foreign key yang ditemukan dari sisi sebaliknya
            }

            // Tidak ditemukan foreign key
            return null;
        } catch (\Exception $e) {
            Log::error("Terjadi kesalahan saat mencari foreign key: ", ['error' => $e->getMessage()]);
            return null;
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

            // Ambil koneksi database dari datasources (hardcoded untuk ID 1)
            $idDatasource = 1; // Hardcode datasource ID 1
            $dbConfig = $this->getConnectionDetails($idDatasource);

            // Buat koneksi on-the-fly menggunakan konfigurasi yang sudah diambil
            config(["database.connections.dynamic" => $dbConfig]);

            // Gunakan koneksi yang baru dibuat
            $connection = DB::connection('dynamic');

            // Menjalankan query SQL yang diberikan
            $result = $connection->select($query);

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
                                    if ($logic === 'or') {
                                        $q->orWhereNotBetween($column, $value);
                                    } else {
                                        $q->whereNotBetween($column, $value);
                                    }
                                } else {
                                    if ($logic === 'or') {
                                        $q->orWhereBetween($column, $value);
                                    } else {
                                        $q->whereBetween($column, $value);
                                    }
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

    public function saveVisualization(Request $request)
{
    try {
        // Validate basic fields
        $validated = $request->validate([
            'id_canvas' => 'required|integer',
            'id_datasource' => 'required|integer',
            'name' => 'required|string',
            'visualization_type' => 'required|string',
            'query' => 'required|string',
            'config' => 'nullable|array',
            'width' => 'nullable',
            'height' => 'nullable',
            'position_x' => 'nullable',
            'position_y' => 'nullable',
        ]);

        // Extract and prepare config data
        $config = $request->input('config', []);

        // Ensure all config values are properly captured
        $visualizationConfig = [
            'colors' => $config['colors'] ?? ['#4CAF50', '#FF9800', '#2196F3'],
            'backgroundColor' => $config['backgroundColor'] ?? '#ffffff',
            'title' => $config['title'] ?? $validated['name'],
            'fontSize' => $config['fontSize'] ?? 14,
            'fontFamily' => $config['fontFamily'] ?? 'Arial',
            'fontColor' => $config['fontColor'] ?? '#333',
            'gridColor' => $config['gridColor'] ?? '#E0E0E0',
            'pattern' => $config['pattern'] ?? 'solid',
            'titleFontSize' => $config['titleFontSize'] ?? 18,
            'titleFontFamily' => $config['titleFontFamily'] ?? 'Arial',
            'xAxisFontSize' => $config['xAxisFontSize'] ?? 12,
            'xAxisFontFamily' => $config['xAxisFontFamily'] ?? 'Arial',
            'yAxisFontSize' => $config['yAxisFontSize'] ?? 12,
            'yAxisFontFamily' => $config['yAxisFontFamily'] ?? 'Arial',
        ];

        // If visualizationOptions exists in config, merge it with our visualizationConfig
        if (isset($config['visualizationOptions']) && is_array($config['visualizationOptions'])) {
            $visualizationConfig['visualizationOptions'] = $config['visualizationOptions'];
        }

        // Try to find existing visualization first by canvas ID and query
        $visualization = Visualization::where('id_canvas', $validated['id_canvas'])
            ->where('query', $validated['query'])
            ->first();

        // Check if this is a position/size update only
        $isPositionUpdate = $request->has('position_x') || $request->has('position_y') || 
                            $request->has('width') || $request->has('height');

        if ($visualization) {
            $updateData = [
                'modified_by' => 1, // Replace with auth user ID
                'modified_time' => now(),
            ];
            
            // Only update these fields if explicitly provided
            if ($request->has('id_datasource')) {
                $updateData['id_datasource'] = $validated['id_datasource'];
            }
            
            if ($request->has('name')) {
                $updateData['name'] = $validated['name'];
            }
            
            if ($request->has('visualization_type')) {
                $updateData['visualization_type'] = $validated['visualization_type'];
            }
            
            // If this is not just a position update, update the config and query
            if (!$isPositionUpdate) {
                $updateData['config'] = $visualizationConfig;
                $updateData['query'] = $validated['query'];
            }
            
            // Always update position and size if provided
            if ($request->has('width')) {
                $updateData['width'] = $validated['width'];
            }
            
            if ($request->has('height')) {
                $updateData['height'] = $validated['height'];
            }
            
            if ($request->has('position_x')) {
                $updateData['position_x'] = $validated['position_x'];
            }
            
            if ($request->has('position_y')) {
                $updateData['position_y'] = $validated['position_y'];
            }
            
            $visualization->update($updateData);
            
            // Log the operation type
            $logMessage = $isPositionUpdate ? 
                'visualization position/size updated' : 
                'visualization fully updated';
                
            Log::info($logMessage, [
                'visualization_id' => $visualization->id,
                'name' => $visualization->name,
                'position_x' => $visualization->position_x,
                'position_y' => $visualization->position_y,
                'width' => $visualization->width,
                'height' => $visualization->height
            ]);
        } else {
            // Create new visualization with all data
            $visualization = Visualization::create([
                'id_canvas' => $validated['id_canvas'],
                'id_datasource' => $validated['id_datasource'],
                'name' => $validated['name'],
                'visualization_type' => $validated['visualization_type'],
                'query' => $validated['query'],
                'config' => $visualizationConfig,
                'width' => $validated['width'] ?? 800,
                'height' => $validated['height'] ?? 350,
                'position_x' => $validated['position_x'] ?? 0,
                'position_y' => $validated['position_y'] ?? 0,
                'created_time' => now(),
                'modified_time' => now(),
                'created_by' => 1, // Replace with auth user ID
                'modified_by' => 1, // Replace with auth user ID
            ]);
            
            Log::info('New visualization created', [
                'visualization_id' => $visualization->id,
                'name' => $visualization->name,
                'visualization_type' => $visualization->visualization_type
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Visualisasi berhasil disimpan',
            'data' => $visualization
        ], 200);
    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Validasi gagal',
            'errors' => $e->errors()
        ], 422);
    } catch (\Exception $e) {
        Log::error('Error saving visualization: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
            'request' => $request->all()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Terjadi kesalahan saat menyimpan visualization',
            'error' => $e->getMessage()
        ], 500);
    }
}

    // Add this new API endpoint to your controller

    /**
     * Retrieve visualization position data
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getvisualizationPosition(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'query' => 'required|string',
                'visualization_type' => 'required|string',
            ]);

            // Find visualization by query and visualization type
            $visualization = Visualization::where('query', $validated['query'])
                ->where('visualization_type', $validated['visualization_type'])
                ->first();

            if (!$visualization) {
                return response()->json([
                    'success' => false,
                    'message' => 'Visualisasi tidak ditemukan'
                ], 404);
            }

            // Return visualization position data
            return response()->json([
                'success' => true,
                'message' => 'Data posisi visualization berhasil diambil',
                'data' => [
                    'width' => $visualization->width,
                    'height' => $visualization->height,
                    'position_x' => $visualization->position_x,
                    'position_y' => $visualization->position_y
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error retrieving visualization position: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data posisi visualization',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    // public function getVisualisasiData(Request $request)
    // {
    //     try {
    //         $idDatasource = 1;
    //         $dbConfig = $this->getConnectionDetails($idDatasource);
    //         config(["database.connections.dynamic" => $dbConfig]);
    //         $connection = DB::connection('dynamic');

    //         $table = $request->input('tabel');
    //         $dimensi = $request->input('dimensi', []);
    //         $metriks = $request->input('metriks', []);
    //         $tabelJoin = $request->input('tabel_join', []);
    //         $filters = $request->input('filters', []);

    //         $query = $connection->table($table);
    //         $previousTable = $table;

    //         foreach ($tabelJoin as $join) {
    //             $joinTable = $join['tabel'];
    //             $joinType = strtoupper($join['join_type']);
    //             $foreignKey = $this->getForeignKey($previousTable, $joinTable);

    //             if ($foreignKey) {
    //                 $query->join(
    //                     $joinTable,
    //                     "{$previousTable}.{$foreignKey->foreign_column}",
    //                     '=',
    //                     "{$joinTable}.{$foreignKey->referenced_column}",
    //                     $joinType
    //                 );
    //             }
    //             $previousTable = $joinTable;
    //         }

    //         $query->select(DB::raw(implode(', ', $dimensi)));
    //         foreach ($metriks as $metriksColumn) {
    //             $columnName = last(explode('.', $metriksColumn));
    //             $query->addSelect(DB::raw("COUNT(DISTINCT {$metriksColumn}) AS total_{$columnName}"));
    //         }

    //         $query->groupBy($dimensi);
    //         $query = $this->applyFilters($query, $filters);

    //         $data = $query->get();

    //         return response()->json([
    //             'success' => true,
    //             'data' => $data,
    //             'labels' => $dimensi,
    //             'series' => $metriks,
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Error visualisasi data',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function getVisualisasiData(Request $request)
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

        // Ambil koneksi database dari datasources (hardcoded untuk ID 1)
        $idDatasource = 1;
        $dbConfig = $this->getConnectionDetails($idDatasource);

        // Buat koneksi on-the-fly menggunakan konfigurasi yang sudah diambil
        config(["database.connections.dynamic" => $dbConfig]);
        $connection = DB::connection('dynamic');

        // Menjalankan query SQL yang diberikan
        $rawResults = $connection->select($query);

        if (empty($rawResults)) {
            return response()->json([
                'success' => true,
                'message' => 'Query berhasil dijalankan, namun tidak ada data.',
                'data' => [],
            ], 200);
        }

        // Ambil kolom pertama sebagai label
        $firstRow = (array)$rawResults[0];
        $labelKey = array_key_first($firstRow);

        // Format dan normalisasi hasil seperti getVisualisasiData, tapi tanpa groupBy
        $formattedData = collect($rawResults)->map(function ($row) use ($labelKey) {
            $formattedRow = [];

            foreach ((array) $row as $key => $value) {
                if ($key === $labelKey) {
                    $formattedRow[$key] = empty(trim($value)) || strtolower(trim($value)) === 'null'
                        ? 'Tidak diketahui'
                        : $value;
                } else {
                    $formattedRow[$key] = is_null($value) || $value === '' || $value === 'null'
                        ? 0
                        : (is_numeric($value) ? $value : 0);
                }
            }

            return $formattedRow;
        });

        return response()->json([
            'success' => true,
            'message' => 'Query berhasil dijalankan.',
            'data' => $formattedData,
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Terjadi kesalahan saat menjalankan query.',
            'error' => $e->getMessage(),
        ], 500);
    }
}


}
