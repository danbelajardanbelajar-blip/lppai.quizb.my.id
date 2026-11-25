<?php
declare(strict_types=1);
namespace Lppai\ProgramSeparation;

/** Reuse an already configured shared-hosting user only for the approved test DB. */
final class TestCredentials
{
    public static function password(string $database,string $username,bool $useConfig): string
    {
        if(strcasecmp($database,(string)DB_NAME)===0)throw new \RuntimeException('Production database credentials cannot be used to target production with this tool.');
        if($useConfig){
            if($database!=='quic1934_uji'||$username!==(string)DB_USER)throw new \RuntimeException('Config credential mode requires the approved test database and the same configured username.');
            return (string)DB_PASS;
        }
        if(function_exists('stream_isatty')&&stream_isatty(STDIN))throw new \RuntimeException('Use a concealed password prompt piped through stdin, or explicit config credential mode for the approved test DB.');
        $password=stream_get_contents(STDIN,65537);
        if($password===false||$password===''||strlen($password)>65536)throw new \RuntimeException('Password required on stdin.');
        return $password;
    }
}
