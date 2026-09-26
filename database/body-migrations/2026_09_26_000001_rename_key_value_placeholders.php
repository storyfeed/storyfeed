<?php

use Storyfeed\Body\KeyValue;
use Storyfeed\BodyMigrations\Body;
use Storyfeed\BodyMigrations\BodyBlueprint;
use Storyfeed\BodyMigrations\BodyMigration;

return new class extends BodyMigration
{
    public function up(): void
    {
        Body::table(KeyValue::class, function (BodyBlueprint $body): void {
            $body->renameField('missing', 'defaultPlaceholder');
            $body->changeField('items', static function (mixed $items): array {
                return array_map(static function (array $row): array {
                    if (! array_key_exists('placeholder', $row) && array_key_exists('missing', $row)) {
                        $row['placeholder'] = $row['missing'];
                    }
                    unset($row['missing']);

                    return $row;
                }, array_filter(is_array($items) ? $items : [], is_array(...)));
            });
        });
    }
};
