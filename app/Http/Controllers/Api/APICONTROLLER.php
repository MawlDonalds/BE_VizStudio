<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visualization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;

class ApiGetDataController extends Controller
{
    private $warehouseConnectionName = 'pgsql2';
    private $schemaMetadata = null;

    private function _getSchemaMetadata()
    {
        if ($this->schemaMetadata !== null) {
            return $this->schemaMetadata;
        }

        $connection = DB::connection($this->warehouseConnectionName);
        $schema = 'public';

        $columnsResult = $connection->select("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = ? ORDER BY table_name, ordinal_position", [$schema]);
        $tableColumns = [];
        foreach ($columnsResult as $col) {
            $tableColumns[strtolower($col->table_name)][] = strtolower($col->column_name);
        }

        $pkResult = $connection->select("SELECT tc.table_name, kcu.column_name FROM information_schema.table_constraints AS tc JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = ?", [$schema]);
        $primaryKeys = [];
        foreach ($pkResult as $pk) {
            $primaryKeys[strtolower($pk->table_name)] = strtolower($pk->column_name);
        }
        
        $fkResult = $connection->select("SELECT tc.table_name AS referencing_table, kcu.column_name AS referencing_column, ccu.table_name AS referenced_table, ccu.column_name AS referenced_column FROM information_schema.table_constraints AS tc JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema JOIN information_schema.constraint_column_usage AS ccu ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = ?", [$schema]);
        
        $this->schemaMetadata = [
            'tableColumns' => $tableColumns,
            'primaryKeys' => $primaryKeys,
            'foreignKeys' => array_map(function($fk) {
                $fk->referencing_table = strtolower($fk->referencing_table);
                $fk->referencing_column = strtolower($fk->referencing_column);
                $fk->referenced_table = strtolower($fk->referenced_table);
                $fk->referenced_column = strtolower($fk->referenced_column);
                return $fk;
            }, $fkResult)
        ];

        return $this->schemaMetadata;
    }

    private function getForeignKey($tableA, $tableB, $metadata)
    {
        $tableA = strtolower($tableA);
        $tableB = strtolower($tableB);

        foreach ($metadata['foreignKeys'] as $fk) {
            if (($fk->referencing_table === $tableA && $fk->referenced_table === $tableB) ||
                ($fk->referencing_table === $tableB && $fk->referenced_table === $tableA)) {
                return $fk;
            }
        }
        
        $pkOfA = $metadata['primaryKeys'][$tableA] ?? null;
        if ($pkOfA && isset($metadata['tableColumns'][$tableB]) && in_array($pkOfA, $metadata['tableColumns'][$tableB])) {
            return (object) [
                'referencing_table' => $tableB, 'referencing_column' => $pkOfA,
                'referenced_table' => $tableA, 'referenced_column' => $pkOfA
            ];
        }

        $pkOfB = $metadata['primaryKeys'][$tableB] ?? null;
        if ($pkOfB && isset($metadata['tableColumns'][$tableA]) && in_array($pkOfB, $metadata['tableColumns'][$tableA])) {
            return (object) [
                'referencing_table' => $tableA, 'referencing_column' => $pkOfB,
                'referenced_table' => $tableB, 'referenced_column' => $pkOfB
            ];
        }

        return null;
    }
    
    public function getJoinableTables(Request $request)
    {
        $validated = $request->validate(['existing_tables' => 'present|array']);
        $existingTables = array_unique($validated['existing_tables']);
        
        $metadata = $this->_getSchemaMetadata();
        $allTablesInWarehouse = array_keys($metadata['tableColumns']);

        if (empty($existingTables)) {
            return response()->json(['success' => true, 'data' => $allTablesInWarehouse]);
        }

        $lastSelectedTable = Arr::last($existingTables);
        if (!$lastSelectedTable) {
             return response()->json(['success' => true, 'data' => $allTablesInWarehouse]);
        }
        
        $joinableTables = [];
        foreach ($allTablesInWarehouse as $candidateTable) {
            if (strtolower($candidateTable) === strtolower($lastSelectedTable)) continue;

            if ($this->getForeignKey($lastSelectedTable, $candidateTable, $metadata)) {
                $joinableTables[] = $candidateTable;
            }
        }
        
        $finalList = array_unique(array_merge($existingTables, $joinableTables));
        
        $lastTablePrefix = explode('__', $lastSelectedTable)[0];
        
        usort($finalList, function ($a, $b) use ($lastTablePrefix, $lastSelectedTable) {
            if ($a === $lastSelectedTable) return 1;
            if ($b === $lastSelectedTable) return -1;

            $aMatchesPrefix = (explode('__', $a)[0] === $lastTablePrefix);
            $bMatchesPrefix = (explode('__', $b)[0] === $lastTablePrefix);

            if ($aMatchesPrefix && !$bMatchesPrefix) return -1;
            if (!$aMatchesPrefix && $bMatchesPrefix) return 1;
            
            return strcasecmp($a, $b);
        });
        
        return response()->json(['success' => true, 'data' => $finalList]);
    }

