<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportV1 extends Command
{
    protected $signature = 'assessment:import-v1 {--database= : Name of the first-version database (default LEGACY_DB_DATABASE)} {--fresh : Empty the new tables first}';

    protected $description = 'Copy staff, participants, answers, audio records and audit history from the first (plain PHP) version.';

    /** Parent tables first, because of the foreign key on assessments. */
    private const TABLES = ['staff', 'audio_files', 'participants', 'assessments', 'assessment_history', 'operational_records', 'allocation_sequences', 'audit'];

    public function handle(): int
    {
        $old = $this->option('database') ?: env('LEGACY_DB_DATABASE', 'youmind_assessment');
        $new = DB::connection()->getDatabaseName();
        if ($old === $new) {
            $this->error('The source and target databases are the same.');

            return self::FAILURE;
        }
        if (! DB::selectOne('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$old])) {
            $this->error("Source database '$old' was not found.");

            return self::FAILURE;
        }
        $existing = DB::table('participants')->count() + DB::table('staff')->count();
        if ($existing > 0 && ! $this->option('fresh')) {
            $this->error('The new database already has data. Run again with --fresh to replace it.');

            return self::FAILURE;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        if ($this->option('fresh')) {
            foreach (array_reverse(self::TABLES) as $table) {
                DB::statement("TRUNCATE TABLE `$table`");
            }
        }
        foreach (self::TABLES as $table) {
            DB::statement("INSERT INTO `$new`.`$table` SELECT * FROM `$old`.`$table`");
            $this->line(sprintf('%-22s %d rows', $table, DB::table($table)->count()));
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->info('Import finished. Existing participant links and passwords keep working.');

        return self::SUCCESS;
    }
}
