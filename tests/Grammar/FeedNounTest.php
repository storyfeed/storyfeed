<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedNoun;

it('selects the English plural forms by count', function () {
    expect(FeedNoun::form('clause|clauses', 1))->toBe('clause')
        ->and(FeedNoun::form('clause|clauses', 7))->toBe('clauses');
});

it('falls back to a generic noun when none is registered', function () {
    expect(FeedNoun::form(null, 7))->toBe('items')
        ->and(FeedNoun::form(null, 1))->toBe('item');
});

it('never prints the number itself', function () {
    // phrase() used to hand back "1,204 clauses". The grouping in it was
    // number_format() — right in English, wrong in Polish ("1 204") and
    // German ("1.204") — an English guess of exactly the kind this class
    // refuses to make for plurals. The count selects the form and stays
    // out of the string; an app that wants it said formats it with its own
    // locale-aware helper and prepends it.
    expect(FeedNoun::form('clause|clauses', 1204))->toBe('clauses')
        ->and(method_exists(FeedNoun::class, 'phrase'))->toBeFalse();
});

it('serves locales with more than two plural forms', function () {
    // The whole reason literal forms go through Laravel's MessageSelector
    // rather than an English Str::plural(): Polish needs three, and a
    // hand-rolled pluraliser could not have them.
    app()->setLocale('pl');

    $forms = 'klauzula|klauzule|klauzul';

    expect(FeedNoun::form($forms, 1))->toBe('klauzula')
        ->and(FeedNoun::form($forms, 2))->toBe('klauzule')
        ->and(FeedNoun::form($forms, 5))->toBe('klauzul')
        ->and(FeedNoun::form($forms, 22))->toBe('klauzule');
});

it('resolves a translation key only behind the explicit wrapper', function () {
    app('translator')->addLines(['nouns.clause' => 'clause|clauses'], 'en');

    // The same string as a plain value is never looked up — otherwise
    // adding a lang file could silently rewrite a headline. It is read as
    // literal forms, and having only one of them is an error.
    expect(FeedNoun::form(FeedNoun::trans('nouns.clause'), 7))->toBe('clauses')
        ->and(fn () => FeedNoun::form('nouns.clause', 7))
        ->toThrow(InvalidArgumentException::class, 'has only one form');
});

it('refuses to inflect, and says so where the developer is looking', function () {
    // "terms sheet" pluralises on the head noun; Str::plural() would say
    // "terms sheets" only by luck and "7 terms sheet" is a typo the package
    // would have shipped on the app's behalf. Both forms, always.
    expect(fn () => Storyfeed::nouns(['terms_sheet' => 'terms sheet']))
        ->toThrow(InvalidArgumentException::class, 'has only one form');

    Storyfeed::nouns(['terms_sheet' => 'terms sheet|terms sheets']);

    expect(FeedNoun::form(Storyfeed::noun('terms_sheet', 'sign'), 4))->toBe('terms sheets');
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

    expect(FeedNoun::form(Storyfeed::noun('anything', 'upload'), 3))->toBe('records');
});

it('refuses a list where a map of nouns was meant', function () {
    expect(fn () => Storyfeed::nouns(['clause|clauses']))
        ->toThrow(InvalidArgumentException::class, 'takes a MAP');
});

it('suppresses :count inside a translated noun rather than doubling the number', function () {
    // trans_choice() adds `count` to the replacements for free, so a
    // translator who writes ":count clauses" — an entirely reasonable thing to
    // write — used to put "7 7 clauses" on the page. The key supplies the
    // noun and nothing else; no number is printed anywhere.
    app('translator')->addLines(['test.clause' => '{1} :count clause|[2,*] :count clauses'], 'en');

    expect(FeedNoun::form(FeedNoun::trans('test.clause'), 7))->toBe('clauses')
        ->and(FeedNoun::form(FeedNoun::trans('test.clause'), 1))->toBe('clause');
});
