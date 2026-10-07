<?php

declare(strict_types=1);

namespace viesrood\storagekit\fs;

use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\flysystem\base\FlysystemFs;
use craft\helpers\App;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Visibility;
use viesrood\storagekit\TemporaryUrls;

/**
 * A private filesystem on any S3-compatible object store.
 *
 * What sets it apart from the AWS S3 filesystem:
 *
 * - the endpoint is a setting, so Cloudflare R2, Backblaze B2, Wasabi and
 *   self-hosted servers work, and moving to another provider is a matter of
 *   changing an endpoint and a key;
 * - it never has public URLs. Every object is private, and reading one goes
 *   through Craft or through a signed URL the site hands out itself;
 * - it sends no ACL lookups, no server-side-encryption header and no STS
 *   call, none of which these providers implement.
 *
 * Every setting accepts an environment variable, so project config stores
 * variable names and never a secret.
 */
class S3Fs extends FlysystemFs implements TemporaryUrls
{
    /** The full endpoint, e.g. `https://<account>.r2.cloudflarestorage.com`. */
    public string $endpoint = '';

    /** The region. R2 uses `auto`; most other providers name a real region. */
    public string $region = 'auto';

    public string $bucket = '';

    public string $keyId = '';

    public string $secret = '';

    /** An optional folder inside the bucket that acts as the filesystem root. */
    public string $subfolder = '';

    /** Address the bucket as a path instead of a subdomain. Self-hosted servers usually need this. */
    public bool $pathStyle = false;

    protected bool $foldersHaveTrailingSlashes = true;

    private ?S3ClientInterface $_client = null;

    public static function displayName(): string
    {
        return 'S3-compatible storage (StorageKit)';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        // Whatever a stored config says: this filesystem is private.
        unset($config['hasUrls'], $config['url']);

        parent::__construct($config);
    }

    public function init(): void
    {
        parent::init();

        $this->hasUrls = false;
        $this->url = null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defineBehaviors(): array
    {
        $behaviors = parent::defineBehaviors();
        $behaviors['parser'] = [
            'class' => EnvAttributeParserBehavior::class,
            'attributes' => ['endpoint', 'region', 'bucket', 'keyId', 'secret', 'subfolder'],
        ];

        return $behaviors;
    }

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['endpoint', 'region', 'bucket', 'keyId', 'secret'], 'required'];
        $rules[] = [['endpoint', 'region', 'bucket', 'keyId', 'secret', 'subfolder'], 'string'];
        $rules[] = [['pathStyle'], 'boolean'];

        return $rules;
    }

    /** There is no "public URLs" switch to show: the answer is always no. */
    public function getShowHasUrlSetting(): bool
    {
        return false;
    }

    public function getShowUrlSetting(): bool
    {
        return false;
    }

    public function getRootUrl(): ?string
    {
        return null;
    }

    public function getSettingsHtml(): ?string
    {
        /** @var \craft\web\View $view */
        $view = Craft::$app->getView();

        return $view->renderTemplate('storagekit/fsSettings', [
            'fs' => $this,
        ]);
    }

    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $options = []): string
    {
        $lifetime = $expiresAt->getTimestamp() - (new DateTimeImmutable())->getTimestamp();

        if ($lifetime < 1 || $lifetime > self::MAX_LIFETIME) {
            throw new InvalidArgumentException(sprintf(
                'A signed URL must expire between 1 and %d seconds from now; %d given.',
                self::MAX_LIFETIME,
                $lifetime,
            ));
        }

        $arguments = [
            'Bucket' => $this->parsed('bucket'),
            'Key' => $this->prefix() . ltrim($path, '/'),
        ];

        if (isset($options['downloadName'])) {
            $arguments['ResponseContentDisposition'] = self::contentDisposition('attachment', $options['downloadName']);
        } elseif (isset($options['inlineName'])) {
            $arguments['ResponseContentDisposition'] = self::contentDisposition('inline', $options['inlineName']);
        }

        if (isset($options['contentType'])) {
            $arguments['ResponseContentType'] = $options['contentType'];
        }

        $client = $this->client();
        $command = $client->getCommand('GetObject', $arguments);

        return (string)$client->createPresignedRequest($command, $expiresAt)->getUri();
    }

    /**
     * The client configuration for a set of resolved settings.
     *
     * Public and static so the decisions in it can be tested without a
     * network and without Craft.
     *
     * @return array<string, mixed>
     */
    public static function clientConfig(
        string $endpoint,
        string $region,
        string $keyId,
        string $secret,
        bool $pathStyle,
    ): array {
        return [
            'version' => 'latest',
            'region' => $region !== '' ? $region : 'auto',
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => $pathStyle,
            'credentials' => new Credentials($keyId, $secret),
            // Newer SDKs add a CRC32 checksum to every upload and expect one on
            // every download. Not every S3-compatible provider handles those
            // headers on every operation; "when required" is the old behaviour.
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            // Never fall back to the machine's AWS profile or instance role.
            'use_aws_shared_config_files' => false,
        ];
    }

    /**
     * A Content-Disposition value with a safe ASCII name and the real name
     * alongside it (RFC 6266).
     */
    public static function contentDisposition(string $type, string $filename): string
    {
        $filename = str_replace(["\r", "\n", '/', '\\'], '', $filename);
        $ascii = preg_replace('/[^\x20-\x7e]/', '_', $filename) ?? 'download';
        $ascii = str_replace(['"', '%'], '_', $ascii);

        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $type, $ascii, rawurlencode($filename));
    }

    /** Lets a test, or a site with special needs, supply its own client. */
    public function setClient(?S3ClientInterface $client): void
    {
        $this->_client = $client;
    }

    protected function client(): S3ClientInterface
    {
        if ($this->_client === null) {
            $this->_client = new S3Client(self::clientConfig(
                $this->parsed('endpoint'),
                $this->parsed('region'),
                $this->parsed('keyId'),
                $this->parsed('secret'),
                $this->pathStyle,
            ));
        }

        return $this->_client;
    }

    protected function createAdapter(): FilesystemAdapter
    {
        return new AwsS3V3Adapter(
            $this->client(),
            $this->parsed('bucket'),
            $this->prefix(),
            new PrivateVisibility(),
            null,
            [],
            false,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function filesystem(array $config = []): FlysystemFilesystem
    {
        // Without this the adapter asks for the source object's ACL before
        // every copy and move, and these providers have no ACLs to return.
        $config['retain_visibility'] = false;

        return parent::filesystem($config);
    }

    protected function visibility(): string
    {
        return Visibility::PRIVATE;
    }

    /** There is no CDN in front of a private bucket. */
    protected function invalidateCdnPath(string $path): bool
    {
        return true;
    }

    /** The subfolder as a key prefix: empty, or ending in a slash. */
    private function prefix(): string
    {
        $subfolder = trim($this->parsed('subfolder'), '/');

        return $subfolder === '' ? '' : $subfolder . '/';
    }

    private function parsed(string $attribute): string
    {
        return (string)App::parseEnv((string)$this->$attribute);
    }
}
