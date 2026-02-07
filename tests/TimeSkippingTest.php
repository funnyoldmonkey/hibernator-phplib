<?php

namespace Hibernator\Tests;

use Hibernator\Persistence\MySQLWorkflowStore;
use Hibernator\Workflow\Orchestrator;
use Hibernator\Worker\WorkflowWorker;
use PHPUnit\Framework\TestCase;
use PDO;
use DateTimeImmutable;

class TimeSkippingTest extends TestCase
{
    private $pdo;
    private $store;
    private $orchestrator;
    private $worker;

    protected function setUp(): void
    {
        // Use SQLite in-memory for speed and isolation
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // SQLite schema adaptation
        $this->pdo->exec("
            CREATE TABLE hibernator_workflows (
                id TEXT PRIMARY KEY,
                class TEXT NOT NULL,
                args TEXT NOT NULL,
                status TEXT NOT NULL,
                wake_up_time TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
            CREATE TABLE hibernator_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                workflow_id TEXT NOT NULL,
                event_type TEXT NOT NULL,
                result TEXT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (workflow_id) REFERENCES hibernator_workflows(id) ON DELETE CASCADE
            );
        ");

        // Mock Store to control time
        $this->store = new class ($this->pdo) extends MySQLWorkflowStore {
            public string $mockTime = '2023-01-01 12:00:00';

            public function getCurrentTime(): DateTimeImmutable
            {
                return new DateTimeImmutable($this->mockTime);
            }
        };

        $this->orchestrator = new Orchestrator($this->store);
        $this->worker = new WorkflowWorker($this->store, $this->orchestrator);
    }

    public function testSevenDayWorkflowCompressed()
    {
        $workflowId = 'trial-subscription-123';

        // 1. Create Workflow
        $this->store->mockTime = '2023-01-01 12:00:00';
        $this->store->createWorkflow($workflowId, TrialWorkflow::class, []);

        // 2. Initial Run (Should execute 'Signup' and then Sleep)
        $this->orchestrator->run($workflowId);

        $state = $this->store->loadWorkflow($workflowId);
        $this->assertEquals('sleeping', $state['status']);
        $this->assertNotNull($state['wake_up_time']);
        $wakeUpTime = new \DateTimeImmutable($state['wake_up_time']);
        $this->assertEquals('2023-01-08', $wakeUpTime->format('Y-m-d')); // 7 days later

        // 3. Try to run worker immediately - should NOT find anything
        $this->worker->runOnce();
        $state = $this->store->loadWorkflow($workflowId);
        $this->assertEquals('sleeping', $state['status']); // Still sleeping

        // 4. FAST FORWARD TIME 7 DAYS + 1 second
        $this->store->mockTime = '2023-01-08 12:00:01';

        // 5. Run Worker - should wake up
        $this->worker->runOnce();

        // 6. Verify completion
        $state = $this->store->loadWorkflow($workflowId);
        $this->assertEquals('completed', $state['status']);

        // 7. Verify History (Replay happened, 'Charge' executed)
        $history = $this->store->getHistory($workflowId);
        // Events: activity_completed (Signup), timer_completed, activity_completed (Charge)
        // Wait! In Orchestrator::run() I add 'timer_completed'.
        // So history structure:
        // 1. activity_completed (Signup) [from first run]
        // 2. timer_completed [from wake-up]
        // 3. activity_completed (Charge) [from second run]

        $this->assertCount(3, $history);
        $this->assertEquals('activity_completed', $history[0]['type']);
        $this->assertEquals('Processed: Signup', $history[0]['result']);

        $this->assertEquals('timer_completed', $history[1]['type']);

        $this->assertEquals('activity_completed', $history[2]['type']);
        $this->assertEquals('Processed: Charge', $history[2]['result']);
    }
}
