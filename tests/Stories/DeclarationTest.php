<?php

use Storyfeed\Story;
use Symfony\Component\Process\Process;

/*
 * PHP property types are INVARIANT. A Story subclass that redeclares `$verb`
 * or `$objectType` with any type other than the base class's exact union is a
 * fatal at class load — not an exception, so nothing in-process can catch it,
 * and nothing in this suite ever exercised a hand-written declaration. The
 * docblock on Story taught a narrowed `$verb`, the docs site copied it, and
 * every Story a reader copied from the quickstart refused to load.
 *
 * The guard runs the declaration in a child PHP process. It reads the example
 * out of Story's own docblock, so the docblock cannot drift back to a form
 * that does not load without this test noticing.
 */

function loadStorySubclass(string $objectTypeLine, string $verbLine): Process
{
    $autoload = realpath(__DIR__.'/../../vendor/autoload.php');

    $php = <<<PHP
    require '{$autoload}';
    use Storyfeed\\Contracts\\FeedVerb;
    use Storyfeed\\Story;
    use Workbench\\App\\Enums\\ActivityVerb;
    use Workbench\\App\\Models\\Document;
    final class DocumentWasUploaded extends Story
    {
        {$objectTypeLine}
        {$verbLine}
        public function headline(): string { return ':actor uploaded :object'; }
    }
    echo get_parent_class(new DocumentWasUploaded);
    PHP;

    $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $php]);
    $process->run();

    return $process;
}

function docblockDeclarations(): array
{
    $source = file_get_contents(__DIR__.'/../../src/Story.php');

    preg_match('/^\s*\*\s+(public string\|array\|null \$objectType = .+;)$/m', $source, $objectType);
    preg_match('/^\s*\*\s+(public .+ \$verb = .+;)$/m', $source, $verb);

    return [$objectType[1] ?? null, $verb[1] ?? null];
}

it('loads a story declared exactly as the docblock on Story teaches', function () {
    [$objectTypeLine, $verbLine] = docblockDeclarations();

    expect($objectTypeLine)->not->toBeNull('Story\'s docblock no longer shows an $objectType declaration.')
        ->and($verbLine)->not->toBeNull('Story\'s docblock no longer shows a $verb declaration.');

    $process = loadStorySubclass($objectTypeLine, $verbLine);

    expect($process->isSuccessful())->toBeTrue(
        "The declaration Story's docblock teaches does not load:\n{$process->getErrorOutput()}",
    )->and($process->getOutput())->toBe(Story::class);
});

it('is a fatal at class load to narrow $verb — the form the docs once taught', function () {
    $process = loadStorySubclass(
        'public string|array|null $objectType = Document::class;',
        "public string|FeedVerb|null \$verb = 'upload';",
    );

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('Type of DocumentWasUploaded::$verb must be');
});

it('is a fatal at class load to narrow $objectType', function () {
    $process = loadStorySubclass(
        'public string $objectType = Document::class;',
        "public string|FeedVerb|BackedEnum|null \$verb = 'upload';",
    );

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('Type of DocumentWasUploaded::$objectType must be');
});
