<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\BodyMigrations\BodyRegistry;
use Storyfeed\BodyMigrations\RewriteBodies;

final class MigrateBodiesCommand extends Command
{
    protected $signature = 'storyfeed:migrate-bodies {--chunk=500 : Rows per transaction}';

    protected $description = 'Upgrade stored bodies using their body migrations';

    public function handle(RewriteBodies $rewrite, BodyRegistry $registry): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        if ($chunk === false || $chunk < 1) {
            $this->error('The chunk size must be positive.');

            return self::FAILURE;
        }
        $registry->clearFindings();
        $result = $rewrite->run($chunk);
        $this->info("Scanned {$result['scanned']} rows; changed {$result['changed']} rows in {$result['chunks']} chunks.");
        foreach ($registry->findings() as $finding) {
            $this->warn($finding->message);
        }

        return $registry->findings() === [] ? self::SUCCESS : self::FAILURE;
    }
}
