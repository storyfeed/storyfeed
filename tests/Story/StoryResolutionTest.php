<?php

use Storyfeed\Story;

it('can guess the type and object from class name', function ($className, $type, $object) {
    $class = <<<CLASS
<?php
namespace Storyfeed\Tests\FakeStories;

class $className extends \Storyfeed\Story {

    public function headline()
    {
        return 'headline';
    }

}
CLASS;

    // inject into temp file
    $path = tempnam(sys_get_temp_dir(), 'story');
    file_put_contents($path, $class);

    include_once $path;

    $fullyQualifiedClass = "Storyfeed\\Tests\\FakeStories\\{$className}";
    expect(class_exists($fullyQualifiedClass))->toBeTrue();

    $story = new $fullyQualifiedClass();
    expect($story)->toBeInstanceOf(Story::class);

    expect($story->type)->toBe($type);
    expect($story->objectType)->toBe($object);

    unlink($path);
})->with([
    ['CreateCustomerStory', 'create', 'customer'],
    ['CreateCustomer', 'create', 'customer'],
    ['SharePostStory', 'share', 'post'],
    ['SharePost', 'share', 'post'],
    ['ConfirmDeliveryStory', 'confirm', 'delivery'],
    ['ConfirmDelivery', 'confirm', 'delivery'],
    ['TellStoryStory', 'tell', 'story'],
    ['TellStory', 'tell', 'story'],
    // ['CreatePurchaseOrderStory', 'create', 'purchase-order'],
]);
