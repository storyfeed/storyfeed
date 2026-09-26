<?php

use Storyfeed\Body\MediaObject;
use Storyfeed\BodyMigrations\Body;
use Storyfeed\BodyMigrations\BodyBlueprint;
use Storyfeed\BodyMigrations\BodyMigration;

return new class extends BodyMigration
{
    public function up(): void
    {
        Body::table(MediaObject::class, function (BodyBlueprint $body): void {
            $body->renameField('attachments', 'files');
            $body->changeField('files', static fn (mixed $files): array => is_array($files) ? $files : []);
        });
    }
};
