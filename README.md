# StorageKit

A private, S3-compatible filesystem for Craft CMS 5.

StorageKit adds one filesystem type. It stores assets in any object store that speaks the
S3 API and lets you set the endpoint yourself: Cloudflare R2, Backblaze B2, Wasabi,
self-hosted servers. It never gives a file a public URL. Reading a file goes through Craft,
or through a short-lived signed URL that your own code hands out after it has decided the
visitor may have it.

## Why not the AWS S3 plugin

Craft's own `craftcms/aws-s3` is built for AWS. It has no endpoint setting, it calls AWS STS
for a session token, and it sends an ACL and a server-side-encryption header with every
upload. S3-compatible providers implement none of that.

StorageKit:

- takes the endpoint as a setting;
- sends no STS call, no encryption header and no ACL lookup;
- keeps the SDK's checksum behaviour at "when required", which is what these providers
  expect;
- is always private.

## Requirements

Craft CMS 5.0 or later, PHP 8.2 or later.

## Installation

```bash
composer require viesrood/storagekit
php craft plugin/install storagekit
```

## Configuration

Create a filesystem under **Settings → Filesystems** and choose **S3-compatible storage
(StorageKit)**. Every setting accepts an environment variable, and you should use one for
each: project config then stores variable names, never a secret.

| Setting | Example | Notes |
|---|---|---|
| Endpoint | `$R2_ENDPOINT` | The full S3 API endpoint, e.g. `https://<account>.r2.cloudflarestorage.com`. |
| Region | `auto` | R2 uses `auto`. Other providers name a region. |
| Bucket | `$R2_BUCKET` | |
| Access key ID | `$R2_ACCESS_KEY_ID` | |
| Secret access key | `$R2_SECRET_ACCESS_KEY` | |
| Subfolder | | Optional root folder inside the bucket. |
| Path-style addressing | off | Turn on for servers that expect `endpoint/bucket`. |

```dotenv
R2_ENDPOINT=https://<account>.r2.cloudflarestorage.com
R2_BUCKET=my-bucket
R2_ACCESS_KEY_ID=...
R2_SECRET_ACCESS_KEY=...
```

Give the API token access to that one bucket only, with object read and write and nothing
else. Leave public access to the bucket switched off.

### Cloudflare R2 with an EU jurisdiction

A bucket created with the EU jurisdiction has its own endpoint:
`https://<account>.eu.r2.cloudflarestorage.com`. Use that as the endpoint; nothing else
changes.

## What a private filesystem means in Craft

`asset.url` is `null`. That is the point, and it has consequences:

- The control panel keeps working. Thumbnails, the image editor, uploads, moves and
  downloads all go through Craft.
- The control panel preview of PDF and video files does not work, because it needs a URL.
- Craft's image transform URLs need a filesystem with public URLs, so `asset.getUrl(transform)`
  returns `null` too. Serve scaled images from your own controller.
- Your templates need a route of their own to show or download a file. That route is where
  you decide who may have it.

## Signed URLs

The filesystem implements `viesrood\storagekit\TemporaryUrls`:

```php
use viesrood\storagekit\TemporaryUrls;

$fs = $asset->getVolume()->getFs();

if ($fs instanceof TemporaryUrls) {
    $url = $fs->temporaryUrl(
        $asset->getVolume()->getSubpath() . $asset->getPath(),
        new DateTimeImmutable('+60 seconds'),
        ['downloadName' => $asset->getFilename()],
    );

    return $this->redirect($url);
}
```

Options: `downloadName` (send as an attachment with this name), `inlineName` (send inline),
`contentType` (override the stored type).

A signed URL is a bearer token. Whoever holds it can fetch the file until it expires, with
no further check. So:

- create one only after you have authorised the request;
- keep the lifetime short (seconds, not hours); the maximum is seven days;
- send it as a redirect with `Cache-Control: no-store`, never in HTML or JSON;
- do not log it.

On Cloudflare R2, signed URLs work on the `r2.cloudflarestorage.com` endpoint only, not on a
custom domain.

## Development

```bash
composer install
composer check   # PHPUnit and PHPStan level 6
```

## License

MIT. See [LICENSE.md](LICENSE.md).
