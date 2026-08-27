<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Support\Noun;

it('selects the English plural forms by count', function () {
    expect(Noun::phrase('clause|clauses', 1))->toBe('1 clause')
        ->and(Noun::phrase('clause|clauses', 7))->toBe('7 clauses');
});

it('falls back to a generic noun when none is registered', function () {
    expect(Noun::phrase(null, 7))->toBe('7 items')
        ->and(Noun::phrase(null, 1))->toBe('1 item');
});

it('groups the thousands, because a headline is read by a person', function () {
    expect(Noun::phrase('clause|clauses', 1204))->toBe('1,204 clauses');
});

it('serves locales with more than two plural forms', function () {
    // The whole reason literal forms go through Laravel's MessageSelector
    // rather than an English Str::plural(): Polish needs three, and a
    // hand-rolled pluraliser could not have them.
    app()->setLocale('pl');

    $forms = 'klauzula|klauzule|klauzul';

    expect(Noun::phrase($forms, 1))->toBe('1 klauzula')
        ->and(Noun::phrase($forms, 2))->toBe('2 klauzule')
        ->and(Noun::phrase($forms, 5))->toBe('5 klauzul')
        ->and(Noun::phrase($forms, 22))->toBe('22 klauzule');
});

it('resolves a translation key only behind the explicit wrapper', function () {
    app('translator')->addLines(['nouns.clause' => 'clause|clauses'], 'en');

    // The same string as a plain value is never looked up — otherwise
    // adding a lang file could silently rewrite a headline. It is read as
    // literal forms, and having only one of them is an error.
    expect(Noun::phrase(Noun::trans('nouns.clause'), 7))->toBe('7 clauses')
        ->and(fn () => Noun::phrase('nouns.clause', 7))
        ->toThrow(InvalidArgumentException::class, 'has only one form');
});

it('refuses to inflect, and says so where the developer is looking', function () {
    // "terms sheet" pluralises on the head noun; Str::plural() would say
    // "terms sheets" only by luck and "7 terms sheet" is a typo the package
    // would have shipped on the app's behalf. Both forms, always.
    expect(fn () => Storyfeed::nouns(['terms_sheet' => 'terms sheet']))
        ->toThrow(InvalidArgumentException::class, 'has only one form');

    Storyfeed::nouns(['terms_sheet' => 'terms sheet|terms sheets']);

    expect(Noun::phrase(Storyfeed::noun('terms_sheet', 'sign'), 4))->toBe('4 terms sheets');
});

it('looks nouns up by type, then by type and verb', function () {
    Storyfeed::nouns([
        'delivery' => 'delivery|deliveries',
        'delivery.upload' => 'file|files',
    ]);

    expect(Storyfeed::noun('delivery', 'upload'))->toBe('file|files')
        ->and(Storyfeed::noun('delivery', 'revise'))->toBe('delivery|deliveries')
        ->and(Storyfeed::noun('customer', 'upload'))->toBeNull()
        ->and(Storyfeed::noun(null, 'upload'))->toBeNull();
});

it('lets a generic entry stand in for every unregistered type', function () {
    Storyfeed::nouns(['*' => 'record|records']);

    expect(Noun::phrase(Storyfeed::noun('anything', 'upload'), 3))->toBe('3 records');
});

it('refuses a list where a map of nouns was meant', function () {
    expect(fn () => Storyfeed::nouns(['clause|clauses']))
        ->toThrow(InvalidArgumentException::class, 'takes a MAP');
});

it('suppresses :count inside a translated noun rather than doubling the number', function () {
    // trans_choice() adds `count` to the replacements for free, so a
    // translator who writes ":count clauses" — an entirely reasonable thing to
    // write — used to put "7 7 clauses" on the page. The number belongs to
    // phrase(); the key supplies the noun.
    app('translator')->addLines(['test.clause' => '{1} :count clause|[2,*] :count clauses'], 'en');

    expect(Noun::phrase(Noun::trans('test.clause'), 7))->toBe('7 clauses')
        ->and(Noun::phrase(Noun::trans('test.clause'), 1))->toBe('1 clause');
});
