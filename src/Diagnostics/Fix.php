<?php

namespace Storyfeed\Diagnostics;

/**
 * The registry edit that resolves a finding — as data, not prose.
 *
 * This exists because of a workflow a consumer had to write down for itself:
 * *register the axis → run real traffic → run storyfeed:doctor → author
 * exactly the keys it names.* That loop worked, but the last step was manual
 * transcription from CLI text. A `Fix` makes doctor's output executable
 * (`storyfeed:doctor --stubs`) and machine-readable (`--json`).
 *
 * `$tokens` is the decisive field. It is derived from the axis's compiled
 * recipe (`StoryfeedManager::aggregateTokens()`), so a generated snippet can
 * only ever suggest tokens the axis actually pins — which structurally
 * prevents the lie class the token check otherwise catches after the fact
 * (":object" on the repeat axis rendering "made 5 revisions to Aut
 * Beatae.docx" over five different documents).
 *
 * NOTE: transcribing an OBSERVED fact is not inference. This carries no
 * guesses — every value comes from a pair actually recorded, an axis actually
 * stamped a winner, or tokens actually derived from a recipe. That is the line
 * between this and the parked `storyfeed:eject` inference engine.
 */
final class Fix
{
    /**
     * @param  string  $registry  the manager method to call, e.g. 'aggregateGrammar'
     * @param  string  $key  the registry key, e.g. 'targets.approve'
     * @param  list<string>  $tokens  tokens that are SAFE here, derived from the axis recipe
     * @param  string|null  $snippet  paste-ready PHP; built from the above when omitted
     */
    public function __construct(
        public readonly string $registry,
        public readonly string $key,
        public readonly array $tokens = [],
        public readonly ?string $snippet = null,
    ) {}

    /**
     * @param  list<string>  $tokens
     */
    public static function make(string $registry, string $key, array $tokens = [], ?string $snippet = null): self
    {
        return new self($registry, $key, $tokens, $snippet);
    }

    /**
     * Paste-ready registration code. The placeholder template is deliberately
     * obvious prose ("TODO") rather than a plausible-looking sentence: a
     * generated headline that reads well is one nobody rewrites, and only
     * taste validates prose (grammar's one untoolable rule).
     */
    public function snippet(): string
    {
        if ($this->snippet !== null) {
            return $this->snippet;
        }

        $template = $this->tokens === []
            ? 'TODO write the headline'
            : 'TODO '.implode(' ', $this->tokens);

        return sprintf(
            "Storyfeed::%s([\n    '%s' => '%s',\n]);",
            $this->registry,
            $this->key,
            $template,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'registry' => $this->registry,
            'key' => $this->key,
            'tokens' => $this->tokens,
            'snippet' => $this->snippet(),
        ];
    }
}
