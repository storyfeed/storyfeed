<?php

namespace Storyfeed\Support;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Storyfeed\Body\Prose;
use Storyfeed\Contracts\FeedBody;

/**
 * Turns whatever an app hands a `body:` slot into the list a payload carries.
 *
 * ONE SHAPE REACHES A RENDERER. A body is always a list of form arrays, even
 * when the app passed one form or a bare string, so nothing downstream ever
 * branches on "did I get a string, an object, or an array of them". A
 * renderer that had to answer that question would answer it differently in
 * Blade and in Vue, and the same payload would draw two ways.
 *
 * A BARE STRING IS `Prose`, not a loose value on the node. It is the only
 * coercion here and it earns its place: the alternative is a body that is
 * sometimes text and sometimes a list, which is the branch above, permanently.
 *
 * CORE STILL READS NOTHING. A form is asked for its own array and that array
 * is carried byte-identical; nothing here consults a name, a version, or a
 * key inside it.
 */
final class BodySlot
{
    /**
     * @param  string|FeedBody|iterable<mixed>|Closure|null  $body
     * @return list<array<string, mixed>>
     */
    public static function normalize(string|FeedBody|iterable|Closure|null $body): array
    {
        if ($body instanceof Closure) {
            $body = $body();
        }

        if ($body === null || $body === '') {
            return [];
        }

        if (is_string($body)) {
            return [Prose::make($body)->toPayload()];
        }

        if ($body instanceof FeedBody) {
            return [$body->toPayload()];
        }

        $forms = [];

        foreach ($body as $form) {
            if ($form instanceof FeedBody) {
                $forms[] = $form->toPayload();

                continue;
            }

            if (is_string($form) && $form !== '') {
                $forms[] = Prose::make($form)->toPayload();

                continue;
            }

            // A stored form handed back in, or an app's own array in the same
            // shape. Passed through rather than validated: core does not know
            // what a form's keys mean and is not going to start here.
            if (is_array($form) && isset($form[FeedBody::KEY])) {
                $forms[] = $form;
            }
        }

        return $forms;
    }

    /**
     * The app's own map, with a NESTED `Arrayable` flattened too.
     *
     * `$data` itself has always been flattened; a form sitting inside it was
     * not, so `['diff' => Change::make(…)]` stored `{}` and the fix was to
     * remember `->toArray()`. That trap produced a docs example teaching the
     * workaround rather than the mistake. One level is enough — a form never
     * nests, and walking further would be core reading the app's map.
     *
     * @param  array<string, mixed>|Arrayable<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function data(array|Arrayable $data): array
    {
        $map = $data instanceof Arrayable ? $data->toArray() : $data;

        return array_map(
            static fn (mixed $value): mixed => $value instanceof Arrayable ? $value->toArray() : $value,
            $map,
        );
    }
}
