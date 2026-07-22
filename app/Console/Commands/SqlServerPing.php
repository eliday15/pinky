<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Smoke test for the external SQL Server connections (compaq + basemaquila).
 *
 * These run over the Cloudflare tunnels started in docker/start.sh and are used
 * to derive payroll bonuses from the Contpaqi / production databases. Run
 * `php artisan sqlserver:ping` inside the container after a deploy to confirm
 * both tunnels and the FreeTDS/dblib driver work end to end.
 */
class SqlServerPing extends Command
{
    protected $signature = 'sqlserver:ping';

    protected $description = 'Verifica la conexión a los SQL Server externos (compaq y basemaquila) vía dblib/cloudflared';

    public function handle(): int
    {
        $ok = true;

        foreach (['compaq', 'basemaquila'] as $connection) {
            try {
                $row = DB::connection($connection)->selectOne('SELECT @@VERSION AS v');
                $version = trim(explode("\n", (string) $row->v)[0]);
                $tables = DB::connection($connection)->selectOne('SELECT COUNT(*) AS n FROM sys.tables');
                $this->info("[{$connection}] OK — {$version} — {$tables->n} tablas");
            } catch (\Throwable $e) {
                $ok = false;
                $this->error("[{$connection}] FALLÓ — " . $e->getMessage());
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
