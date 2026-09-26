<?php

namespace Storyfeed\Exceptions;

use LogicException;
use Storyfeed\Stories\Story;
use Throwable;

/**
 * Thrown at compile time when a Story cannot produce valid registry entries.
 *
 * Every one of these replaces a SILENT failure the array registries allow: a
 * null headline, a wildcard icon, a lie in an aggregate template, or a
 * last-writer-wins collision nobody noticed. The layer's whole claim is that
 * these become boot failures instead — so the messages have to name the fix,
 * not just the fault.
 */
class StoryMisconfigured extends LogicException
{
    public static function missingObjectType(string $story): self
    {
        return new self(
            "Story [{$story}] must declare \$objectType — a model class (Document::class), a morph alias "
            ."('document'), an array of either, or '*' for object-less activities such as composite parents. "
            .'It is never inferred from the class name: token-guessing died on multi-word objects '
            .'(CreatePurchaseOrder — is the object PurchaseOrder, or the verb CreatePurchase?). Or bind it '
            ."inside the types' scope in routes/feed.php: Story::for(Order::class)->verb('ship', ".class_basename($story).'::class).'
        );
    }

    public static function missingVerb(string $story): self
    {
        return new self(
            "Story [{$story}] has no verb. It is not inferred from the class name: bind the class to its "
            ."verb in routes/feed.php, Story::for(Order::class)->verb('ship', ".class_basename($story).'::class).'
        );
    }

    /** @param array<int, string> $registered */
    public static function unknownAxis(string $story, string $axis, array $registered): self
    {
        return new self(
            "Story [{$story}] declares a group on axis [{$axis}], which is not registered — its group "
            .'headline would never resolve. Register the axis with Storyfeed::axes([Axis::make(...)]) first. '
            .'Registered axes: '.implode(', ', $registered).'.'
        );
    }

    /** @param array<int, string> $allowed */
    public static function unpinnedToken(string $story, string $axis, string $token, array $allowed): self
    {
        return new self(
            "Story [{$story}] uses [{$token}] in its [{$axis}] group headline, but that axis does not pin it — "
            .'groups on this axis may span many values, so the headline can lie. This is the defect that '
            .'rendered "made 5 revisions to Aut Beatae.docx" over five different documents. '
            .'Allowed here: '.implode(' ', $allowed).'.'
        );
    }

    public static function conflictingStories(string $key, string $first, string $second): self
    {
        return new self(
            "[{$key}] is defined twice: {$first} and {$second}. "
            .'The array registries are last-writer-wins, so this would silently pick one — declaring it an error is the main '
            .'guarantee the Story layer adds. Keep one definition, or give them distinct (objectType, verb) pairs.'
        );
    }

    public static function nameSpansTypes(string $source, string $keys, string $name): self
    {
        return new self(
            "->name('{$name}') at {$source} names [{$keys}], more than one key. A story name names one key, "
            .'as a route name names one route: define the verb for each type, and name each.'
        );
    }

    public static function namedFallback(string $source, string $keys, string $name): self
    {
        return new self(
            "->name('{$name}') at {$source} names the fallback [{$keys}], which has no verb to record. Name a verb."
        );
    }

    /**
     * Two declarations share a name. `storyfeed:cache` refuses, as
     * `route:cache` does (Illuminate/Routing/AbstractRouteCollection.php):
     * at runtime the last one would win without a word.
     */
    public static function duplicateName(string $name, string $key, string $source, string $firstKey, string $firstSource): self
    {
        return new self(
            "Unable to prepare story [{$key}] ({$source}) for caching. Another story has already been assigned name [{$name}]: "
            ."[{$firstKey}] ({$firstSource})."
        );
    }

    /**
     * One key given two names. A row's name is looked up from its key, so
     * one of them would never be the name a recorded activity reads as.
     */
    public static function keyNamedTwice(string $key, string $name, string $source, string $firstName, string $firstSource): self
    {
        return new self(
            "Unable to prepare story [{$key}] ({$source}) for caching. It is named [{$name}], and already named "
            ."[{$firstName}] ({$firstSource}). A key has one name, because \$activity->storyName() is looked up from it."
        );
    }

    public static function verbDefinedTwice(string $key, string $first, string $second): self
    {
        return new self(
            "[{$key}] is defined twice: {$first} and {$second}. A verb a Story class defines is defined there whole, "
            .'as a route bound to a controller is, so say everything about it in that one place.'
        );
    }

