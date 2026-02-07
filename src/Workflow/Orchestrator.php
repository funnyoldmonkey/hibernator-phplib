<?php

namespace Hibernator\Workflow;

use Fiber;
use DateTimeImmutable;
use Hibernator\Persistence\WorkflowStoreInterface;
use Hibernator\Activity\ActivityInterface;

class Orchestrator
{
    private WorkflowStoreInterface $store;

    public function __construct(WorkflowStoreInterface $store)
    {
        $this->store = $store;
    }

    public function run(string $workflowId): void
    {
        $state = $this->store->loadWorkflow($workflowId);
        if (!$state) {
            throw new \RuntimeException("Workflow $workflowId not found.");
        }

        // Handle Wake Up Logic
        if ($state['status'] === 'sleeping') {
            $this->store->saveEvent($workflowId, 'timer_completed', null);
            $this->store->updateStatus($workflowId, 'running');
        }

        $class = $state['class'];
        $args = $state['args'];
        $history = $this->store->getHistory($workflowId);

        if (!class_exists($class)) {
            throw new \RuntimeException("Workflow class $class not found.");
        }
        $workflowInstance = new $class(...$args);

        $fiber = new Fiber(function () use ($workflowInstance) {
            if (method_exists($workflowInstance, 'run')) {
                $result = $workflowInstance->run();
                if ($result instanceof \Generator) {
                    foreach ($result as $yieldedValue) {
                    }
                    return $result->getReturn();
                }
                return $result;
            }
            throw new \RuntimeException("Workflow class must implement run() method.");
        });

        $this->advance($fiber, $history, $workflowId);
    }

    private function advance(Fiber $fiber, array $history, string $workflowId): void
    {
        try {
            $value = $fiber->start();
        } catch (\Throwable $e) {
            $this->failWorkflow($workflowId, $e);
            return;
        }

        $historyIndex = 0;

        while ($fiber->isSuspended()) {
            if (!is_array($value) || !isset($value['type'])) {
                $value = $fiber->resume(null);
                continue;
            }

            $type = $value['type'];

            // CHECK HISTORY
            if (isset($history[$historyIndex])) {
                $historicalEvent = $history[$historyIndex];

                if ($historicalEvent['type'] !== $type . '_completed') {
                    throw new \RuntimeException("Non-deterministic workflow history detected at index $historyIndex. Expected {$historicalEvent['type']}, got $type.");
                }

                $result = $historicalEvent['result'];
                $historyIndex++;

                try {
                    $value = $fiber->resume($result);
                } catch (\Throwable $e) {
                    $this->failWorkflow($workflowId, $e);
                    return;
                }
                continue;
            }

            // NO HISTORY - NEW EXECUTION
            try {
                if ($type === 'activity') {
                    /** @var ActivityInterface $activity */
                    $activity = $value['activity'];

                    $result = $activity->handle();

                    $this->store->saveEvent($workflowId, 'activity_completed', $result);

                    $value = $fiber->resume($result);

                } elseif ($type === 'timer') {
                    $duration = $value['duration'];
                    // Use store time for testability
                    $wakeUpTime = $this->store->getCurrentTime()->modify($duration);

                    $this->store->updateStatus($workflowId, 'sleeping', $wakeUpTime);
                    return;

                } elseif ($type === 'side_effect') {
                    $result = ($value['callable'])();
                    $this->store->saveEvent($workflowId, 'side_effect_completed', $result);
                    $value = $fiber->resume($result);
                }
            } catch (\Throwable $e) {
                $this->failWorkflow($workflowId, $e);
                return;
            }
        }

        if ($fiber->isTerminated()) {
            $this->store->updateStatus($workflowId, 'completed');
        }
    }

    private function failWorkflow(string $workflowId, \Throwable $e): void
    {
        $this->store->updateStatus($workflowId, 'failed');
    }
}
