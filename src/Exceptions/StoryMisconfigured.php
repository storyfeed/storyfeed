<?php

namespace Storyfeed\Exceptions;

use LogicException;

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
            .'(CreatePurchaseOrder — is the object PurchaseOrder, or the verb CreatePurchase?).'
        );
    }

    public static function missingVerb(string $story): self
    {
        return new self(
            "Story [{$story}] must declare \$verb — a string or a FeedVerb enum case. It is not inferred at "
            .'runtime: a Story REGISTERS its own verb, so a wrong guess would self-register and sail past '
            .'verbs.strict. `php artisan make:story` writes the verb into the file instead, where a wrong '
            .'guess is visible in the diff.'
        );
    }

    /** @param array<int, string> $registered */
    public static function unknownAxis(string $story, string $axis, array $registered): self
    {
        return new self(
            "Story [{$story}] declares a group on axis [{$axis}], which is not registered — its aggregate "
            .'grammar would never resolve. Register the axis with Storyfeed::axes([Axis::make(...)]) first. '
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
            "Stories [{$first}] and [{$second}] both author [{$key}]. The array registries are "
            .'last-writer-wins, so this would silently pick one — declaring it an error is the main '
            .'guarantee the Story layer adds. Give them distinct (objectType, verb) pairs.'
        );
    }

    public static function missingParentGrammar(string $story, string $verb): self
    {
        return new self(
            "Story [{$story}] declares a composite group, so its PARENT activity needs singular grammar at "
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

    public static function notAStory(string $given): self
    {
        return new self(
            "[{$given}] is not a Storyfeed\\Story subclass. Storyfeed::stories() takes Story class-strings, "
            .'StoryDefinition objects, or `\'type.verb\' => [...]` arrays.'
        );
    }
}