    public static function wildcardObjectType(string $source): self
    {
        return new self(
            "[{$source}] sets activityStreamsType() without an object type. It describes what a TYPE is, "
            .'so set it in a type scope: Story::for(Delivery::class)->activityStreamsType(ObjectType::Document).'
        );
    }

    public static function unscopedNoun(string $source, string $verb): self
    {
        return new self(
            "[{$source}] sets a noun on the unscoped verb [{$verb}]. A noun describes a kind of thing, so it "
            ."belongs to a type: Story::for(Document::class)->verb('{$verb}')->noun('file|files'), or "
            ."Story::fallback()->noun('item|items') for every type."
        );
    }

    public static function typeScopedFallbackGroup(string $source, string $type): self
    {
        return new self(
            "[{$source}] gives Story::for('{$type}')->fallback() a group headline. A group headline names a verb, "
            ."so it belongs on Story::for('{$type}')->verb('…') or, for every type, Story::fallback()->grouped(…)."
        );
    }

    public static function typeScopedGroup(string $source, string $axis, string $verb, string $type): self
    {
        return new self(
            "[{$source}] gives [{$verb}] a `{$axis}` group headline inside Story::for('{$type}'). The `{$axis}` axis "
            .'can gather several object types into one group, so its headline belongs to the verb, not to one '
            ."type: Story::verb('{$verb}')->grouped(…), outside the group() closure."
        );
    }

    /**
     * A Story class gave a headline to a grouping that can
     * hold more than one type. Said in terms of the sentence and the rows,
     * never of keys: that is what the reader was writing.
     */
    public static function classGroupSpansTypes(string $uses, string $axis, string $verb, string $type): self
    {
        $method = str_replace('@', '::', $uses).'()';
        $group = match ($axis) {
            'actors' => 'Group::byActors()',
            'targets' => 'Group::byTargets()',
            default => "Group::on('{$axis}')",
        };

        return new self(
            "{$method} gives its {$group} grouping a headline. A headline written in a Story class is about that "
            ."class's type, [{$type}], but this grouping can put other kinds of thing in the same row, so the headline "
            ."would describe things that are not all [{$type}]. Remove it here and declare it in routes/feed.php "
            ."instead, worded so it names no type: Story::verb('{$verb}')->grouped({$group}->headline('…'))."
        );
    }

    public static function nestedScope(): self
    {
        return new self(
            'Story::for() was called inside a Story::for()->group() closure. A verb has one object-type '
            .'scope, so scopes do not nest: close the group first, or pass a list — Story::for([Order::class, Refund::class]).'
        );
    }

    public static function missingParentGrammar(string $story, string $verb): self
    {
        return new self(
            "Story [{$story}] declares a composite group, so its PARENT activity needs a single-activity headline at "
            ."['*.{$verb}'] as well — a composite parent has no object of its own, so [{$verb}]'s normal "
            .'type.verb key never resolves for it. Add ->parentHeadline() to the composite group. '
            .'Do NOT reach for `*.*`: a catch-all silently covers every future gap and makes every coverage '
            .'report meaningless.'
        );
    }

    /** @param array<int, string> $allowed */
    public static function unknownDefinitionKey(string $key, string $given, array $allowed): self
    {
        return new self(
            "Ad-hoc story [{$key}] has an unrecognized option [{$given}]. Allowed: "
            .implode(', ', $allowed).'. (A typo'."'".'d key must fail loudly — silently ignoring it is how '
            .'an authored headline goes missing with nothing to show for it.)'
        );
    }

    public static function invalidDefinitionKey(string $key): self
    {
        return new self(
            "Ad-hoc story key [{$key}] is not in the registry's `{type}.{verb}` form — e.g. 'document.upload' "
            ."or '*.upload' for an object-less parent."
        );
    }

    public static function actionReturn(string $uses, ?string $given): self
    {
        $given = $given === null ? 'declares no return type' : "returns [{$given}]";

        if (str_ends_with($uses, '@__invoke')) {
            return new self(
                "[{$uses}] is an invokable story class's action, and it {$given}. It returns "
                .'Storyfeed\Stories\Verb, string (the headline) or array (the array form): '
                .'public function __invoke(Verb $verb): Verb { return $verb->headline(...); }'
            );
        }

        return new self(
            "[{$uses}] is a public method of a resource Story class, so it is an action, and it {$given}. "
            .'An action returns Storyfeed\Stories\Verb, string (the headline) or array (the array form). '
            .'If it is a helper, make it protected or private.'
        );
    }

