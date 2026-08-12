<?php

namespace Storyfeed\Diagnostics;

/**
 * One diagnostic result.
 *
 * `$code` is the load-bearing field and it is a CONTRACT: stable, greppable,
 * dot-namespaced (`aggregate_grammar.missing`). Codes are what make findings
 * filterable (`--only=`), assertable in tests, and addressable in a consumer's
 * own tooling. A consumer was reduced to scraping doctor's CLI text to render
 * coverage in a web page; codes plus `Report::toArray()` are what replace that.
 *
 * `$subject` carries the identifying parts as data rather than interpolated
 * prose (`['axis' => 'targets', 'verb' => 'approve']`), so callers can group,
 * dedupe and count without parsing sentences.
 *
 * `$message` still says the CONSEQUENCE, not just the condition — that phrasing
 * is why doctor's output taught faster than any changelog, and it is worth
 * preserving verbatim as checks move in here.
 */
final class Finding
{
    /**
     * @param  array<string, scalar|null>  $subject
     */
    public function __construct(
        public readonly string $code,
        public readonly Severity $severity,
        public readonly string $message,
        public readonly array $subject = [],
        public readonly ?Fix $fix = null,
    ) {}

    /**
     * @param  array<string, scalar|null>  $subject
     */
    public static function error(string $code, string $message, array $subject = [], ?Fix $fix = null): self
    {
        return new self($code, Severity::Error, $message, $subject, $fix);
    }

    /**
     * @param  array<string, scalar|null>  $subject
     */
    public static function warning(string $code, string $message, array $subject = [], ?Fix $fix = null): self
    {
        return new self($code, Severity::Warning, $message, $subject, $fix);
    }

    /**
     * @param  array<string, scalar|null>  $subject
     */
    public static function info(string $code, string $message, array $subject = []): self
    {
        return new self($code, Severity::Info, $message, $subject);
    }

    /** The check that produced this, derived from the code's first segment. */
    public function group(): string
    {
        return explode('.', $this->code, 2)[0];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'subject' => $this->subject,
            'fix' => $this->fix?->toArray(),
        ];
    }
}
