<?php

namespace Storyfeed\Diagnostics;

use Illuminate\Support\Collection;

/**
 * The result of a doctor run, as data.
 *
 * `Storyfeed::doctor()` returns this so an application can render feed health
 * in its own UI. That is not hypothetical: a consumer was shelling
 * `Artisan::call('storyfeed:doctor')` and printing the raw CLI output in a web
 * page, because the command was the only view of registry coverage. A report
 * object makes the command one formatter among several rather than the API.
 *
 * `Info` findings are carried but never counted — see Severity.
 */
final class Report
{
    /** @param list<Finding> $findings */
    public function __construct(
        public readonly array $findings = [],
    ) {}

    /** @return Collection<int, Finding> */
    public function all(): Collection
    {
        return Collection::make($this->findings);
    }

    /** Warnings and errors — what "N finding(s)" counts. @return Collection<int, Finding> */
    public function problems(): Collection
    {
        return $this->all()->filter(fn (Finding $f) => $f->severity->isFinding())->values();
    }

    public function count(): int
    {
        return $this->problems()->count();
    }

    public function isHealthy(): bool
    {
        return $this->count() === 0;
    }

    /** @return Collection<int, Finding> */
    public function withCode(string $code): Collection
    {
        return $this->all()->filter(fn (Finding $f) => $f->code === $code)->values();
    }

    public function has(string $code): bool
    {
        return $this->withCode($code)->isNotEmpty();
    }

    /** Highest severity present, or null when there is nothing to report. */
    public function severity(): ?Severity
    {
        return $this->problems()
            ->sortByDesc(fn (Finding $f) => $f->severity->weight())
            ->first()?->severity;
    }

    /**
     * Every fix, deduped by registry+key. Two findings can name the same edit
     * (a pair missing both grammar and an icon is two findings, one snippet
     * each), and printing a key twice invites pasting it twice.
     *
     * @return Collection<int, Fix>
     */
    public function fixes(): Collection
    {
        return $this->all()
            ->map(fn (Finding $f) => $f->fix)
            ->filter()
            ->unique(fn (Fix $fix) => $fix->registry.'|'.$fix->key)
            ->values();
    }

    /** @param list<string> $names check names to keep */
    public function only(array $names): self
    {
        if ($names === []) {
            return $this;
        }

        return new self(
            $this->all()->filter(fn (Finding $f) => in_array($f->group(), $names, true))->values()->all()
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'healthy' => $this->isHealthy(),
            'count' => $this->count(),
            'severity' => $this->severity()?->value,
            'findings' => $this->all()->map(fn (Finding $f) => $f->toArray())->all(),
        ];
    }
}
