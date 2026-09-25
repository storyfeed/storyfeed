<?php

namespace Storyfeed\Tests\Static;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Storyfeed\PHPStan\StoryNameRule;

/**
 * The story-name rule, run against a fixture of every call shape. The
 * names come from a fixed list here; in an app they come from the booted
 * application Larastan provides.
 *
 * @extends RuleTestCase<StoryNameRule>
 */
class StoryNameRuleTest extends RuleTestCase
{
    /** @var list<string>|null */
    private ?array $names = ['delivery.ship'];

    protected function getRule(): Rule
    {
        return new class($this->names) extends StoryNameRule
        {
            /** @param  list<string>|null  $known */
            public function __construct(private readonly ?array $known) {}

            protected function knownNames(): ?array
            {
                return $this->known;
            }
        };
    }

    public function test_it_reports_a_literal_name_nothing_defined(): void
    {
        $fix = ' Name a story in routes/feed.php with ->name(), or use a name storyfeed:list shows.';

        $this->analyse([__DIR__.'/Fixtures/story-name-calls.php'], [
            ['Story [delivery.shp] not defined: story() would throw StoryNotFound.'.$fix, 13],
            ['Story [order.confirm] not defined: Storyfeed::route() would throw StoryNotFound.'.$fix, 16],
            ['Story [delivery.lost] not defined: Story::has() is always false for it.'.$fix, 19],
            ['Story [delivery.sent] not defined: route() would throw StoryNotFound.'.$fix, 21],
            ['Story [delivery.gone] not defined: story() would throw StoryNotFound.'.$fix, 29],
        ]);
    }

    public function test_it_says_nothing_without_an_application_to_ask(): void
    {
        $this->names = null;

        $this->analyse([__DIR__.'/Fixtures/story-name-calls.php'], []);
    }
}
