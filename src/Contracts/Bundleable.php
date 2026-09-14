<?php

namespace Storyfeed\Contracts;

/**
 * Marker: runs of this Feedable BUNDLE into one composite activity — "uploaded
 * 6 files" is one act, not six. Designating a model bundleable lets the
 * auto-bundler mint a composite story from an atomically-recorded run (same
 * actor/verb/target, distinct objects) when the actor's batch closes; the
 * developer never corrals activities by hand.
 *
 * A run of one needs no story: a solo upload stays a plain atomic activity,
 * which IS the collapsed presentation.
 *
 * The registry override (`Storyfeed::bundleables(['document'])`) wins over
 * the interface, covering third-party models — the established pattern.
 *
 * Named for what happens to the model's runs, not for the shape of the
 * result: `Collectable` (its name through v0.10) read as "can become a
 * Laravel Collection".
 */
interface Bundleable {}