    public function getTableDataByColumns(Request $request)
    {
        try {
            $connection = DB::connection($this->warehouseConnectionName);
            $table = $request->input('tabel');
            if (empty($table)) {
                return response()->json(['success' => false, 'message' => 'Nama tabel tidak boleh kosong.'], 400);
            }
            $query = $connection->table($table);
            $userInputDimensi = $request->input('dimensi', []);
            $metriks = $request->input('metriks', []);
            $tabelJoin = $request->input('tabel_join', []);
            $metadata = null; 

            $previousTable = $table;
            if (!empty($tabelJoin)) {
                $metadata = $this->_getSchemaMetadata();
                foreach ($tabelJoin as $join) {
                    $joinTable = isset($join['tabel']) ? $join['tabel'] : null;
                    $joinType = strtoupper($join['join_type'] ?? 'INNER');
                    if ($joinTable) {
                        if ($joinType === 'CROSS') {
                            $query->crossJoin($joinTable);
                        } else {
                            $foreignKey = $this->getForeignKey($previousTable, $joinTable, $metadata);
                            if ($foreignKey) {
                                $query->join($joinTable, "{$foreignKey->referencing_table}.{$foreignKey->referencing_column}", '=', "{$foreignKey->referenced_table}.{$foreignKey->referenced_column}", $joinType);
                            } else {
                                return response()->json(['success' => false, 'message' => "Foreign key not found for join between {$previousTable} and {$joinTable}."], 400);
                            }
                        }
                        $previousTable = $joinTable;
                    }
                }
            }
            
            $filters = $request->input('filters', []);
            $granularity = $request->input('granularity');
            $dateFilterDetails = $request->input('date_filter_details');
            $topN = $request->input('topN');
            $topNMetric = $request->input('topN_metric');
            $displayFormat = $request->input('display_format', 'auto');
            $selects = []; $groupBy = []; $orderBy = []; $rawGroupByExpressions = [];
            $granularityDateColumn = null;
            if ($granularity && $granularity !== 'asis' && $dateFilterDetails && isset($dateFilterDetails['column'])) {
                $granularityDateColumn = $dateFilterDetails['column'];
                $colParts = explode('.', $granularityDateColumn);
                $actualDateColumnForExpr = count($colParts) > 1 ? $granularityDateColumn : $table . '.' . $granularityDateColumn;
                $groupingExpr = null; $periodAlias = ''; $labelExpr = null;
                switch (strtolower($granularity)) {
                    case 'daily': $periodAlias = 'day_start'; $groupingExpr = DB::raw("DATE_TRUNC('day', {$actualDateColumnForExpr})"); $labelFormat = "YYYY-MM-DD"; switch ($displayFormat) { case 'week_number': $labelFormat = 'IYYY-"Week"-IW'; break; case 'month_name': $labelFormat = 'YYYY-Mon-DD'; break; } $labelExpr = DB::raw("TO_CHAR(DATE_TRUNC('day', {$actualDateColumnForExpr}), '{$labelFormat}')"); break;
                    case 'weekly': $periodAlias = 'week_start'; $groupingExpr = DB::raw("DATE_TRUNC('week', {$actualDateColumnForExpr})"); $labelFormat = 'IYYY-"Week"-IW'; switch ($displayFormat) { case 'month_name': case 'year': case 'original': $labelFormat = 'YYYY-MM-DD'; break; } $labelExpr = DB::raw("TO_CHAR(DATE_TRUNC('week', {$actualDateColumnForExpr}), '{$labelFormat}')"); break;
                    case 'monthly': $periodAlias = 'month_start'; $groupingExpr = DB::raw("DATE_TRUNC('month', {$actualDateColumnForExpr})"); $labelFormat = 'YYYY-Month'; switch ($displayFormat) { case 'original': $labelFormat = 'YYYY-MM'; break; } $labelExpr = DB::raw("TRIM(TO_CHAR(DATE_TRUNC('month', {$actualDateColumnForExpr}), '{$labelFormat}'))"); break;
                }
                if ($groupingExpr && $labelExpr && $periodAlias) {
                    $selects[] = new Expression($labelExpr->getValue($connection->getQueryGrammar()) . " AS period_label");
                    $selects[] = new Expression($groupingExpr->getValue($connection->getQueryGrammar()) . " AS {$periodAlias}");
                    $rawGroupByExpressions[] = $groupingExpr;
                    $orderBy[] = new Expression("{$periodAlias} ASC");
                }
            }
            foreach ($userInputDimensi as $dim) { if ($granularityDateColumn && $dim === $granularityDateColumn && $granularity !== 'asis') { continue; } $selects[] = $dim; $groupBy[] = $dim; }
            $selects = array_unique($selects, SORT_REGULAR); $groupBy = array_unique($groupBy, SORT_REGULAR);
            $hasAggregations = false;
            if (!empty($metriks)) {
                foreach ($metriks as $metrikColumn) {
                    $parts = explode('|', $metrikColumn); $columnName = $parts[0]; $aggregationType = isset($parts[1]) ? strtoupper($parts[1]) : 'COUNT'; $hasAggregations = true;
                    $columnAliasBase = str_replace(['.', '*'], ['_', 'all'], $columnName); $columnAliasBase = preg_replace('/[^a-zA-Z0-9_]/', '', $columnAliasBase);
                    switch ($aggregationType) {
                        case 'SUM': $selects[] = DB::raw("SUM({$columnName}) AS sum_{$columnAliasBase}"); break;
                        case 'AVERAGE': $selects[] = DB::raw("AVG({$columnName}) AS avg_{$columnAliasBase}"); break;
                        case 'MIN': $selects[] = DB::raw("MIN({$columnName}) AS min_{$columnAliasBase}"); break;
                        case 'MAX': $selects[] = DB::raw("MAX({$columnName}) AS max_{$columnAliasBase}"); break;
                        case 'COUNT': default: if ($columnName === '*') { $selects[] = DB::raw("COUNT(*) AS count_star"); } else { $selects[] = DB::raw("COUNT({$columnName}) AS count_{$columnAliasBase}"); } break;
                    }
                }
            }
            if (empty($selects)) { $query->selectRaw("1 AS placeholder_if_no_selects_error"); } else { $query->select($selects); }
            $this->applyFilters($query, $filters);
            if (!empty($groupBy) || !empty($rawGroupByExpressions)) { foreach ($groupBy as $gbItem) { $query->groupBy($gbItem); } foreach ($rawGroupByExpressions as $gbExpr) { $query->groupBy($gbExpr); } } elseif ($hasAggregations && !empty($userInputDimensi)) { foreach ($userInputDimensi as $gbItem) { $query->groupBy($gbItem); } }
            if (!empty($orderBy)) { foreach ($orderBy as $obItem) { if ($obItem instanceof Expression) { $query->orderByRaw($obItem->getValue($connection->getQueryGrammar())); } else { $parts = explode(' ', $obItem); $query->orderBy($parts[0], $parts[1] ?? 'asc'); } } } elseif (empty($orderBy) && $hasAggregations && !empty($userInputDimensi)) { $query->orderBy($userInputDimensi[0], 'asc'); }
            if ($topN && is_numeric($topN) && $topN > 0 && $hasAggregations) {
                $orderByMetric = $topNMetric ?? ($metriks[0] ?? null);
                if ($orderByMetric) {
                    $parts = explode('|', $orderByMetric); $columnName = $parts[0]; $aggregationType = isset($parts[1]) ? strtoupper($parts[1]) : 'COUNT';
                    $columnAliasBase = str_replace(['.', '*'], ['_', 'all'], $columnName); $columnAliasBase = preg_replace('/[^a-zA-Z0-9_]/', '', $columnAliasBase);
                    $orderColumn = '';
                    switch ($aggregationType) {
                        case 'SUM': $orderColumn = "sum_{$columnAliasBase}"; break;
                        case 'AVERAGE': $orderColumn = "avg_{$columnAliasBase}"; break;
                        case 'MIN': $orderColumn = "min_{$columnAliasBase}"; break;
                        case 'MAX': $orderColumn = "max_{$columnAliasBase}"; break;
                        case 'COUNT': default: $orderColumn = ($columnName === '*') ? 'count_star' : "count_{$columnAliasBase}"; break;
                    }
                    $query->orders = null; $query->orderBy($orderColumn, 'DESC');
                }
                $query->limit((int)$topN);
            }
            $sqlForDebug = vsprintf(str_replace(['%', '?'], ['%%', "'%s'"], $query->toSql()), $query->getBindings());
            $data = $query->get();
            return response()->json(['success' => true, 'message' => 'Data berhasil di-query.', 'data' => $data, 'query' => $sqlForDebug], 200);

        } catch (\Exception $e) {
            Log::error("Error in getTableDataByColumns: " . $e->getMessage() . " Stack: " . $e->getTraceAsString() . (isset($sqlForDebug) ? " SQL: " . $sqlForDebug : ""));
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan saat mengambil data: ' . $e->getMessage(), 'error_detail' => $e->getMessage(), 'query_attempted' => isset($sqlForDebug) ? $sqlForDebug : 'Query not fully built or error before build'], 500);
        }
    }

