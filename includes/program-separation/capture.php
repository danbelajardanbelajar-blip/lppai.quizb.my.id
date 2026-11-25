<?php
declare(strict_types=1);
namespace Lppai\ProgramSeparation;
require_once __DIR__ . '/audit.php';

/** Read-only database inspection; no calls to application initializers. */
final class Capture
{
    public static function identifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) throw new \InvalidArgumentException('Unsupported table/column name.');
        return '`' . $name . '`';
    }

    public static function tables(\PDO $pdo): array
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite') {
            return $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        }
        $rows=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(\PDO::FETCH_COLUMN);
        return array_map('strval',$rows);
    }

    public static function academicTables(array $tables): array
    {
        return array_values(array_filter($tables,static fn(string $name):bool =>
            (bool)preg_match('/^(pretes_|tutorial_|master_pretes$|master_gelombang$|nilai|sertifikat|pengambilan_sertifikat)/D',$name)));
    }

    /** Stable multiset digest: includes every column/value and duplicates, independent of row order. */
    public static function fingerprint(\PDO $pdo, array $tables): array
    {
        sort($tables,SORT_STRING);$out=[];
        foreach ($tables as $table) {
            $stmt=$pdo->query('SELECT * FROM '.self::identifier($table));$hashes=[];
            while ($row=$stmt->fetch(\PDO::FETCH_ASSOC)) {
                // Preserve binary values without JSON substitution; normalize DB numeric fetch types.
                foreach ($row as &$value) if ($value!==null) $value=(string)$value;
                unset($value);ksort($row,SORT_STRING);$hashes[]=hash('sha256',serialize($row));
            }
            sort($hashes,SORT_STRING);
            $out[$table]=['rows'=>count($hashes),'sha256'=>hash('sha256',implode("\n",$hashes))];
        }
        return $out;
    }

    public static function minimumSnapshot(\PDO $pdo): array
    {
        $tables=self::tables($pdo);$snapshot=['schema_version'=>1,'tables'=>[]];$missing=[];
        foreach (Audit::FIELDS as $table=>$fields) {
            if (!in_array($table,$tables,true)) { $missing[$table]=['table_missing'];continue; }
            $columns=$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'
                ? array_column($pdo->query('PRAGMA table_info('.self::identifier($table).')')->fetchAll(\PDO::FETCH_ASSOC),'name')
                : array_column($pdo->query('SHOW COLUMNS FROM '.self::identifier($table))->fetchAll(\PDO::FETCH_ASSOC),'Field');
            if (!in_array('id',$columns,true)) throw new \RuntimeException('Snapshot table has no primary identifier.');
            $projection=[];
            foreach ($fields as $field) {
                $projection[]=(in_array($field,$columns,true) ? self::identifier($field) : 'NULL').' AS '.self::identifier($field);
                if (!in_array($field,$columns,true)) $missing[$table][]=$field;
            }
            foreach (Audit::OPTIONAL_FIELDS[$table]??[] as $field) {
                if (in_array($field,$columns,true)) $projection[]=self::identifier($field);
            }
            $where=$table==='users' && in_array('role',$columns,true) ? " WHERE role='mahasiswa'" : '';
            $snapshot['tables'][$table]=$pdo->query('SELECT '.implode(',',$projection).' FROM '.self::identifier($table).$where.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        }
        return ['snapshot'=>$snapshot,'missing_schema'=>$missing];
    }

    public static function privateDirectory(string $path, string $projectRoot): string
    {
        $dir=realpath($path);$root=realpath($projectRoot);
        if (!$dir || !$root || !is_dir($dir) || !is_writable($dir)) throw new \RuntimeException('Private output directory must exist and be writable.');
        $normalize=static fn(string $s):string => strtolower(rtrim(str_replace('\\','/',$s),'/'));
        $d=$normalize($dir);$r=$normalize($root);
        if ($d===$r || str_starts_with($d,$r.'/') || preg_match('~/(public_html|htdocs|www)(/|$)~',$d)) {
            throw new \RuntimeException('Backup directory must be outside the web root.');
        }
        if (DIRECTORY_SEPARATOR!=='\\' && ((fileperms($dir)&0077)!==0)) throw new \RuntimeException('Output directory must have private permissions (0700).');
        return $dir;
    }

    public static function clientOptions(array $values): string
    {
        $text="[client]\n";
        foreach (['host','user','password'] as $key) {
            $value=$values[$key]??null;
            if (!is_string($value) || preg_match('/[\r\n\x00]/',$value)) throw new \InvalidArgumentException('Invalid connection option.');
            $text.=$key.'="'.str_replace(['\\','"'],['\\\\','\\"'],$value)."\"\n";
        }
        return $text;
    }
}
