<?php

namespace Storyfeed\ActivityStreams;

/**
 * A term from the Activity Streams 2.0 vocabulary.
 *
 * Implementations are pure transcriptions of the W3C vocabulary: they know
 * nothing about Storyfeed's registries or Eloquent, and they never throw.
 * Unrecognized terms are data, not errors — see tryFromLoose().
 */
interface VocabularyTerm
{
    /** The full IRI, e.g. https://www.w3.org/ns/activitystreams#Create */
    public function iri(): string;
}
