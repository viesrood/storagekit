<?php

declare(strict_types=1);

namespace viesrood\storagekit;

use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fs as FsService;
use viesrood\storagekit\fs\S3Fs;
use yii\base\Event;

/**
 * StorageKit plugin.
 *
 * Adds one filesystem type: private, S3-compatible object storage with a
 * configurable endpoint. That is all it does. It knows nothing about who may
 * read a file; deciding that, and handing out a signed URL or a stream, is the
 * job of the site that uses it.
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();

        Event::on(
            FsService::class,
            FsService::EVENT_REGISTER_FILESYSTEM_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = S3Fs::class;
            },
        );
    }
}
