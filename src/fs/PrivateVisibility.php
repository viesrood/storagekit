<?php

declare(strict_types=1);

namespace viesrood\storagekit\fs;

use League\Flysystem\AwsS3V3\VisibilityConverter;
use League\Flysystem\Visibility;

/**
 * Everything is private, and nothing is ever looked up.
 *
 * The stock converter translates between Flysystem visibility and S3 ACLs.
 * Providers such as Cloudflare R2 have no object ACLs: access is decided by
 * the bucket and the API token. So there is one answer in each direction.
 */
final class PrivateVisibility implements VisibilityConverter
{
    public function visibilityToAcl(string $visibility): string
    {
        return 'private';
    }

    /**
     * @param array<int, mixed> $grants
     */
    public function aclToVisibility(array $grants): string
    {
        return Visibility::PRIVATE;
    }

    public function defaultForDirectories(): string
    {
        return Visibility::PRIVATE;
    }
}
