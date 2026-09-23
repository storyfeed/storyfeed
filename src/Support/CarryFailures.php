<?php

namespace Storyfeed\Support;

use Illuminate\Support\Str;
use Storyfeed\Models\Meta;
use Throwable;

/**
 * Actions that take the request and threw when a job was dispatched, kept
 * so `storyfeed:doctor` can name them. Nothing else would: the dispatch went
 * ahead, and the job's publish took the actor it would otherwise have had.
 *
 * Bounded as IgnoredParties is: at most LIMIT actions, each once, and the
 * manager writes an action at most once per process. Recording never fails
 * the dispatch that threw.
 *
 * @internal
 */
class CarryFailures
{
    public const LIMIT = 20;

    private const PREFIX = 'actions:carry_failed:';

    public static function record(string $uses, Throwable $e): void
    {
        $key = self::PREFIX.Str::limit($uses, 200, '');
        $model = config('storyfeed.models.meta', Meta::class);

        try {
            if ($model::query()->where('key', $key)->exists()
                || $model::query()->where('key', 'like', self::PREFIX.'%')->count() >= self::LIMIT) {
                return;
            }

            $model::query()->create(['key' => $key, 'value' => Str::limit($uses.' threw '.$e::class.': '.$e->getMessage(), 250)]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return array<string, string> `Class@method` => what it threw */
    public static function all(): array
    {
        $model = config('storyfeed.models.meta', Meta::class);

        try {
            return $model::query()->where('key', 'like', self::PREFIX.'%')->orderBy('id')->pluck('value', 'key')
                ->mapWithKeys(fn (string $value, string $key) => [Str::after($key, self::PREFIX) => $value])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
