<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Http\Request;
use ReflectionMethod;
use ReflectionNamedType;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\CarryFailures;
use Throwable;

/**
 * Story actions and the request.
 *
 *  - WARNING `actions.carry_failed`: an action that takes the request threw
 *    when a job was dispatched, where it runs to carry its actor to the
 *    worker. The dispatch went ahead and the job's publish took the actor
 *    it would otherwise have had. CarryFailures kept it; nothing else would.
 *  - WARNING `actions.request_helper`: an action that reads the request
 *    through `request()` or the facade without taking `Request`. Reflection
 *    can't see that, so it runs only when stories compile, with whatever
 *    request the container holds then, and never at a publish or in a job.
 *    A source scan, so it can be fooled; it only ever warns.
 */
class RequestActions extends Check
{
    /** `request(`, not `$request(` or `->request(`; the facade; the container. */
    private const HELPER = '/(?<![\w$>:])request\s*\(|\bRequest::|app\(\s*[\'"]request[\'"]/';

    public function name(): string
    {
        return 'actions';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        yield from $this->helpers($storyfeed);

        if (! $this->hasTable('meta')) {
            return;
        }

        foreach (CarryFailures::all() as $uses => $threw) {
            yield Finding::warning(
                'actions.carry_failed',
                "{$threw}. It runs when a job is dispatched during a request, to carry the actor it chooses to the "
                .'worker, so that job published with the actor it would otherwise have had. The dispatch went ahead.',
                ['action' => $uses],
            );
        }
    }

    /** @return iterable<Finding> */
    protected function helpers(StoryfeedManager $storyfeed): iterable
    {
        try {
            $actions = $storyfeed->storyActions();
        } catch (StoryMisconfigured) {
            return;
        }

        foreach (array_unique($actions) as $uses) {
            if (! $this->readsRequestUnseen($uses)) {
                continue;
            }

            yield Finding::warning(
                'actions.request_helper',
                "{$uses} reads the request without taking it, so it runs only when stories compile and never at a "
                .'publish or in a queued job. Take `Illuminate\Http\Request $request` as a parameter instead.',
                ['action' => $uses],
            );
        }
    }

    protected function readsRequestUnseen(string $uses): bool
    {
        try {
            $method = new ReflectionMethod(...explode('@', $uses, 2));

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && $type->getName() === Request::class) {
                    return false;
                }
            }

            $file = $method->getFileName();
            $start = $method->getStartLine();
            $end = $method->getEndLine();

            if ($file === false || $start === false || $end === false) {
                return false;
            }

            $lines = array_slice(file($file) ?: [], $start - 1, $end - $start + 1);

            return preg_match(self::HELPER, implode('', $lines)) === 1;
        } catch (Throwable) {
            return false;
        }
    }
}
