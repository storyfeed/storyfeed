<?php

namespace Storyfeed\Healing;

/*
 * @deprecated Use ActivityRetirement; removed before v1. An alias rather than
 * a subclass, because ActivityRetirement is final and a healer returning
 * either name must satisfy the same type.
 */
class_alias(ActivityRetirement::class, 'Storyfeed\Healing\StoryRetirement');
