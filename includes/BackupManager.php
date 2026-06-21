<?php
/**
 * LPPAI Corner - Backup Manager
 */

require_once __DIR__ . '/../config/database.php';

class BackupManager {
    
    public static function exportDatabase($outputFilePath) {
        $pdo = getDBConnection();
        $tables = [];
        $query = $pdo->query('SHOW TABLES');
        while ($row = $query->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        $fp = fopen($outputFilePath, 'w');
        if (!$fp) {
            throw new Exception("Tidak dapat membuat file backup.");
        }

        fwrite($fp, "-- LPPAI Corner Database Backup\n");
        fwrite($fp, "-- Waktu Pembuatan: " . date('Y-m-d H:i:s') . "\n\n");
        fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            fwrite($fp, "DROP TABLE IF EXISTS `$table`;\n");
            $row2 = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
            fwrite($fp, "\n" . $row2[1] . ";\n\n");

            $rows = $pdo->query("SELECT * FROM `$table`");
            $rowCount = $rows->rowCount();
            
            if ($rowCount > 0) {
                fwrite($fp, "INSERT INTO `$table` VALUES \n");
                $counter = 0;
                while ($row = $rows->fetch(PDO::FETCH_NUM)) {
                    $counter++;
                    $sql = "(";
                    for ($j = 0; $j < count($row); $j++) {
                        if (isset($row[$j])) {
                            $val = str_replace("\n", "\\n", addslashes($row[$j]));
                            $sql .= "'" . $val . "'";
                        } else {
                            $sql .= "NULL";
                        }
                        if ($j < (count($row) - 1)) {
                            $sql .= ",";
                        }
                    }
                    $sql .= ")";
                    if ($counter < $rowCount) {
                        $sql .= ",\n";
                    } else {
                        $sql .= ";\n";
                    }
                    fwrite($fp, $sql);
                }
            }
            fwrite($fp, "\n\n");
        }
        
        fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fp);
        return true;
    }

    public static function restoreDatabase($inputFilePath) {
        if (!file_exists($inputFilePath)) {
            throw new Exception("File backup tidak ditemukan.");
        }
        
        $sql = file_get_contents($inputFilePath);
        if (empty($sql)) {
            throw new Exception("File backup kosong.");
        }
        
        $pdo = getDBConnection();
        
        // Execute whole script
        try {
            $pdo->exec($sql);
            return true;
        } catch (PDOException $e) {
            throw new Exception("Gagal merestore database: " . $e->getMessage());
        }
    }

    public static function autoBackup() {
        $backupDir = __DIR__ . '/../backups/';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
            @file_put_contents($backupDir . '.htaccess', "Deny from all");
        }

        $todayFile = $backupDir . 'auto_' . date('Y-m-d') . '.sql';

        if (!file_exists($todayFile)) {
            // Lakukan ekspor
            self::exportDatabase($todayFile);

            // Pembersihan otomatis: simpan hanya 3 file "auto_" terbaru
            $files = glob($backupDir . 'auto_*.sql');
            if ($files && count($files) > 3) {
                // Sort files by modification time, newest first
                usort($files, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });

                // Hapus file ke-4 dan seterusnya
                $filesToDelete = array_slice($files, 3);
                foreach ($filesToDelete as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
        }
    }
}
