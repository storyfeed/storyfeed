<?php

use Storyfeed\Body\CallToAction;
use Storyfeed\Body\Component;
use Storyfeed\Body\Concerns\HasTitle;
use Storyfeed\Body\Excerpt;
use Storyfeed\Body\FileAttachment;
use Storyfeed\Body\Image;
use Storyfeed\Body\ItemList;
use Storyfeed\Body\KeyValue;
use Storyfeed\Body\MediaObject;
use Storyfeed\Body\Prose;
use Storyfeed\Body\Table;
use Storyfeed\Contracts\FeedBody as FeedBodyContract;
use Storyfeed\Exceptions\IncompleteFeedValue;
use Storyfeed\FeedBody;

class ShipmentBody extends FeedBody
{
    use HasTitle;

    protected ?string $carrier = null;

    protected bool $insured = false;

    protected function __construct(?string $carrier = null, ?string $title = null)
    {
        $this->carrier($carrier)->title($title);
    }

    public function carrier(?string $carrier): static
    {
        $this->carrier = $carrier;

        return $this;
    }

    public function insured(bool $insured = true): static
    {
        $this->insured = $insured;

        return $this;
    }

    public static function bodyType(): string
    {
        return 'Acme/Shipment';
    }

    protected function body(): array
    {
        return ['carrier' => $this->required($this->carrier, 'carrier'), 'insured' => $this->insured, 'title' => $this->title];
    }

    protected static function defaults(): array
    {
        return ['insured' => false, 'title' => null];
    }

    protected function defaultFallback(): ?string
    {
        return $this->title;
    }
}

it('is the base every body type core ships extends', function () {
    foreach ([Component::class, Excerpt::class, FileAttachment::class, Image::class, ItemList::class, KeyValue::class, MediaObject::class, Prose::class, Table::class, CallToAction::class] as $class) {
        expect(is_subclass_of($class, FeedBody::class))->toBeTrue()
            ->and(is_subclass_of($class, FeedBodyContract::class))->toBeTrue();
    }

    expect((new ReflectionMethod(FeedBody::class, 'toPayload'))->isFinal())->toBeTrue();
});

it('builds an app body from make(), with named arguments reaching the same body as the chain', function () {
    expect(ShipmentBody::make(title: 'Order #1042', carrier: 'UPS')->toPayload())
        ->toBe(ShipmentBody::make()->carrier('UPS')->title('Order #1042')->toPayload())
        ->and(ShipmentBody::make('UPS')->toPayload())
        ->toBe(['$body' => 'Acme/Shipment', '$v' => 1, 'carrier' => 'UPS']);
});

it('leaves defaults out of the payload and fills them back in through the default upgrade()', function () {
    $body = ShipmentBody::make('UPS')->insured();

    expect($body->toPayload())->toBe(['$body' => 'Acme/Shipment', '$v' => 1, 'carrier' => 'UPS', 'insured' => true])
        ->and(rendered(ShipmentBody::make('UPS')))->toBe(['insured' => false, 'title' => null, 'carrier' => 'UPS'])
        ->and(ShipmentBody::version())->toBe(1);
});

it('names the method to call when a required value is missing', function () {
    expect(fn () => ShipmentBody::make()->toPayload())
        ->toThrow(IncompleteFeedValue::class, 'ShipmentBody has no carrier. Call ->carrier(…) on it, or pass carrier: to ShipmentBody::make().');
});

it('carries a fallback line in the reserved key, given or derived', function () {
    expect(FeedBodyContract::FALLBACK)->toBe('$fallback')
        ->and(Excerpt::make('A')->toPayload())->not->toHaveKey('$fallback')
        ->and(Excerpt::make('A')->fallback('Jasper quoted the contract')->toPayload())
        ->toBe(['$body' => Excerpt::bodyType(), '$v' => 2, '$fallback' => 'Jasper quoted the contract', 'text' => 'A'])
        ->and(ShipmentBody::make('UPS', 'Order #1042')->toPayload()['$fallback'])->toBe('Order #1042')
        ->and(ShipmentBody::make('UPS', 'Order #1042')->fallback('Shipped')->toPayload()['$fallback'])->toBe('Shipped')
        ->and(Excerpt::make('A')->fallback('')->toPayload())->not->toHaveKey('$fallback');
});

