<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SetupAssessment extends Command
{
    protected $signature = 'assessment:setup';

    protected $description = 'Create the encryption key for participant links, the audio folder and a first admin (if none exists).';

    public function handle(): int
    {
        $private = storage_path('app/private');
        File::ensureDirectoryExists($private.'/audio');

        if (! File::exists($private.'/key')) {
            File::put($private.'/key', random_bytes(32));
            $this->info('Created the participant-link key (keep it safe: it is needed to read existing links).');
        }

        if (! DB::table('staff')->exists()) {
            $password = bin2hex(random_bytes(9));
            DB::table('staff')->insert(['username' => 'admin', 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'admin']);
            File::put($private.'/LOCAL-LOGIN.txt', "Username: admin\nPassword: $password\n");
            $this->info('Created the first admin. Credentials are in storage/app/private/LOCAL-LOGIN.txt');
        }

        $this->info('Setup complete.');

        return self::SUCCESS;
    }
}
