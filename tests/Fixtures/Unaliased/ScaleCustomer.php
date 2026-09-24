<?php

namespace Storyfeed\Tests\Fixtures\Unaliased;

use Storyfeed\FeedContext;
use Storyfeed\FeedMedia;
use Workbench\App\Models\Customer;

/**
 * A probe subclass of an aliased model, aliased only while the probe runs —
 * the shape of a consumer's scale-measurement models, which swap themselves
 * into the morph map for one command and are absent from it the rest of the
 * time. Under an enforced map its getMorphClass() throws, and the doctor's
 * `surface` and `hydration` checks used to throw with it. They scan this
 * directory for it.
 */
final class ScaleCustomer extends Customer
{
    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        return parent::feedMedia($context);
    }
}
