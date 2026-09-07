<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ResetDataController extends Controller
{
    public function resetData(Request $request)
    {
        try {


            // Disable foreign key checks temporarily
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            // Get all tables from database
            $tables = DB::select('SHOW TABLES');
            $databaseName = DB::getDatabaseName();
            $tableKey = "Tables_in_{$databaseName}";

            // Truncate all tables
            $truncatedTables = [];
            foreach ($tables as $table) {
                $tableName = $table->$tableKey;
                try {
                    DB::statement("TRUNCATE TABLE `{$tableName}`;");
                    $truncatedTables[] = $tableName;
                } catch (\Exception $e) {
                    // Log error but continue with other tables
                }
            }

            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            // Import SQL file
            $sqlFilePath = base_path('reset_button_sql.sql');

            if (!File::exists($sqlFilePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'SQL file not found: ' . $sqlFilePath
                ], 500);
            }



            // Use PDO to execute SQL file more efficiently
            $pdo = DB::connection()->getPdo();

            // Disable foreign key checks for import
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0;');
            $pdo->exec('SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";');
            $pdo->exec('SET time_zone = "+00:00";');

            // Read SQL file
            $sql = File::get($sqlFilePath);

            // Remove BOM if present
            $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);

            // Remove comments (single line -- comments)
            $sql = preg_replace('/--.*$/m', '', $sql);

            // Remove multi-line comments /* */
            $sql = preg_replace('/\/\*[\s\S]*?\*\//', '', $sql);

            // Split by semicolon, but handle quoted strings properly
            $statements = [];
            $currentStatement = '';
            $inString = false;
            $stringChar = '';
            $len = strlen($sql);

            for ($i = 0; $i < $len; $i++) {
                $char = $sql[$i];
                $nextChar = ($i < $len - 1) ? $sql[$i + 1] : '';

                // Handle string escaping
                if ($char === '\\' && $inString) {
                    $currentStatement .= $char . $nextChar;
                    $i++;
                    continue;
                }

                // Toggle string state
                if (($char === '"' || $char === "'" || $char === '`') && !$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === $stringChar && $inString) {
                    $inString = false;
                    $stringChar = '';
                }

                $currentStatement .= $char;

                // If we hit a semicolon and we're not in a string, end the statement
                if ($char === ';' && !$inString) {
                    $statement = trim($currentStatement);
                    if (
                        !empty($statement) &&
                        !preg_match('/^(SET|START TRANSACTION|COMMIT)/i', $statement)
                    ) {
                        $statements[] = $statement;
                    }
                    $currentStatement = '';
                }
            }

            // Add any remaining statement
            if (!empty(trim($currentStatement))) {
                $statements[] = trim($currentStatement);
            }

            $executedCount = 0;
            $failedCount = 0;

            foreach ($statements as $index => $statement) {
                $statement = trim($statement);
                if (empty($statement)) {
                    continue;
                }

                try {
                    $pdo->exec($statement);
                    $executedCount++;

                    // Log progress for large imports
                    if (($index + 1) % 100 === 0) {
                        // Progress tracking
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                }
            }

            // Re-enable foreign key checks
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1;');


            return response()->json([
                'success' => true,
                'message' => 'Data reset successfully completed. ' . count($truncatedTables) . ' tables truncated and SQL file imported.',
                'truncated_tables' => count($truncatedTables),
                'sql_statements_executed' => $executedCount,
                'sql_statements_failed' => $failedCount
            ]);
        } catch (\Exception $e) {
            // Re-enable foreign key checks even on error
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            } catch (\Exception $fkException) {
                // Ignore if we can't re-enable
            }


            return response()->json([
                'success' => false,
                'message' => 'Error resetting data: ' . $e->getMessage()
            ], 500);
        }
    }
}
