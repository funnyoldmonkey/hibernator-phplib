<?php

namespace Hibernator\Tests;

use Hibernator\Workflow\WorkflowContext;

class TrialWorkflow
{
    public function run()
    {
        $result1 = yield WorkflowContext::execute(new MockActivity('Signup'));

        yield WorkflowContext::wait('7 days');

        $result2 = yield WorkflowContext::execute(new MockActivity('Charge'));

        return "Done: $result1 -> $result2";
    }
}
