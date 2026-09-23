<?php

namespace Storyfeed\Diagnostics;

use Illuminate\Database\Eloquent\Relations\Relation;
use Storyfeed\Grouping\GroupBuilder;

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

    /**
     * The same edit as a `routes/feed.php` definition, with the imports it
     * needs: `Story::for(Order::class)->verb('place')->headline('TODO …');`.
     * Null for a registry the Story facade doesn't write, or a hand-built
     * snippet, which stay in the array form.
     *
     * @return array{code: string, imports: list<string>}|null
     */
    public function definition(): ?array
    {
        if ($this->snippet !== null || ! str_contains($this->key, '.')) {
            return null;
        }

        [$first, $verb] = explode('.', $this->key, 2);
        $template = $this->tokens === [] ? 'TODO write the headline' : 'TODO '.implode(' ', $this->tokens);
        $imports = ['Storyfeed\Facades\Story'];

        if ($this->registry === 'aggregateGrammar') {
            $imports[] = GroupBuilder::class;
            $group = match ($first) {
                '*' => "any('{$template}')",
                'repeat', 'actors', 'targets', 'object', 'composite' => "{$first}('{$template}')",
                default => "axis('{$first}', '{$template}')",
            };

            return [
                'code' => $this->scope('*', $verb, $imports)."->grouped(fn (GroupBuilder \$group) => \$group->{$group});",
                'imports' => $imports,
            ];
        }

        $call = match ($this->registry) {
            'grammar' => "headline('{$template}')",
            'actorlessGrammar' => "anonymousHeadline('TODO write the headline without an actor')",
            'icons' => "icon('TODO')",
            'glyphIntents' => "intent('TODO')",
            default => null,
        };

        if ($call === null) {
            return null;
        }

        return ['code' => $this->scope($first, $verb, $imports)."->{$call};", 'imports' => $imports];
    }

    /**
     * `Story::for(Order::class)->verb('place')`, `Story::verb('place')`,
     * `Story::for(Order::class)->fallback()` or `Story::fallback()`. A morph
     * alias becomes its model class where the morph map knows it.
     *
     * @param  list<string>  $imports
     */
    private function scope(string $type, string $verb, array &$imports): string
    {
        $call = $verb === '*' ? 'fallback()' : "verb('{$verb}')";

        if ($type === '*') {
            return "Story::{$call}";
        }

        $class = Relation::getMorphedModel($type) ?? (class_exists($type) ? $type : null);

        if ($class === null) {
            return "Story::for('{$type}')->{$call}";
        }

        $imports[] = ltrim($class, '\\');

        return 'Story::for('.class_basename($class)."::class)->{$call}";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'registry' => $this->registry,
            'key' => $this->key,
            'tokens' => $this->tokens,
            'snippet' => $this->snippet(),
            'definition' => $this->definition()['code'] ?? null,
        ];
    }
}
