<?php

namespace App\Console\Commands;

use App\View\Components\Marketing\Photo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Copies the marketing photographs from Pexels onto this server, so the public
 * pages never make a visitor's browser contact a third party. Run once per
 * server, and again after changing config('marketing.photos').
 */
class FetchMarketingPhotos extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'marketing:fetch-photos {--force : Download again even if a copy exists}';

    /**
     * The console command description.
     */
    protected $description = 'Download the marketing photos so they are served from this server';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        File::ensureDirectoryExists(public_path('images/marketing'));

        $failures = 0;

        foreach (config('marketing.photos') as $name => $photo) {
            if (! isset($photo['id'])) {
                continue;
            }

            foreach (config('marketing.photo_widths') as $width) {
                $target = public_path(Photo::localPath($name, $width));

                if (is_file($target) && ! $this->option('force')) {
                    continue;
                }

                $response = Http::timeout(30)->get(Photo::remoteUrl($photo['id'], $width));

                if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
                    $this->components->error("Could not download {$name} at {$width}px (HTTP {$response->status()}).");
                    $failures++;

                    continue;
                }

                File::put($target, $response->body());
                $this->components->info("Saved {$name} at {$width}px.");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
