<?php

namespace Storyfeed\Diagnostics;

use Illuminate\Database\Eloquent\Relations\Relation;
use Storyfeed\Grouping\GroupBuilder;
use Storyfeed\Support\StoryName;

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
 * between this and the parked `storyfeed:eject` inference engine. A printed
 * sentence conjugates the stored verb only where the spelling is certain
 * (`StoryName::certainParticiple()`); elsewhere the line is left commented.
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
     * Paste-ready registration code — or, where doctor cannot write a line
     * that is true, the same code commented out beneath the reason.
     *
     * Never `TODO`. A pasted placeholder satisfies the very check that printed
     * it: `headline('TODO …')` resolves, so doctor goes quiet while the feed
     * says "TODO" to users. The sentence is therefore built the way
     * `make:story` builds its own — the stored verb in the past tense, over
     * tokens the axis pins — and only when that is certain. Otherwise nothing
     * live is printed, and doctor keeps saying so until someone decides.
     */
    public function snippet(): string
    {
        if ($this->snippet !== null) {
            return $this->snippet;
        }

        $code = sprintf(
            "Storyfeed::%s([\n    '%s' => '%s',\n]);",
            $this->registry,
            $this->key,
            $this->template() ?? '…',
        );

        return $this->live($code);
    }

    /**
     * The same edit as a `routes/feed.php` definition, with the imports it
     * needs: `Story::for(Order::class)->verb('place')->headline(':actor placed :object');`.
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
        $template = $this->template() ?? '…';
        $imports = ['Storyfeed\Facades\Story'];

        if ($this->registry === 'aggregateGrammar') {
            $imports[] = GroupBuilder::class;
            $group = match ($first) {
                '*' => "any('{$template}')",
                'repeat', 'actors', 'targets', 'object', 'composite' => "{$first}('{$template}')",
                default => "axis('{$first}', '{$template}')",
            };

            return [
                'code' => $this->live($this->scope('*', $verb, $imports)."->grouped(fn (GroupBuilder \$group) => \$group->{$group});"),
                'imports' => $imports,
            ];
        }

        $call = match ($this->registry) {
            'grammar' => "headline('{$template}')",
            'actorlessGrammar' => "anonymousHeadline('{$template}')",
            'icons' => "icon('…')",
            default => null,
        };

        if ($call === null) {
            return null;
        }

        return ['code' => $this->live($this->scope($first, $verb, $imports)."->{$call};"), 'imports' => $imports];
    }

    /**
     * The value this edit registers, or null where doctor cannot know it: an
     * icon is the app's own vocabulary, a verb-agnostic key needs a
     * sentence true of every verb, and a verb whose past tense is not certain
     * (`ship`, `check_in`) would be printed misspelled.
     *
     * Every token used is one the finding says is safe here.
     */
    private function template(): ?string
    {
        [$type, $verb] = str_contains($this->key, '.') ? explode('.', $this->key, 2) : ['*', $this->key];
        $past = $verb === '*' ? null : StoryName::certainParticiple($verb);
        $pins = fn (string $token) => in_array($token, $this->tokens, true);

        if ($past === null) {
            return null;
        }

        return match ($this->registry) {
            // `*` here is a pair recorded with no object, so none is named.
            'grammar' => $type === '*' ? ":actor {$past}" : ":actor {$past} :object",
            'actorlessGrammar' => $type === '*' ? ucfirst($past) : ":object was {$past}",
            // A sentence naming the verb is true of a group only where the
            // axis pins it; the singular forms only where it pins those.
            'aggregateGrammar' => $pins(':verb')
                ? ($pins(':actor') ? ':actor' : ':actors')." {$past} ".($pins(':object') ? ':object' : ':objects')
                : null,
            default => null,
        };
    }

    /** The definition as it stands, or commented out beneath the reason. */
    private function live(string $code): string
    {
        return $this->template() === null ? $this->commented($code) : $code;
    }

    private function commented(string $code): string
    {
        $verb = str_contains($this->key, '.') ? explode('.', $this->key, 2)[1] : $this->key;

        $reason = match (true) {
            $this->registry === 'icons' => "{$this->key}: an icon from your app's own set; doctor cannot choose one.",
            $verb === '*' => "{$this->key}: one sentence true of every verb this key covers.",
            $this->registry === 'aggregateGrammar' && ! in_array(':verb', $this->tokens, true) => "{$this->key}: the axis does not pin the verb, so a sentence naming it can be false of a group.",
            default => "{$this->key}: doctor cannot spell '{$verb}' in the past tense for certain.",
        };

        if ($this->tokens !== []) {
            $reason .= ' Safe tokens: '.implode(' ', $this->tokens);
        }

        return implode(PHP_EOL, array_map(fn (string $line) => "// {$line}", [$reason, ...explode("\n", $code)]));
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
