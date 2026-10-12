<?php

namespace App\Console\Commands;

use App\Models\ItemImageSubmission;
use App\Support\MediaLibrary\ImageSubmissionPathGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use Throwable;

class MigrateImageSubmissionStorageCommand extends Command
{
    protected $signature = 'image-submissions:migrate-storage
        {--execute : Actually move files (without this option, only preview the database-derived paths)}
        {--media-id= : Migrate one Spatie media record by ID}';

    protected $description = 'Move submitted images and their conversions into the dedicated image-submissions folder';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $mediaId = $this->option('media-id');

        if ($mediaId !== null && (! ctype_digit((string) $mediaId) || (int) $mediaId < 1)) {
            $this->error('--media-id must be a positive Spatie media ID.');

            return self::FAILURE;
        }

        $query = Media::query()
            ->where('model_type', (new ItemImageSubmission)->getMorphClass())
            ->where('collection_name', 'submitted_image');

        if ($mediaId !== null) {
            $query->whereKey((int) $mediaId);
        }

        $total = (clone $query)->count();
        $this->info(($execute ? 'MOVING' : 'DRY RUN').' image submission files from '.$total.' database media record(s).');

        $legacy = new DefaultPathGenerator;
        $dedicated = new ImageSubmissionPathGenerator;
        $moved = 0;
        $alreadyMoved = 0;
        $failed = 0;

        $query->orderBy('id')->chunkById(100, function ($mediaRows) use (
            $legacy,
            $dedicated,
            $execute,
            &$moved,
            &$alreadyMoved,
            &$failed,
        ): void {
            foreach ($mediaRows as $media) {
                try {
                    $operations = $this->planMoves($media, $legacy, $dedicated);
                    $this->line(sprintf(
                        'Media #%s: %s:%s -> %s:%s (%d file(s))',
                        $media->getKey(),
                        $media->disk,
                        $legacy->getPath($media),
                        $media->disk,
                        $dedicated->getPath($media),
                        count($operations),
                    ));

                    if ($operations === []) {
                        $alreadyMoved++;

                        continue;
                    }

                    if (! $execute) {
                        $moved += count($operations);

                        continue;
                    }

                    // Derived files first. Keep the original available on the old path
                    // until the other files have moved, so an interrupted run is retryable.
                    $original = $legacy->getPath($media).$media->file_name;
                    usort($operations, static fn (array $a, array $b): int => (int) ($a['from'] === $original)
                        <=> (int) ($b['from'] === $original));

                    foreach ($operations as $operation) {
                        $disk = Storage::disk($operation['disk']);

                        if (! $disk->move($operation['from'], $operation['to'])) {
                            throw new RuntimeException('Unable to move '.$operation['disk'].':'.$operation['from']);
                        }

                        $moved++;
                    }
                } catch (Throwable $exception) {
                    $failed++;
                    $this->error('Media #'.$media->getKey().': '.$exception->getMessage());
                }
            }
        });

        $this->newLine();
        $this->line('Media records: '.$total);
        $this->line(($execute ? 'Files moved: ' : 'Files to move: ').$moved);
        $this->line('Already migrated: '.$alreadyMoved);
        $this->line('Failures: '.$failed);

        if (! $execute) {
            $this->comment('Dry run only. Run again with --execute to move the files.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Generate source/destination paths from the media table's ID, filename and
     * disk fields. Moving all files under each ID also retains thumbnail and
     * responsive-image conversion files without guessing their names.
     *
     * @return list<array{disk:string,from:string,to:string}>
     */
    private function planMoves(
        Media $media,
        DefaultPathGenerator $legacy,
        ImageSubmissionPathGenerator $dedicated,
    ): array {
        $sourceOriginal = $legacy->getPath($media).$media->file_name;
        $destinationOriginal = $dedicated->getPath($media).$media->file_name;
        $originalDisk = Storage::disk($media->disk);

        if (! $originalDisk->exists($sourceOriginal) && ! $originalDisk->exists($destinationOriginal)) {
            throw new RuntimeException('Original missing on disk '.$media->disk.': '.$sourceOriginal);
        }

        $folders = [
            [$media->disk, $legacy->getPath($media), $dedicated->getPath($media)],
        ];

        $conversionDisk = $media->conversions_disk ?: $media->disk;

        if ($conversionDisk !== $media->disk) {
            $folders[] = [$conversionDisk, $legacy->getPathForConversions($media), $dedicated->getPathForConversions($media)];
            $folders[] = [$conversionDisk, $legacy->getPathForResponsiveImages($media), $dedicated->getPathForResponsiveImages($media)];
        }

        $operations = [];

        foreach ($folders as [$diskName, $fromFolder, $toFolder]) {
            $disk = Storage::disk($diskName);

            if (! $disk->directoryExists($fromFolder)) {
                continue;
            }

            foreach ($disk->allFiles($fromFolder) as $source) {
                $destination = $toFolder.substr($source, strlen($fromFolder));

                if ($disk->exists($destination)) {
                    throw new RuntimeException('Target already exists; refusing to overwrite '.$diskName.':'.$destination);
                }

                $operations[] = [
                    'disk' => $diskName,
                    'from' => $source,
                    'to' => $destination,
                ];
            }
        }

        return $operations;
    }
}
