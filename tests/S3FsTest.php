<?php

declare(strict_types=1);

namespace viesrood\storagekit\tests;

use Aws\CommandInterface;
use Aws\Middleware;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use viesrood\storagekit\fs\PrivateVisibility;
use viesrood\storagekit\fs\S3Fs;
use viesrood\storagekit\TemporaryUrls;

/**
 * The decisions that make this filesystem work on providers that are not AWS,
 * tested against a client that records what it is asked to send.
 */
final class S3FsTest extends TestCase
{
    /** @var list<CommandInterface> */
    private array $commands = [];

    public function testItIsAlwaysPrivate(): void
    {
        $fs = new S3Fs(['hasUrls' => true, 'url' => 'https://cdn.example.test']);

        self::assertFalse($fs->hasUrls);
        self::assertNull($fs->getRootUrl());
        self::assertFalse($fs->getShowHasUrlSetting());
        self::assertFalse($fs->getShowUrlSetting());
    }

    public function testTheClientUsesTheEndpointAndTheOldChecksumBehaviour(): void
    {
        $config = S3Fs::clientConfig('https://account.r2.example.test', '', 'key', 'secret', false);

        self::assertSame('https://account.r2.example.test', $config['endpoint']);
        self::assertSame('auto', $config['region'], 'an empty region falls back to auto');
        self::assertSame('when_required', $config['request_checksum_calculation']);
        self::assertSame('when_required', $config['response_checksum_validation']);
        self::assertFalse($config['use_aws_shared_config_files']);
        self::assertFalse($config['use_path_style_endpoint']);
        self::assertTrue(S3Fs::clientConfig('https://x.test', 'eu', 'k', 's', true)['use_path_style_endpoint']);
    }

    public function testAWriteSendsNoEncryptionHeaderAndGoesUnderTheSubfolder(): void
    {
        $fs = $this->fs(['subfolder' => '/brand/files/'], [new Result()]);

        $fs->write('logo.svg', '<svg/>');

        self::assertCount(1, $this->commands);
        $command = $this->commands[0];
        self::assertSame('PutObject', $command->getName());
        self::assertSame('bucket', $command['Bucket']);
        self::assertSame('brand/files/logo.svg', $command['Key']);
        self::assertArrayNotHasKey('ServerSideEncryption', $command->toArray());
        self::assertSame('private', $command['ACL']);
    }

    public function testCopyAndMoveNeverAskForAnAcl(): void
    {
        // A copy is a HeadObject plus a CopyObject; a move adds a DeleteObject.
        $head = new Result(['ContentLength' => 3]);
        $fs = $this->fs([], [$head, new Result(), $head, new Result(), new Result()]);

        $fs->copyFile('a.txt', 'b.txt', []);
        $fs->renameFile('b.txt', 'c.txt', []);

        $names = array_map(static fn(CommandInterface $command): string => $command->getName(), $this->commands);

        self::assertNotContains('GetObjectAcl', $names);
        self::assertSame(2, count(array_keys($names, 'CopyObject', true)));
        self::assertContains('DeleteObject', $names);

        foreach ($this->commands as $command) {
            if ($command->getName() === 'CopyObject') {
                self::assertArrayNotHasKey('ServerSideEncryption', $command->toArray());
            }
        }
    }

    public function testASignedUrlCarriesTheKeyTheExpiryAndTheDownloadName(): void
    {
        // An empty result queue: a request that reached the network would throw.
        $fs = $this->fs(['subfolder' => 'brand'], []);

        $url = $fs->temporaryUrl('docs/Brand guide é.pdf', new DateTimeImmutable('+60 seconds'), [
            'downloadName' => 'Brand guide é.pdf',
            'contentType' => 'application/pdf',
        ]);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        self::assertStringContainsString('/brand/docs/Brand%20guide%20%C3%A9.pdf', $url);
        self::assertSame('60', $query['X-Amz-Expires']);
        self::assertArrayHasKey('X-Amz-Signature', $query);
        self::assertSame('application/pdf', $query['response-content-type']);
        self::assertSame(
            'attachment; filename="Brand guide __.pdf"; filename*=UTF-8\'\'Brand%20guide%20%C3%A9.pdf',
            $query['response-content-disposition'],
        );
    }

    public function testASignedUrlCanAskForInlineDelivery(): void
    {
        $url = $this->fs([], [])->temporaryUrl('a.png', new DateTimeImmutable('+10 seconds'), ['inlineName' => 'a.png']);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        self::assertStringStartsWith('inline; ', $query['response-content-disposition']);
    }

    public function testASignedUrlMustExpireWithinTheAllowedWindow(): void
    {
        $fs = $this->fs([], []);

        foreach (['-1 second', '+' . (TemporaryUrls::MAX_LIFETIME + 60) . ' seconds'] as $when) {
            try {
                $fs->temporaryUrl('a.txt', new DateTimeImmutable($when));
                self::fail("An expiry of $when should have been refused.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAFileNameCannotBreakOutOfTheHeader(): void
    {
        $value = S3Fs::contentDisposition('attachment', "evil\"\r\nX-Injected: 1/../name%.txt");

        self::assertStringNotContainsString("\r", $value);
        self::assertStringNotContainsString("\n", $value);
        self::assertSame(2, substr_count($value, '"'), 'only the two quotes around the ASCII name remain');
        self::assertStringStartsWith('attachment; filename="evil_X-Injected: 1..name_.txt"; ', $value);
    }

    public function testVisibilityIsPrivateInBothDirections(): void
    {
        $visibility = new PrivateVisibility();

        self::assertSame('private', $visibility->visibilityToAcl('public'));
        self::assertSame('private', $visibility->aclToVisibility([['Permission' => 'READ']]));
        self::assertSame('private', $visibility->defaultForDirectories());
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<int, mixed> $results the queued responses, in order
     */
    private function fs(array $settings, array $results): S3Fs
    {
        $this->commands = [];
        $handler = new MockHandler($results);

        $config = S3Fs::clientConfig('https://account.r2.example.test', 'auto', 'key', 'secret', false);
        $config['handler'] = $handler;

        $client = new S3Client($config);
        $client->getHandlerList()->appendInit(Middleware::tap(function(CommandInterface $command): void {
            $this->commands[] = $command;
        }));

        $fs = new S3Fs(array_merge(['bucket' => 'bucket'], $settings));
        $fs->setClient($client);

        return $fs;
    }
}
