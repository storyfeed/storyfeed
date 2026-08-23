<?php

namespace Storyfeed\Demo;

use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Facades\Storyfeed;

/**
 * The demo kit's verb vocabulary, grammar, aggregate grammar and icons.
 *
 * Every verb is prefixed `demo.` and that prefix is load-bearing rather than
 * decorative. It is what makes teardown safe: `storyfeed:demo --fresh` deletes
 * activities whose verb starts with `demo.` and can therefore never reach a row
 * the application published, on any driver, without a JSON path expression and
 * without truncating a table. A demo kit that could delete real activities would
 * be a worse hazard than the one this kit exists to remove.
 *
 * It is also visible: an operator reading the payload in dev tools on stage sees
 * `demo.upload` and knows immediately which world they are looking at. The
 * rendered headline is unaffected — that comes from the grammar below — so the
 * prefix costs the audience nothing and tells the truth to anyone who looks.
 *
 * Grammar is keyed by verb (`*.demo.upload`) rather than by type, because every
 * entity in the shipped cast is a Party and therefore shares one morph alias.
 * See docs/demo-data.md for what that trade buys and what it costs.
 */
class Vocabulary
{
    /** The prefix every demo verb carries. Teardown matches on it. */
    public const PREFIX = 'demo.';

    public const UPLOAD = 'demo.upload';

    public const COMMENT = 'demo.comment';

    public const COMPLETE = 'demo.complete';

    public const APPROVE = 'demo.approve';

    public const CREATE = 'demo.create';

    public const INVITE = 'demo.invite';

    /**
     * Register the whole vocabulary. Merges, so an app's own registrations are
     * untouched — the kit adds a world beside yours rather than replacing it.
     */
    public static function register(): void
    {
        Storyfeed::verbs([
            self::UPLOAD => ActivityType::Add,
            self::COMMENT => ActivityType::Create,
            self::COMPLETE => ActivityType::Update,
            self::APPROVE => ActivityType::Accept,
            self::CREATE => ActivityType::Create,
            self::INVITE => ActivityType::Invite,
        ]);

        Storyfeed::grammar([
            '*.'.self::UPLOAD => ':actor uploaded :object to :context',
            '*.'.self::COMMENT => ':actor commented on :object',
            '*.'.self::COMPLETE => ':actor completed :object',
            '*.'.self::APPROVE => ':actor approved :object',
            '*.'.self::CREATE => ':actor created :object in :context',
            '*.'.self::INVITE => ':actor invited :object to :context',
        ]);

        // The collapsed forms, and they are registered for EVERY axis that can
        // win rather than the ones a first run happened to produce. A group node
        // whose axis has no aggregate grammar renders with a null headline —
        // silently, in the one place a demo cannot afford it — and which axis
        // wins depends on the data, so "it looked right yesterday" is not
        // evidence. A test asserts no group node in a seeded feed is headline-less.
        $collapsed = [
            self::UPLOAD => [
                'repeat' => ':actor uploaded :count files to :context',
                'actors' => ':actors uploaded :count files to :context',
                'object' => ':actor uploaded :object :count times',
                'targets' => ':actor uploaded files to :targets',
            ],
            self::COMMENT => [
                'repeat' => ':actor left :count comments on :object',
                'actors' => ':actors commented on :object',
                'object' => ':actor left :count comments on :object',
                'targets' => ':actor commented across :targets',
            ],
            self::COMPLETE => [
                'repeat' => ':actor completed :count tasks',
                'actors' => ':actors completed :count tasks',
                'object' => ':actor updated :object :count times',
                'targets' => ':actor completed tasks across :targets',
            ],
            self::CREATE => [
                'repeat' => ':actor created :count items in :context',
                'actors' => ':actors created :count items in :context',
                'object' => ':actor created :object :count times',
                'targets' => ':actor created items across :targets',
            ],
            self::APPROVE => [
                'repeat' => ':actor approved :count items',
                'actors' => ':actors approved :count items',
                'object' => ':actor approved :object :count times',
                'targets' => ':actor approved items across :targets',
            ],
            self::INVITE => [
                'repeat' => ':actor sent :count invitations',
                'actors' => ':actors sent :count invitations',
                'object' => ':actor invited :object :count times',
                'targets' => ':actor sent invitations across :targets',
            ],
        ];

        $aggregate = [];

        foreach ($collapsed as $verb => $byAxis) {
            foreach ($byAxis as $axis => $template) {
                $aggregate["{$axis}.{$verb}"] = $template;
            }
        }

        Storyfeed::aggregateGrammar($aggregate);

        Storyfeed::icons([
            '*.'.self::UPLOAD => 'bi-cloud-arrow-up',
            '*.'.self::COMMENT => 'bi-chat-left-text',
            '*.'.self::COMPLETE => 'bi-check2-circle',
            '*.'.self::APPROVE => 'bi-patch-check',
            '*.'.self::CREATE => 'bi-plus-circle',
            '*.'.self::INVITE => 'bi-person-plus',
        ]);
    }
}
