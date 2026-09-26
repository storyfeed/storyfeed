<?php

namespace Storyfeed\Tests\BodyMigrations\Fixtures;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Storyfeed\Body\KeyValue;
use Storyfeed\Body\MediaObject;
use Storyfeed\BodyMigrations\BodyMigrator;
use Storyfeed\BodyMigrations\BodyRegistry;
use Storyfeed\Models\Party;
use Storyfeed\Models\Snapshot;

final class MatureFeed
{
    public const OPERATIONS = [
        "\$body->renameField('size', 'bytes');",
        "\$body->dropField('legacy');",
        "\$body->addField('caption', default: null);",
        "\$body->changeField('mediaType', static fn (?string \$old): string => strtolower(\$old ?? ''));",
        "\$body->addField('label', default: 'Attachment');",
    ];

    public static function migration(string $path, int $step, ?string $operation = null): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $operation ??= self::OPERATIONS[$step - 1];
        file_put_contents($path.'/2026_09_26_12000'.$step.'_change_'.$step.'.php', <<<PHPFILE
<?php
use Storyfeed\Tests\BodyMigrations\Fixtures\Attachment;
use Storyfeed\BodyMigrations\Body;
use Storyfeed\BodyMigrations\BodyBlueprint;
use Storyfeed\BodyMigrations\BodyMigration;
return new class extends BodyMigration {
    public function up(): void {
        Body::table(Attachment::class, function (BodyBlueprint \$body): void {
            {$operation}
        });
    }
};
PHPFILE);
        app(BodyMigrator::class)->path($path);
    }

    public static function register(int $shape): void
    {
        app(BodyRegistry::class)->register(new Attachment($shape));
    }

    public static function seed(int $count, int $shape = 1, bool $unversioned = false): void
    {
        $attachment = (new Attachment($shape))->toPayload();
        if ($unversioned) {
            unset($attachment['$v']);
        }
        $keyValue = ['$body' => KeyValue::bodyType(), 'missing' => 'Unknown', 'items' => [['key' => 'Status', 'value' => null, 'missing' => 'Pending']]];
        $media = ['$body' => MediaObject::bodyType(), 'attachments' => true, 'subject' => 'Old photograph'];
        $party = Party::make('Sender', key: 'sender-'.Str::ulid(), data: ['details' => [$attachment, $keyValue, $media]]);
        $partySnapshot = Snapshot::query()->where('model_type', $party->getMorphClass())->where('model_id', $party->id)->firstOrFail();
        $snapshot = Snapshot::create([
            'model_type' => 'delivery', 'model_id' => random_int(100000, 999999999), 'label' => 'Parcel',
            'body' => [$attachment, $keyValue, $media],
            'data' => ['details' => ['attachment' => $attachment, 'facts' => $keyValue, 'media' => $media]],
        ]);
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'uid' => (string) Str::ulid(), 'verb' => 'ship', 'object_type' => 'delivery', 'object_id' => $snapshot->model_id,
                'cached_object_id' => $snapshot->id,
                'actor_type' => $party->getMorphClass(), 'actor_id' => $party->id, 'cached_actor_id' => $partySnapshot->id,
                'data' => json_encode(['attachment' => $attachment, 'deep' => ['more' => [$keyValue, $media]]], JSON_THROW_ON_ERROR),
                'published_at' => now()->format('Y-m-d H:i:s.u'), 'created_at' => now()->format('Y-m-d H:i:s.u'), 'updated_at' => now()->format('Y-m-d H:i:s.u'),
            ];
            if (count($rows) === 100) {
                DB::table('feed_activities')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('feed_activities')->insert($rows);
        }
    }

    public static function bodies(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        if (isset($value['$body'])) {
            return [$value];
        }
        $bodies = [];
        foreach ($value as $child) {
            array_push($bodies, ...self::bodies($child));
        }

        return $bodies;
    }
}
