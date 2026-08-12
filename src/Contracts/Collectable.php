<?php

namespace Storyfeed\Contracts;

/**
 * Marker: activities about this Feedable arrive in COLLECTIONS — "uploaded
 * 6 files" is one act, not six. Designating a model collection-natured lets
 * the auto-bundler mint a composite story from an atomically-recorded run
 * (same actor/verb/target, distinct objects) when the actor's batch closes;
 * the developer never corrals activities by hand.
 *
 * A collection of one needs no story: a solo upload stays a plain atomic
 * activity, which IS the collapsed presentation.
 *
 * The registry override (`Storyfeed::collectables(['document'])`) wins over
 * the interface, covering third-party models — the established pattern.
 */
interface Collectable {}
