<?php

namespace Storyfeed\Stories;

use BackedEnum;
use Closure;
use DateInterval;
use Illuminate\Database\Eloquent\Model;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Contracts\PublishesToFeed;
use Storyfeed\Exceptions\UnknownStory;
use Storyfeed\Grouping\Group;
use Storyfeed\PendingActivity;
use Storyfeed\StoryfeedManager;

/**
 * One activity type as a message, the way a Notification is one: constructed
 * with its data, then published.
 *
 *   class DocumentWasUploaded extends Story
 *   {
 *       public string|array|null $objectType = Document::class;
 *       public string|FeedVerb|BackedEnum|null $verb = ActivityVerb::Upload;
 *
 *       public function __construct(public Document $document, public User $user) {}
 *
 *       public function toFeedActivity(): ?PendingActivity
 *       {
 *           return $this->activity($this->document)->by($this->user)->in($this->document->folder);
 *       }
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
 *       public function intent(): ?string
 *       {
 *           return 'success';   // optional; the app's own word
 *       }
 *
 *       public function groups(): array
 *       {
 *           return [
 *               Group::repeat()->headline(':actor uploaded :count files to :target'),
 *           ];
 *       }
 *   }
 *
 *   // routes/feed.php
 *   Story::for(Document::class)->verb('upload', DocumentWasUploaded::class);
 *
 *   // the call site
 *   Storyfeed::publish(new DocumentWasUploaded($document, $user));
 *
 * `toFeedActivity()` is the class's `toMail()`: what this one publish says.
 * Null publishes nothing. Everything else is presentation, the same for every
 * activity of the type. `$objectType` and `$verb` are optional, since the
 * line names them, and must agree with it; the enum case carries the verb's
 * AS2.0 type.
 *
 * PRESENTATION NEVER READS THE CONSTRUCTOR'S STATE. The presentation methods
 * (headline(), icon(), intent(), groups(), missing(), keepFor(),
 * keepForever(), keepLatest()) compile into the registries at boot, from an instance made
 * WITHOUT calling the constructor: there is no order at boot to pass it. A
 * headline that reads `$this->order` fails the compile, naming the class.
 * Per-publish wording belongs in toFeedActivity(), as data.
 *
 * A group headline here is about documents, so it is filed under documents
 * and cannot collide with another class's headline for the same verb. A
 * grouping whose rows can hold other kinds of thing (Group::byActors(),
 * Group::byTargets()) has no one type, so its headline belongs to the verb
 * in routes/feed.php, worded so it names no type; declared here, it fails at
 * compile.
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
 * BOUND IN ROUTES/FEED.PHP, the one place stories register, as a route
 * binds an invokable controller. The line names the verb, and inside
 * `Story::for()` the types:
 *
 *   Story::for(Document::class)->verb('upload', DocumentWasUploaded::class);
 *
 * The class may declare `$verb` and `$objectType` as well; they must then
 * agree with the line. Outside a `Story::for()`, the class's `$objectType`
 * gives the types.
 *
 * NOTHING IS INFERRED AT RUNTIME. The class name is documentation and
 * generator input, never behaviour: `make:story` writes the binding line,
 * where a wrong guess shows up in the diff. (The miss rate is real: in one
 * app, 9 of 10 command-style class names matched their verb, and
 * `PostComment` published `comment`, not `post`.)
 */
abstract class Story implements PublishesToFeed
{
    /**
     * REQUIRED when the line binding the class is outside a `Story::for()`,
     * which would otherwise give the types. A model
     * class (recommended — a rename is then IDE-checked), a morph alias, an
     * array of either, or '*' for object-less activities such as composite
     * parents.
     *
     * Never inferred: token-guessing on class names died on multi-word objects.
     *
     * @var string|array<int, string>|null
     */
    public string|array|null $objectType = null;

    /**
     * Optional: the line in routes/feed.php names the verb, and one declared
     * here must agree with it. A verb string, or a FeedVerb enum case, which
     * also carries its AS2.0 mapping.
     */
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
     * The glyph's intent: an optional, app-owned word (`'success'`,
     * `'danger'`, `'warm'` — whatever the renderer's palette speaks) emitted
     * beside the token as `glyph_intent`. Null, the default, emits null; core
     * neither ships a vocabulary nor validates one. Resolves on the same
     * ladder as icon() but independently of it, so a `'*.finalize'` story
     * can carry the intent for every finalize while each type keeps its own
     * token.
     */
    public function intent(): ?string
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

    /**
     * The roles this activity is about, which make it redundant once one of
     * them is a tombstone — see Verb::missing(). Null, the
     * default, keeps the default set (the object; none for a removal verb);
     * an empty list means no role.
     *
     * @return list<string>|null
     */
    public function missing(): ?array
    {
        return null;
    }

    /**
     * How long these activities are worth keeping — see Verb::keepFor().
     * Null, the default, leaves them to `storyfeed.prune.after_days`.
     */
    public function keepFor(): string|DateInterval|null
    {
        return null;
    }

    /** Never prune these activities — see Verb::keepForever(). */
    public function keepForever(): bool
    {
        return false;
    }

    /**
     * Keep only the latest of these activities per object — see
     * Verb::keepLatest(). Null, the default, keeps every row; `true` is the
     * plain form; an array is its named arguments:
     * `['per' => ['object', 'actor'], 'within' => '10 minutes']`.
     *
     * @return true|array{per?: list<string>|string|null, within?: string|DateInterval|null}|null
     */
    public function keepLatest(): array|true|null
    {
        return null;
    }

    /**
     * Middleware this activity goes through when it is published, after the
     * `default` group, as a job's `middleware()` wraps the job — see
     * Verb::middleware(). Read when stories compile, like the presentation
     * methods, so it must not read constructor state. Strings
     * (`'batch:5 minutes'`) and closures.
     *
     * @return list<string|Closure>
     */
    public function middleware(): array
    {
        return [];
    }

    /**
     * The activity to publish, or null to publish nothing: the same hook as
     * an event's {@see PublishesToFeed::toFeedActivity()}, so a message class
     * and an event are published by one method. Start it with
     * {@see activity()}, which knows this class's verb.
     */
    abstract public function toFeedActivity(): ?PendingActivity;

    /**
     * Begin this class's activity, about this object: the verb is the one
     * this class declares or routes/feed.php binds it to.
     *
     *   public function toFeedActivity(): ?PendingActivity
     *   {
     *       return $this->activity($this->order)->by($this->user);
     *   }
     *
     * `Storyfeed::activity($verb, $object)` with the verb filled in, as a
     * controller's `$this->authorize()` is `Gate::authorize()`. Protected: call
     * sites construct the class and hand it to `Storyfeed::publish()`.
     */
    protected function activity(Model|string|null $object = null): PendingActivity
    {
        // A string object is a party's name, so a Story class here would
        // publish an activity about a party called "App\Stories\…".
        if (is_string($object) && is_a($object, Story::class, true)) {
            throw UnknownStory::classGivenAsObject(static::class, $object);
        }

        return PendingActivity::make(app(StoryfeedManager::class)->storyVerb(static::class), $object);
    }
}
