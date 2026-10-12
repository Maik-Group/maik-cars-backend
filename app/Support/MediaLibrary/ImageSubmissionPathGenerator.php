<?php

namespace App\Support\MediaLibrary;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

/**
 * Keep submitted images separate from approved product media.
 *
 * The media row's numeric ID identifies the folder; the file name and disk
 * remain owned by Spatie Media Library and are not rewritten in the database.
 */
class ImageSubmissionPathGenerator extends DefaultPathGenerator
{
    protected function getBasePath(Media $media): string
    {
        return 'image-submissions/'.$media->getKey();
    }
}