    public function getAllTables()
    {
        try {
            $tables = DB::connection($this->warehouseConnectionName)->select("
                SELECT table_name
                FROM information_schema.tables
                WHERE table_schema = 'public'
            ");
            $excludedTables = ['migrations', 'personal_access_tokens'];
            $tableNames = array_filter(array_map(fn($table) => $table->table_name, $tables), fn($tableName) => !in_array($tableName, $excludedTables));
            return response()->json(['success' => true, 'message' => 'Daftar tabel berhasil diambil dari data warehouse.', 'data' => array_values($tableNames)], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan saat mengambil daftar tabel dari data warehouse.', 'error' => $e->getMessage()], 500);
        }
    }

    public function getTableColumns($table)
    {
        try {
            $connection = DB::connection($this->warehouseConnectionName);
            $tableExists = $connection->select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?", [$table]);
            if (empty($tableExists)) {
                return response()->json(['success' => false, 'message' => "Tabel '{$table}' tidak ditemukan di data warehouse."], 404);
            }
            $columns = $connection->select("SELECT column_name, data_type, is_nullable, ordinal_position FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?", [$table]);
            $formattedColumns = array_map(function ($column) {
                return ['id' => $column->ordinal_position, 'name' => $column->column_name, 'type' => $column->data_type, 'nullable' => $column->is_nullable === 'YES'];
            }, $columns);
            return response()->json(['success' => true, 'message' => "Daftar kolom berhasil diambil dari tabel '{$table}'.", 'data' => $formattedColumns], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan saat mengambil daftar kolom.', 'error' => $e->getMessage()], 500);
        }
    }
    
    private function getConnectionDetails($idDatasource)
    {
        $datasource = DB::table('datasources')->where('id_datasource', $idDatasource)->first();
        if (!$datasource) throw new \Exception("Datasource dengan ID {$idDatasource} tidak ditemukan.");
        return ['driver' => $datasource->type, 'host' => $datasource->host, 'port' => $datasource->port, 'database' => $datasource->database_name, 'username' => $datasource->username, 'password' => $datasource->password, 'charset' => 'utf8', 'collation' => 'utf8_unicode_ci', 'prefix' => '', 'schema' => 'public'];
    }

    public function executeQuery(Request $request)
    {
        try {
            $query = $request->input('query');
            if (empty($query)) return response()->json(['success' => false, 'message' => 'Query SQL tidak boleh kosong.'], 400);
            $idDatasource = 1;
            $dbConfig = $this->getConnectionDetails($idDatasource);
            config(["database.connections.dynamic" => $dbConfig]);
            $connection = DB::connection('dynamic');
            $result = $connection->select($query);
            return response()->json(['success' => true, 'message' => 'Query berhasil dijalankan.', 'data' => $result], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan saat menjalankan query.', 'error' => $e->getMessage()], 500);
        }
    }

    public function applyFilters($query, $filters)
    {
        if (!is_array($filters) || empty($filters)) return $query;
        $query->where(function ($q) use ($filters) {
            foreach ($filters as $filter) {
                $column = $filter['column'] ?? null;
                $operator = strtolower($filter['operator'] ?? '=');
                $value = $filter['value'] ?? null;
                $logic = strtolower($filter['logic'] ?? 'and');
                $mode = strtolower($filter['mode'] ?? 'include');
                if (!$column || $value === null) continue;
                if ($operator === 'between') {
                    if (is_array($value) && count($value) === 2) {
                        $method = ($mode === 'exclude') ? 'whereNotBetween' : 'whereBetween';
                        if ($logic === 'or') $method = 'or' . ucfirst($method);
                        $q->{$method}($column, $value);
                    }
                } else {
                    $condition = [$column, $operator, $value];
                    if ($operator === 'like') $condition = [$column, 'LIKE', "%{$value}%"];
                    if ($mode === 'exclude') {
                        $logic === 'or' ? $q->orWhereNot(...$condition) : $q->whereNot(...$condition);
                    } else {
                        $logic === 'or' ? $q->orWhere(...$condition) : $q->where(...$condition);
                    }
                }
            }
        });
        return $query;
    }
}