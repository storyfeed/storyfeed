<?php

namespace Storyfeed\Body;

use Storyfeed\Body\Concerns\HasContent;
use Storyfeed\Exceptions\IncompleteFeedValue;
use Storyfeed\FeedBody;
use Storyfeed\FeedLink;

/**
 * A short heading, a sentence or two, and one action.
 *
 *     body: CallToAction::make(subject: 'The countdown to 1.0', content: 'Five milestones to a stable release.')
 *         ->action('See the roadmap', FeedLink::to(route('roadmap'))->modal())
 *
 * The anatomy of an action panel, and of Laravel's notification mail, whose
 * `MailMessage::action($text, $url)` this copies: the text is the call to
 * action's own, and where it goes is a {@see FeedLink}, so `modal()` and
 * `attributes()` work as they do anywhere else. A string is a plain link, and
 * `FeedLink::toEntity()` goes to the entity this body belongs to.
 *
 * ## The action's text is a verb, and that is right here
 *
 * A {@see FeedLink}'s label names a thing and never says what to do, because
 * a title that is an instruction reads as a button the moment two rows carry
 * one. This body is the place for the instruction: "See the roadmap" is the
 * point of it. So the text belongs to the action, not to the link, and the
 * link carries none.
 *
 * ## Named `CallToAction`, not `Action`
 *
 * `->action('verb', $entity)` records a verb on the activity builder, and a
 * Story class has actions. A third meaning of the word in one codebase would
 * be one too many.
 *
 * ## One action, and no variant
 *
 * `subject` and `content` are optional; the smallest call to action is a lone
 * action. A renderer chooses its look, a button or a "→" link, and draws
 * every call to action the same way: the payload has no variant. There is no
 * toggle and no input either, because a feed row does not change state.
 */
class CallToAction extends FeedBody
{
    use HasContent;

    protected ?string $subject = null;

    protected ?string $actionText = null;

    protected ?FeedLink $actionLink = null;

    /**
     * Start a call to action. Every argument is optional and has a method of
     * the same name; the action must be set before the body is used.
     *
     * @param  string|null  $subject  a short heading
     * @param  mixed  $content  a sentence or two, as plain text
     */
    protected function __construct(?string $subject = null, mixed $content = null)
    {
        $this->subject($subject)->content($content);
    }

    /** A short heading. */
    public function subject(?string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * The one action: its text, a verb, and where it goes, as Laravel's
     * `MailMessage::action($text, $url)`. A string is a plain link.
     */
    public function action(string $text, FeedLink|string $link): static
    {
        $this->actionText = $text;
        $this->actionLink = is_string($link) ? FeedLink::to($link) : $link;

        return $this;
    }

    /** `Storyfeed/Body/CallToAction` — the vocabulary's name, as every core body's is. */
    public static function bodyType(): string
    {
        return 'Storyfeed/Body/CallToAction';
    }

    public static function upgrade(array $payload, int $from): array
    {
        // Total by contract: a row that is missing its action, or whose link is
        // malformed, still renders its heading and text, with no action.
        $action = is_array($payload['action'] ?? null) ? $payload['action'] : [];
        $text = $action['label'] ?? null;
        $link = FeedLink::from($action['link'] ?? null);

        return [
            'subject' => is_string($payload['subject'] ?? null) ? $payload['subject'] : null,
            'content' => is_string($payload['content'] ?? null) ? $payload['content'] : null,
            'action' => is_string($text) && $text !== '' && $link !== null
                ? ['label' => $text, 'link' => self::link($link)]
                : null,
        ];
    }

    protected function body(): array
    {
        // Not required(): its message offers `action:` to make(), and the
        // action is two values set by one method.
        $text = $this->actionText;
        $link = $this->actionLink;

        if ($text === null || $link === null) {
            throw new IncompleteFeedValue(class_basename(static::class).' has no action. Call ->action($text, $link) on it.');
        }

        return [
            'subject' => $this->subject,
            'content' => $this->content,
            'action' => ['label' => $text, 'link' => self::link($link)],
        ];
    }

    protected static function defaults(): array
    {
        return ['subject' => null, 'content' => null];
    }

    /** The heading, else the action's text: one line that still says what the row offers. */
    protected function defaultFallback(): ?string
    {
        return $this->subject ?? $this->actionText;
    }

    /**
     * The action's link without a label: the text is the action's.
     *
     * @return array{href: string|null, modal: bool, attributes: array<string, mixed>}
     */
    private static function link(FeedLink $link): array
    {
        $payload = $link->toPayload();

        return ['href' => $payload['href'], 'modal' => $payload['modal'], 'attributes' => $payload['attributes']];
    }
}
