<?php

namespace App\Support\Admin;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * One seam between the /admin UI and `php artisan` (Phase 13): every console
 * command the panel runs goes through this class, so tests can swap it for a
 * recording fake instead of actually running `optimize` (which would write a
 * bootstrap/cache/config.php that outlives the test) or `backup:run`.
 *
 * Production behaviour is unchanged: the real Artisan kernel, real output.
 */
class ArtisanRunner
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return array{code: int, output: string}
     */
    public function call(string $command, array $parameters = []): array
    {
        $buffer = new BufferedOutput;
        $code = Artisan::call($command, $parameters, $buffer);

        return ['code' => $code, 'output' => $buffer->fetch()];
    }
}
