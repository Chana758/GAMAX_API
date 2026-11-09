<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class CleanupOrphanImages extends Command
{
    protected $signature = 'images:cleanup-orphans
                            {--hours=24 : Only delete files older than this many hours}
                            {--dry-run : List the files without deleting them}';

    protected $description = 'Delete uploaded images that no product, gallery, banner, category or brand references.';

    /**
     * Storage folder => every [table, column] that stores paths for that folder.
     * A folder is skipped when ANY of its tables does not exist yet, so files can never be
     * deleted just because a migration has not been run.
     */
    private const FOLDERS = [
        'products'   => [['products', 'image'], ['product_images', 'path']],
        'banners'    => [['banners', 'image']],
        'categories' => [['categories', 'icon']],
        'brands'     => [['brands', 'logo']],
    ];

    public function handle(): int
    {
        $disk   = Storage::disk('public');
        $cutoff = now()->subHours((int) $this->option('hours'))->getTimestamp();
        $dryRun = (bool) $this->option('dry-run');
        $count  = 0;

        foreach (self::FOLDERS as $folder => $references) {
            $missing = collect($references)->first(fn ($ref) => ! Schema::hasTable($ref[0]));

            if ($missing) {
                $this->warn("Skipping '{$folder}': table '{$missing[0]}' does not exist.");
                continue;
            }

            // Set of paths currently referenced in the DB, e.g. "/storage/products/abc.png"
            $used = [];
            foreach ($references as [$table, $column]) {
                DB::table($table)->whereNotNull($column)->pluck($column)
                    ->each(function ($path) use (&$used) {
                        $used[$path] = true;
                    });
            }

            foreach ($disk->files($folder) as $file) {
                $publicPath = '/storage/' . $file;

                // Still referenced -> keep
                if (isset($used[$publicPath])) {
                    continue;
                }

                // Recently uploaded -> keep (the admin may still be filling in the form)
                if ($disk->lastModified($file) > $cutoff) {
                    continue;
                }

                if ($dryRun) {
                    $this->line("[dry-run] {$publicPath}");
                } else {
                    $disk->delete($file);
                }

                $count++;
            }
        }

        $this->info(($dryRun ? 'Would delete' : 'Deleted') . " {$count} orphan image(s).");

        return self::SUCCESS;
    }
}
