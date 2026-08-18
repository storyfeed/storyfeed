<?php

namespace Storyfeed\Tests\Static;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Storyfeed\PHPStan\FeedMakeArityRule;

/**
 * The rule the package ships to consumers, run against a fixture of every call
 * shape that matters. This is a plain PHPUnit case rather than a Pest test
 * because RuleTestCase brings its own container bootstrap; it runs in the same
 * suite regardless.
 *
 * @extends RuleTestCase<FeedMakeArityRule>
 */
class FeedMakeArityRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new FeedMakeArityRule($this->createReflectionProvider());
    }

    public function test_it_checks_make_against_the_constructor_it_reaches(): void
    {
        $this->analyse([__DIR__.'/Fixtures/feed-make-calls.php'], [
            [
                'Workbench\App\Feeds\CustomerFeed::make() invoked with 0 arguments, 1 required — '
                .'Workbench\App\Feeds\CustomerFeed::__construct() declares ($customer). A Feed takes its '
                .'subject through the constructor, so this is an unscoped feed: it would throw '
                .'ArgumentCountError on the first call.',
                12,
            ],
            [
                'Workbench\App\Feeds\CustomerFeed::make() invoked with 2 arguments, at most 1 expected — '
                .'Workbench\App\Feeds\CustomerFeed::__construct() declares ($customer).',
                18,
            ],
            [
                'Storyfeed\Tests\Static\Fixtures\WindowedFeed::make() invoked with 0 arguments, 1 required — '
                .'Storyfeed\Tests\Static\Fixtures\WindowedFeed::__construct() declares ($customer, $since = …). '
                .'A Feed takes its subject through the constructor, so this is an unscoped feed: it would '
                .'throw ArgumentCountError on the first call.',
                28,
            ],
        ]);
    }
}
