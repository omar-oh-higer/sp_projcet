<?php

namespace App\Services\StressTesting;

use Illuminate\Support\Facades\Process;

/** Spawns stress:concurrent detached from php artisan serve (Windows-safe). */
class StressDemoProcessLauncher
{
    /** @param list<string> $stressArguments Flags after `stress:concurrent`. */
    public function start(array $stressArguments): void
    {
        $command = [
            PHP_BINARY,
            base_path('artisan'),
            'stress:concurrent',
            ...$stressArguments,
        ];

        if (PHP_OS_FAMILY === 'Windows') {
            Process::path(base_path())->start([
                'cmd', '/C', 'start', '/B', '',
                ...$command,
            ]);

            return;
        }

        Process::path(base_path())->start($command);
    }
}
