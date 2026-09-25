<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\TombstoneRules;

/**
 * A recorded verb that reads like a removal ("cancel", "archive", "void")
 * but that the tombstone rules treat as being about its object.
 *
 * By default an activity is about its object, so once the object is deleted
 * the activity is redundant: "An order Dana placed was later removed". For a
 * removal verb that's wrong: "Steve cancelled Order #1042" is still the news
 * after the order is gone, and a renderer told it's redundant would retell
 * it as "a cancelled order that was later removed". A verb is recognised as
 * a removal by its AS2 type (Delete, Remove, Undo, Reject), or declared one
 * with `->missing()`. This names the verbs that are neither.
 *
 * INFO, because the word is a guess: "archive" can mean the object moved,
 * and a verb the app means as "about the object" is declared that way with
 * `->missing('object')`, which silences this.
 */
class RemovalVerbs extends Check
{
    /** Word stems that read like a removal, at the start of a verb or one of its words. */
    public const STEMS = [
        'archiv', 'cancel', 'delet', 'destroy', 'discard', 'dismiss', 'eras', 'purg',
        'remov', 'retract', 'revok', 'trash', 'unpublish', 'void', 'withdr',
    ];

    public function name(): string
    {
        return 'removals';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('activities')) {
            return;
        }

        $rules = app(TombstoneRules::class);
        $declared = $this->declared($storyfeed);

        /** @var array<string, list<string>> $unclassified verb => the types it's recorded on */
        $unclassified = [];

        // Tombstoned rows under their former type, which the tombstone rules
        // are asked about on the read path.
        $query = $this->activities()->withTrashed();
        $objectType = $this->objectTypeOf($query);

        foreach ($query->toBase()->selectRaw("{$objectType} as object_type, verb")->distinct()->get() as $row) {
            $verb = (string) $row->verb;
            $type = $row->object_type === null ? null : (string) $row->object_type;

            if (! self::looksLikeRemoval($verb)
                || ! in_array('object', $rules->constitutiveRoles($type, $verb), true)
                || isset($declared["{$type}.{$verb}"])
                || isset($declared["*.{$verb}"])) {
                continue;
            }

            $unclassified[$verb][] = $type ?? '*';
        }

        ksort($unclassified);

        foreach ($unclassified as $verb => $types) {
            sort($types);

            yield Finding::info(
                'removals.unclassified',
                "Verb `{$verb}` (recorded on ".implode(', ', $types).') reads like a removal, but once its object '
                .'is deleted the activity is treated as redundant ("an order that was later removed"), as for any '
                .'verb about its object. If it records the removal itself, map it to an AS2 Delete, Remove, Undo '
                ."or Reject type, or declare `Story::verb('{$verb}')->missing()`. If it is about its object, "
                ."declare `->missing('object')` to say so.",
                ['verb' => $verb, 'types' => implode(', ', $types)],
            );
        }
    }

    public static function looksLikeRemoval(string $verb): bool
    {
        $stems = implode('|', self::STEMS);

        return preg_match("/(^|[^a-z])({$stems})/i", $verb) === 1;
    }

    /**
     * The `type.verb` and `*.verb` keys a `->missing()` declared: a decision
     * about this verb, whatever it was. A type-wide or global fallback isn't.
     *
     * @return array<string, true>
     */
    protected function declared(StoryfeedManager $storyfeed): array
    {
        try {
            $missing = $storyfeed->compiledStories()['missing'];
        } catch (StoryMisconfigured) {
            return [];
        }

        return array_fill_keys(array_keys($missing), true);
    }
}
