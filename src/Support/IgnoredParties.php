<?php

namespace Storyfeed\Support;

use Illuminate\Support\Str;
use Storyfeed\Models\Meta;
use Throwable;

/**
 * Party names production ignored because `Storyfeed::parties()` didn't
 * declare them, kept so `storyfeed:doctor` can name them. Nothing else
 * would: the activity published with the actor it would otherwise have had,
 * so no row shows the name.
 *
 * Bounded, because the name may come from a request: at most LIMIT names
 * are kept, each once, and the manager writes a name at most once per
 * process. Recording never fails the publish that ignored it.
 *
 * @internal
 */
class IgnoredParties
{
    public const LIMIT = 20;

    private const PREFIX = 'parties:ignored:';

    public static function record(string $name): void
    {
        $key = self::PREFIX.Str::limit(Str::slug($name), 200, '');
        $model = config('storyfeed.models.meta', Meta::class);

        try {
            if ($model::query()->where('key', $key)->exists()
                || $model::query()->where('key', 'like', self::PREFIX.'%')->count() >= self::LIMIT) {
                return;
            }

            $model::query()->create(['key' => $key, 'value' => Str::limit($name, 250)]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return list<string> */
    public static function all(): array
    {
        $model = config('storyfeed.models.meta', Meta::class);

        try {
            return $model::query()->where('key', 'like', self::PREFIX.'%')->orderBy('id')->pluck('value')->all();
        } catch (Throwable) {
            return [];
        }
    }
}