    public static function actionReturnedAnotherVerb(string $uses): self
    {
        return new self(
            "[{$uses}] returned a Verb it was not given. Configure the Verb the action receives and return it: "
            .'public function place(Verb $verb): Verb { return $verb->headline(...); }'
        );
    }

    public static function actionCollision(string $class, string $first, string $second, string $verb): self
    {
        return new self(
            "[{$class}] has two actions for the verb [{$verb}]: {$first}() and {$second}(). A verb is the method "
            .'name snake-cased, so rename one of them.'
        );
    }

    public static function actionRequestType(string $uses, string $type): self
    {
        return new self(
            "[{$uses}] takes [{$type}]. An action takes Illuminate\Http\Request, and only to choose the actor: "
            .'it also runs when stories compile, where a form request would validate a request that is not there.'
        );
    }

    /** @param list<string> $verbs */
    public static function unknownResourceVerb(string $source, string $verb, array $verbs): self
    {
        return new self(
            "Story::resource() at {$source} has no [{$verb}] verb. It defines ".implode(', ', $verbs)
            .'. Name verbs as they are stored: confirmPayment() is confirm_payment.'
        );
    }

    public static function notAResourceClass(string $source, string $class): self
    {
        return new self("Story::resource() at {$source} binds [{$class}], which is not a class.");
    }

    public static function compiledModelActor(string $source): self
    {
        return new self(
            "[{$source}] gives ->actor() a model when stories compile. A fixed actor is a party name "
            ."(->actor('Stripe')); a model comes from the request, in an action that takes Request."
        );
    }

    public static function requestVaries(string $uses, string $part): self
    {
        return new self(
            "[{$uses}] returned a different {$part} for this request than when stories compiled. An action "
            .'that takes the request may only use it to choose the actor: everything else is read when no '
            .'request exists (the feed, storyfeed:list, the doctor, storyfeed:cache), so it must not depend on one.'
        );
    }

    public static function notAOneVerbStory(string $source, string $class): self
    {
        $hint = class_exists($class)
            ? " A resource Story class is bound with Story::resource(Order::class, {$class}::class)."
            : ' No such class exists.';

        return new self(
            "The verb at {$source} binds [{$class}], which is neither a message class (one that extends "
            .'Storyfeed\Stories\Story) nor an invokable one (one with a public __invoke(Verb $verb) method).'.$hint
        );
    }

    public static function bothStoryShapes(string $source, string $class): self
    {
        return new self(
            "The verb at {$source} binds [{$class}], which is both a message class (it extends "
            .'Storyfeed\Stories\Story) and an invokable one (it has a public __invoke method). Make it one: '
            .'drop __invoke to publish it with Storyfeed::publish(new '.class_basename($class).'(…)), or extend '
            .'nothing to keep __invoke(Verb $verb) and publish with story().'
        );
    }

    public static function boundVerbDiffers(string $source, string $class, string $declared, string $bound): self
    {
        return new self(
            "{$source} binds [{$class}] to the verb [{$bound}], but the class declares [{$declared}]. "
            .'Remove $verb from the class, so the line names it, or make them agree.'
        );
    }

    /**
     * @param  list<string>  $declared
     * @param  list<string>  $bound
     */
    public static function boundTypeDiffers(string $source, string $class, array $declared, array $bound): self
    {
        return new self(
            "{$source} binds [{$class}] for [".implode(', ', $bound).'], but the class declares ['.implode(', ', $declared).']. '
            .'Remove $objectType from the class, so the line names it, or make them agree.'
        );
    }

    /** @param list<string> $verbs */
    public static function storyBoundTwice(string $class, array $verbs): self
    {
        return new self(
            "[{$class}] is bound to the verbs [".implode('] and [', $verbs).']. A message class has one verb, '
            .'which is what its $this->activity() publishes. Give the other verb its own class.'
        );
    }

    /**
     * A presentation method read what only the constructor sets. Stories
     * compile at boot from an instance made without the constructor.
     */
    public static function presentationReadsState(string $class, Throwable $previous): self
    {
        return new self(
            "[{$class}] read constructor state while it compiled: {$previous->getMessage()}. Its presentation "
            .'(headline(), icon(), groups(), keepFor() and the rest) compiles at boot, '
            .'with no constructor call and no data. Say per-publish things in toFeedActivity() instead.',
            previous: $previous,
        );
    }

    public static function unsupportedDataCast(string $verb, string $key, string $cast): self
    {
        return new self(
            "The verb [{$verb}] declares the unsupported cast [{$cast}] for data key [{$key}]. "
            .'Activity data is recorded as plain JSON; encrypted and hashed casts cannot be used.'
        );
    }
}
