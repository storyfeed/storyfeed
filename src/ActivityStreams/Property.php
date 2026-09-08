<?php

namespace Storyfeed\ActivityStreams;

use Storyfeed\ActivityStreams\Concerns\IsVocabularyTerm;

/**
 * Only the AS2 properties our serializer emits belong here. Grow this set
 * with the code that emits a property, so unused vocabulary cannot drift.
 *
 * `id` and `type` alias JSON-LD keywords; they are not AS2 properties.
 * `@context` is itself a JSON-LD keyword, and `sf:verb` belongs to our
 * extension namespace rather than the W3C vocabulary.
 */
enum Property: string implements VocabularyTerm
{
    use IsVocabularyTerm {
        tryFromLoose as private tryFromWireSpelling;
    }

    case Actor = 'actor';
    case Object = 'object';
    case Target = 'target';
    case Context = 'context';
    case Origin = 'origin';
    case Result = 'result';
    case Instrument = 'instrument';
    case Published = 'published';
    case TotalItems = 'totalItems';

    case OrderedItems = 'orderedItems';

    // The responses to what an activity is about — a Collection carrying
    // `totalItems`, and the one clean AS2 mapping FeedThread has
    // (docs/payload.md, `thread`).
    case Replies = 'replies';

    // The sentence. AS2 core's own first examples are activities carrying
    // `"summary": "Martin created an image"`; this package had that sentence
    // in its grammar and withheld it (docs/activity-streams.md, `summary`).
    case Summary = 'summary';

    case Name = 'name';
    case Url = 'url';

    // The media slots and the Link properties they carry (docs/payload.md,
    // `entity.media`). `name` doubles as a Link's accessible name — what a
    // renderer calls alt — so it needs no second case.
    case Icon = 'icon';
    case Image = 'image';
    case Preview = 'preview';
    case Href = 'href';
    case MediaType = 'mediaType';
    case Width = 'width';
    case Height = 'height';

    public function iri(): string
    {
        // orderedItems is the JSON-LD list alias for as:items, not a
        // separate vocabulary term. Keep the wire spelling in the value.
        return Context::AS2.'#'.($this === self::OrderedItems ? 'items' : $this->value);
    }

    public static function tryFromLoose(string $value): ?static
    {
        // The canonical IRI must round-trip as well as the emitted alias.
        $term = trim($value);

        if (str_starts_with($term, Context::AS2.'#')) {
            $term = substr($term, strlen(Context::AS2) + 1);
        } elseif (str_starts_with($term, 'as:')) {
            $term = substr($term, 3);
        }

        return strcasecmp($term, 'items') === 0
            ? self::OrderedItems
            : self::tryFromWireSpelling($value);
    }
}
