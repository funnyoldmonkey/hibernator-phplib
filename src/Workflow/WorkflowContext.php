<?php

namespace Hibernator\Workflow;

use Fiber;
use Hibernator\Activity\ActivityInterface;

class WorkflowContext
{
    /**
     * Request execution of an activity.
     * Usage: $result = yield WorkflowContext::execute(new MyActivity($args));
     *
     * @param ActivityInterface $activity
     * @return mixed
     */
    public static function execute(ActivityInterface $activity): mixed
    {
        return Fiber::suspend(['type' => 'activity', 'activity' => $activity]);
    }

    /**
     * Pause execution for a specified duration.
     * Usage: yield WorkflowContext::wait('1 hour');
     *
     * @param string $duration PHP date/time duration string (e.g., "1 day", "30 minutes")
     * @return void
     */
    public static function wait(string $duration): void
    {
        Fiber::suspend(['type' => 'timer', 'duration' => $duration]);
    }

    /**
     * Get the current side effect (non-deterministic) result.
     * Useful for things like generic UUID generation that need to be replay-safe.
     *
     * @param callable $callable
     * @return mixed
     */
    public static function sideEffect(callable $callable): mixed
    {
        return Fiber::suspend(['type' => 'side_effect', 'callable' => $callable]);
    }
}
