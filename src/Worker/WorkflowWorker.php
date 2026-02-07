<?php

namespace Hibernator\Worker;

use Hibernator\Persistence\WorkflowStoreInterface;
use Hibernator\Workflow\Orchestrator;
use Throwable;

class WorkflowWorker
{
    private WorkflowStoreInterface $store;
    private Orchestrator $orchestrator;
    private bool $running = true;

    public function __construct(WorkflowStoreInterface $store, Orchestrator $orchestrator)
    {
        $this->store = $store;
        $this->orchestrator = $orchestrator;
    }

    /**
     * Start the worker loop.
     *
     * @param int $intervalSeconds How often to poll (default 1 second)
     */
    public function start(int $intervalSeconds = 1): void
    {
        echo "Worker started. Polling for workflows...\n";

        while ($this->running) {
            try {
                $this->runOnce();
            } catch (Throwable $e) {
                echo "Worker encountered an error: " . $e->getMessage() . "\n";
            }

            // Simple sleep for polling interval
            sleep($intervalSeconds);
        }
    }

    /**
     * Run a single polling iteration.
     * Useful for testing without blocking loops.
     */
    public function runOnce(): void
    {
        $readyIds = $this->store->pollForReadyWorkflows();

        foreach ($readyIds as $id) {
            echo "Resuming workflow $id...\n";
            try {
                $this->orchestrator->run($id);
            } catch (Throwable $e) {
                echo "Failed to resume workflow $id: " . $e->getMessage() . "\n";
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }
}
