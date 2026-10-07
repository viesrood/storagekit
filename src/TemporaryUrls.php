<?php

declare(strict_types=1);

namespace viesrood\storagekit;

use DateTimeInterface;

/**
 * A filesystem that can hand out a short-lived URL to one private object.
 *
 * Callers type-hint this interface instead of a concrete filesystem class, so
 * the code that authorises a download does not depend on where the file lives.
 */
interface TemporaryUrls
{
    /** The longest lifetime S3-compatible providers accept for a signed URL. */
    public const MAX_LIFETIME = 604800;

    /**
     * Returns a signed GET URL for one object.
     *
     * The URL is a bearer token: anyone holding it can fetch the object until
     * it expires. Create it only after the request has been authorised, keep
     * the lifetime short, and never cache, log or embed it.
     *
     * @param string $path The path within the filesystem, as Craft knows it.
     * @param DateTimeInterface $expiresAt When the URL stops working.
     * @param array{downloadName?: string, inlineName?: string, contentType?: string} $options
     *        `downloadName` asks the provider to send the object as an
     *        attachment with that file name, `inlineName` to send it inline;
     *        `contentType` overrides the stored content type.
     * @throws \InvalidArgumentException if the expiry is in the past or more
     *         than [[MAX_LIFETIME]] seconds away.
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $options = []): string;
}