it('supports tap(), when() and unless()', function () {
    $tapped = null;

    $body = ShipmentBody::make('UPS')
        ->tap(function (ShipmentBody $body) use (&$tapped) {
            $tapped = $body;
        })
        ->when(true, fn (ShipmentBody $body) => $body->insured())
        ->unless(true, fn (ShipmentBody $body) => $body->title('Hidden'));

    expect($tapped)->toBe($body)
        ->and($body->toPayload())->toBe(['$body' => 'Acme/Shipment', '$v' => 1, 'carrier' => 'UPS', 'insured' => true]);
});

it('reads shared fields back through their getters', function () {
    expect(KeyValue::make(title: 'Order')->getTitle())->toBe('Order')
        ->and(Prose::make('Text')->getContent())->toBe('Text')
        ->and(MediaObject::make(footnote: 'Approved')->withIcon()->getImage()?->value)->toBe('icon')
        ->and(MediaObject::make(footnote: 'Approved')->getFootnote())->toBe('Approved')
        ->and(MediaObject::make()->getFiles())->toBe([]);
});

it('sets a maximum height in the $meta bucket, written only when set', function () {
    expect(FeedBodyContract::META)->toBe('$meta')
        ->and(Prose::markdown('notes')->toPayload())->not->toHaveKey('$meta')
        ->and(Prose::markdown('notes')->maxHeight('none')->toPayload())
        ->toBe(['$body' => Prose::bodyType(), '$v' => 2, '$meta' => ['maxHeight' => 'none'], 'content' => 'notes', 'mediaType' => 'text/markdown'])
        ->and(Table::make(rows: [['a']])->maxHeight('16rem')->toPayload()['$meta'])->toBe(['maxHeight' => '16rem'])
        ->and(ShipmentBody::make('UPS')->maxHeight('320px')->maxHeight(null)->toPayload())->not->toHaveKey('$meta')
        ->and(rendered(Prose::make('x')->maxHeight('none')))->not->toHaveKey('$meta');

    foreach (['0', '16rem', '320px', '12.5em', '.5vh', '40%', '30dvh', '20ch'] as $height) {
        expect(Excerpt::make('A')->maxHeight($height)->toPayload()['$meta']['maxHeight'])->toBe($height);
    }
});

it('merges app keys into $meta with withMeta(), as Nova does', function () {
    $body = Prose::markdown('notes')
        ->withMeta(['acme.layout' => 'wide', 'acme.tone' => 'quiet'])
        ->maxHeight('none')
        ->withMeta(['acme.layout' => 'narrow']);

    expect($body->toPayload()['$meta'])->toBe(['acme.layout' => 'narrow', 'acme.tone' => 'quiet', 'maxHeight' => 'none'])
        ->and($body->meta())->toBe($body->toPayload()['$meta'])
        ->and(Excerpt::make('A')->withMeta(['maxHeight' => '16rem'])->toPayload()['$meta'])->toBe(['maxHeight' => '16rem'])
        ->and(Excerpt::make('A')->maxHeight('16rem')->withMeta(['maxHeight' => null])->toPayload())->not->toHaveKey('$meta')
        ->and(Excerpt::make('A')->withMeta(['acme.x' => 1])->fallback('Quoted')->toPayload())
        ->toBe(['$body' => Excerpt::bodyType(), '$v' => 2, '$fallback' => 'Quoted', '$meta' => ['acme.x' => 1], 'text' => 'A']);
});

it('validates a core key given through withMeta() as its typed method does', function () {
    expect(fn () => Prose::make('x')->withMeta(['maxHeight' => 'auto']))
        ->toThrow(InvalidArgumentException::class, 'Prose::maxHeight() takes a CSS length such as `16rem` or `320px`, or `none`; `auto` given.')
        ->and(fn () => Prose::make('x')->withMeta(['maxHeight' => 320]))
        ->toThrow(InvalidArgumentException::class, 'Prose::maxHeight() takes a CSS length such as `16rem` or `320px`, or `none`; int given.');
});

it('names the method when a maximum height is not a CSS length', function (string $height) {
    expect(fn () => Prose::make('x')->maxHeight($height))
        ->toThrow(InvalidArgumentException::class, "Prose::maxHeight() takes a CSS length such as `16rem` or `320px`, or `none`; `{$height}` given.");
})->with(['16', '-4rem', 'auto', 'calc(100% - 1rem)', '', ' 16rem', '16 rem', '10px; color: red']);
