<?php

namespace Storyfeed\Diagnostics;

/** Exact, application-owned acceptance of deliberate grammar gaps, never broad suppression. */
final class Acknowledgments
{
    private const SUBJECTS = [
        'grammar.missing' => ['type', 'verb'],
        'grammar.icon_missing' => ['type', 'verb'],
        'aggregates.missing' => ['axis', 'verb', 'key', 'read_by'],
        'axes.verbless_no_grammar' => ['axis'],
    ];

    private const CHECKS = [
        'grammar.missing' => 'grammar',
        'grammar.icon_missing' => 'grammar',
        'aggregates.missing' => 'aggregates',
        'axes.verbless_no_grammar' => 'axes',
    ];

    /**
     * @param  list<Finding>  $findings
     * @param  list<string>  $completed  Checks that ran to completion without any failure.
     */
    public function apply(array $findings, array $completed, mixed $policy): Report
    {
        if (! is_array($policy) || ! array_is_list($policy)) {
            return new Report([...$findings, $this->invalid('The acknowledgment policy must be a list.')]);
        }

        $entries = [];
        $identities = [];
        foreach ($policy as $index => $entry) {
            if (! $this->valid($entry)) {
                $findings[] = $this->invalid("Acknowledgment entry {$index} needs a supported code, complete typed subject, and nonempty reason.");

                continue;
            }

            $identity = $this->identity($entry['code'], $entry['subject']);
            $identities[$identity] = ($identities[$identity] ?? 0) + 1;
            $entries[] = $entry;
        }

        foreach ($identities as $identity => $count) {
            if ($count > 1) {
                $findings[] = $this->invalid('Duplicate acknowledgment identity: '.$identity.'. Neither duplicate is applied.');
            }
        }

        foreach ($entries as $entry) {
            $identity = $this->identity($entry['code'], $entry['subject']);
            $check = self::CHECKS[$entry['code']];
            if ($identities[$identity] > 1 || ! in_array($check, $completed, true)) {
                continue;
            }

            $matched = false;
            foreach ($findings as $index => $finding) {
                if ($finding->code === $entry['code'] && $this->identity($finding->code, $finding->subject) === $identity) {
                    $findings[$index] = new Finding(
                        $finding->code, $finding->severity, $finding->message,
                        $finding->subject, $finding->fix, $entry['reason'],
                    );
                    $matched = true;
                }
            }

            if (! $matched) {
                $subject = ['code' => $entry['code'], 'check' => $check] + $entry['subject'];
                $findings[] = $check === 'axes'
                    ? Finding::warning('doctor.acknowledgment_stale', "Acknowledgment for `{$entry['code']}` no longer matches this registry check — review or remove it.", $subject)
                    : Finding::info('doctor.acknowledgment_unobserved', "Acknowledgment for `{$entry['code']}` was not observed — the gap may be resolved or absent from this run's traffic or clusters.", $subject);
            }
        }

        return new Report($findings);
    }

    /** @phpstan-assert-if-true array{code: string, subject: array<string, string|null>, reason: string} $entry */
    private function valid(mixed $entry): bool
    {
        if (! is_array($entry) || ! $this->keys($entry, ['code', 'subject', 'reason'])
            || ! is_string($entry['code']) || ! isset(self::SUBJECTS[$entry['code']])
            || ! is_string($entry['reason']) || trim($entry['reason']) === ''
            || ! is_array($entry['subject']) || ! $this->keys($entry['subject'], self::SUBJECTS[$entry['code']])) {
            return false;
        }

        foreach ($entry['subject'] as $key => $value) {
            if (! is_string($value) && ! ($value === null && in_array($key, ['type', 'read_by'], true))) {
                return false;
            }
        }

        return true;
    }

    /** @param array<array-key, mixed> $value
     * @param  list<string>  $expected
     */
    private function keys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }

    /** @param array<string, scalar|null> $subject */
    private function identity(string $code, array $subject): string
    {
        ksort($subject);

        return $code.'|'.serialize($subject);
    }

    private function invalid(string $message): Finding
    {
        return Finding::error('doctor.acknowledgment_invalid', $message.' No invalid entry can accept a finding.');
    }
}
