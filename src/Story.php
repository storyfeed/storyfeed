<?php

namespace Storyfeed;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Grouping\Group;
use Storyfeed\Models\Activity;

/**
 * One class per meaningful activity type — the declarative authoring layer.
 *
 *   class DocumentWasUploaded extends Story
 *   {
 *       public string|array|null $objectType = Document::class;
 *       public string|FeedVerb|BackedEnum|null $verb = ActivityVerb::Upload;
 *
 *       public function headline(): string
 *       {
 *           return ':actor uploaded :object to :target';
 *       }
 *
 *       public function icon(): ?string
 *       {
 *           return 'bi-file-earmark-arrow-up';
 *       }
 *
 *       public function groups(): array
 *       {
 *           return [
 *               Group::byActors()->headline(':actors uploaded :count files to :target'),
 *               Group::repeat()->headline(':actor uploaded :count files to :target'),
 *           ];
 *       }
 *   }
 *
 * WHAT THIS REPLACES. Authoring one activity type against the raw registries
 * touched seven places in a real consumer: the verb enum case, its
 * activityType() arm, the morph map, objectTypes, grammar, icons, then six
 * separate aggregate keys — with the aggregate array ordered by axis, so one
 * verb's headlines sat 40+ lines apart. Everything above is one file.
 *
 * ARCHITECTURE. Stories COMPILE DOWN into the same registries at boot. Three
 * consequences, in order of importance:
 *
 *   1. The payload contract is immune to authoring-layer churn — the payload
 *      emits a resolved headline_template either way. That is what makes it
 *      safe to keep iterating on this layer after the contract froze.
 *   2. The registries stay the documented substrate and the permanent escape
 *      hatch. Neither layer is a bolt-on; one compiles into the other.
 *   3. The read path never changes: resolution hits compiled arrays and never
 *      reflects on a class per row.
 *
 * NOTHING IS INFERRED AT RUNTIME. `$verb` and `$objectType` are both required.
 * The class name is documentation and generator input, never behaviour. This is
 * not fussiness: a Story REGISTERS its own verb, so an inferred-wrong verb
 * would self-register and sail past verbs.strict — inference at boot would
 * REMOVE the typo safety net that exists today. `make:story` writes both values
 * into the generated file, where a wrong guess shows up in the diff. (The miss
 * rate is real: in one app, 9 of 10 command-style class names matched their
 * verb, and `PostComment` published `comment`, not `post`.)
 */
abstract class Story
{
    /**
     * REQUIRED. A model class (recommended — a rename is then IDE-checked), a
     * morph alias, an array of either, or '*' for object-less activities such
     * as composite parents.
     *
     * Never inferred: token-guessing on class names died on multi-word objects.
     *
     * @var string|array<int, string>|null
     */
    public string|array|null $objectType = null;

    /** REQUIRED. A verb string, or a FeedVerb enum case (which also carries its AS2.0 mapping). */
    public string|FeedVerb|BackedEnum|null $verb = null;

    /**
     * Optional AS2.0 override. Normally the FeedVerb enum's job.
     *
     * Typed to allow raw strings, not just the enum: extension types like
     * 'sf:Frobnicate' must round-trip, and dropping unrecognized terms is the
     * recurring data-loss bug in this ecosystem.
     */
    public ActivityType|string|null $type = null;

    /**
     * The singular headline template. A plain string with :actor / :object /
     * :target / :context tokens — NOT a translated string: templates are
     * emitted raw as `headline_template` and interpolated by the renderer, so
     * calling __() here would bake the boot locale into a cacheable value and
     * (since no translation file keys on a token string) silently return the
     * key. i18n belongs in the renderer.
     */
    abstract public function headline(): string;

    public function icon(): ?string
    {
        return null;
    }

    /**
     * Aggregate headlines, one per axis this activity can group on.
     *
     * @return array<int, Group>
     */
    public function groups(): array
    {
        return [];
    }

    /** The compiled verb string. */
    public static function verb(): string
    {
        return StoryDefinition::fromStory(static::class)->verb;
    }

    /**
     * Begin composing this story's activity.
     *
     * Returns PendingActivity, so the whole fluent surface — actor/target/in/
     * to/for/data/when/replace — comes from the one builder. A parallel
     * chainable surface on Story would need its own parity test and would
     * drift; the trait that forwards the builder for verb enums already pays
     * that tax with twelve forwarders.
     */
    public static function activity(Model|string|null $object = null): PendingActivity
    {
        return PendingActivity::make(static::verb(), $object);
    }

    /** A composite: one authored story over a collection of objects. */
    public static function objects(iterable $models): PendingActivity
    {
        return static::activity()->objects($models);
    }

    /**
     * Compose and publish in one call. Spec names only (object/actor/target/
     * context) — the one-liner speaks the spec, the chain speaks English, and
     * named arguments cannot be aliased.
     */
    public static function record(
        Model|string|null $object = null,
        Model|string|null $actor = null,
        Model|string|null $target = null,
        Model|string|null $context = null,
        array $data = [],
        bool $replace = false,
        iterable $objects = [],
    ): Activity {
        return static::activity($object)
            ->when($objects !== [], fn (PendingActivity $a) => $a->objects($objects))
            ->actor($actor)
            ->target($target)
            ->context($context)
            ->when($data !== [], fn (PendingActivity $a) => $a->data($data))
            ->replace($replace)
            ->publish();
    }

    public static function publish(Model|string|null $object = null): Activity
    {
        return static::activity($object)->publish();
    }
}
