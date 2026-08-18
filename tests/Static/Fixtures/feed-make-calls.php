<?php

namespace Storyfeed\Tests\Static\Fixtures;

use Workbench\App\Feeds\AdminFeed;
use Workbench\App\Feeds\CustomerFeed;
use Workbench\App\Models\Customer;

function calls(Customer $customer, array $spread): void
{
    // Line 12: the case this rule exists for.
    CustomerFeed::make();

    // Correct, and silent.
    CustomerFeed::make($customer);

    // Line 18: past the end of the constructor.
    CustomerFeed::make($customer, $customer);

    // A feed with no constructor of its own takes nothing. Silent.
    AdminFeed::make();

    // Optional parameters are optional.
    WindowedFeed::make($customer);
    WindowedFeed::make($customer, 'last week');

    // Line 28: still one short, even with the optional one in view.
    WindowedFeed::make();

    // A spread's length is unknowable here; the rule says nothing.
    CustomerFeed::make(...$spread);

    // Not a Feed. Not this rule's business.
    Customer::make();
}
