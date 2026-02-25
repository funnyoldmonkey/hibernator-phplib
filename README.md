# Hibernator 💤

**Write PHP code that sleeps for months, survives server crashes, and wakes up exactly where it left off.**

Hibernator is a **Durable Execution Engine** for PHP 8.2+. It eliminates the complexity of queues, cron jobs, and state machines by allowing you to write long-running business processes as simple, linear code.

## 🌟 The Problem: "Cron & Queue Spaghetti"

Modern applications are full of multi-step processes that span hours, days, or even months:
*   *User signs up -> Wait 3 days -> Send email -> Wait 7 days -> Check subscription.*
*   *Order placed -> Charge card -> Wait for shipping -> Update inventory.*

Traditionally, you implement this by fragmenting your logic into:
1.  **Database Columns:** `status = 'WAITING_FOR_EMAIL'`, `next_check = '2025-01-01'`.
2.  **Cron Jobs:** Scripts that poll the DB every minute: `SELECT * FROM users WHERE next_check < NOW()`.
3.  **Queue Workers:** Jobs that handle one tiny slice of logic and then dispatch the next job.

**The result?** Your business logic is scattered across 10 different files, 3 different infrastructure pieces, and it's impossible to read the flow of the program.

## ⚡ The Solution: Hibernator

Hibernator lets you write the entire flow in **one single PHP file**. 

```php
// The entire user onboarding flow in one function
public function run(string $email) {
    // Step 1: Send welcome
    yield WorkflowContext::execute(new SendEmail($email, 'Welcome!'));

    // Step 2: Sleep for 3 days (This actually stops CPU usage!)
    // The state is saved to the DB. The process exits.
    yield WorkflowContext::wait('3 days');

    // Step 3: Wake up automatically and continue
    // Even if you redeployed your server 50 times in between.
    yield WorkflowContext::execute(new SendEmail($email, 'How is it going?'));
    
    // Step 4: Check billing
    $isPaid = yield WorkflowContext::execute(new CheckBilling($email));
    
    if ($isPaid) {
        yield WorkflowContext::execute(new GrantPremiumAccess($email));
    }
}
```

### How is this possible?
Hibernator uses **PHP Fibers** to suspend execution and the **Replay Pattern** (Event Sourcing) to restore state.
1.  When you `yield wait`, Hibernator saves your execution history to the database and kills the process. Zero resources are used.
2.  When the time is up, a Worker boots up the workflow.
3.  It **replays** previous steps instantly from the database history (skipping the actual work) to restore variables and memory state.
4.  It continues executing from the exact line where it left off.

---

## 📋 Requirements

-   **PHP 8.2+** (Native Fiber support required)
-   `ext-pdo` (For database connectivity)
-   **MySQL/MariaDB** (Default store implementation)

## 📦 Installation

Install via Composer:

```bash
composer require hibernator/hibernator-phplib
```

---

## 🚀 Quick Start Guide

### Step 1: Database Setup

You need to create the tables to store standard workflow state and event history.
Run the following SQL in your database:

```sql
CREATE TABLE IF NOT EXISTS hibernator_workflows (
    id VARCHAR(255) PRIMARY KEY,
    class VARCHAR(255) NOT NULL,
    args JSON NOT NULL,
    status VARCHAR(50) NOT NULL, -- running, sleeping, completed, failed
    wake_up_time DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS hibernator_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    workflow_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    result JSON NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_workflow_id (workflow_id),
    FOREIGN KEY (workflow_id) REFERENCES hibernator_workflows(id) ON DELETE CASCADE
);
```

### Step 2: Create an Activity

Activities are the building blocks of your workflow. They represent **side effects** (e.g., sending an email, charging a card, writing to a file).
They must implement `Hibernator\Activity\ActivityInterface`.

**Important:** Activities should be idempotent if possible, as they might be retried in failure scenarios (though the Replay Pattern generally prevents re-execution of successful activities).

```php
namespace App\Activity;

use Hibernator\Activity\ActivityInterface;

class SendWelcomeEmail implements ActivityInterface {
    public function __construct(public string $email, public string $name) {}

    public function handle(): mixed {
        // Your logic here (e.g., Mail::send(...))
        echo "Sending email to {$this->email}...\n";
        return "sent_success";
    }
}
```

### Step 3: Create a Workflow

Workflows define the **flow** of logic. They are deterministic code that orchestrates your activities.

-   Use `yield WorkflowContext::execute($activity)` to run an activity.
-   Use `yield WorkflowContext::wait($duration)` to sleep.

```php
namespace App\Workflow;

use Hibernator\Workflow\WorkflowContext;
use App\Activity\SendWelcomeEmail;
use App\Activity\CheckPaymentStatus;

class OnboardingWorkflow {
    public function __construct(public string $userId, public string $email) {}

    public function run() {
        // Step 1: Send Welcome Email
        yield WorkflowContext::execute(new SendWelcomeEmail($this->email, 'User'));

        // Step 2: Wait for 3 days
        yield WorkflowContext::wait('3 days');

        // Step 3: Check if they paid
        $status = yield WorkflowContext::execute(new CheckPaymentStatus($this->userId));

        if ($status === 'paid') {
            return "Onboarding Complete";
        } else {
            return "User Churned";
        }
    }
}
```

### Step 4: Run a Workflow (The Orchestrator)

To start a new workflow, you need the `Orchestrator`. This is the engine that manages the Fibers.

```php
use Hibernator\Persistence\MySQLWorkflowStore;
use Hibernator\Workflow\Orchestrator;
use App\Workflow\OnboardingWorkflow;

// 1. Setup the Store
$pdo = new PDO('mysql:host=127.0.0.1;dbname=your_db', 'user', 'pass');
$store = new MySQLWorkflowStore($pdo);

// 2. Setup the Orchestrator
$orchestrator = new Orchestrator($store);

// 3. Run the Workflow
$workflowId = 'onboarding-' . uniqid();
$orchestrator->run($workflowId, OnboardingWorkflow::class, [
    'userId' => 'user_123', 
    'email' => 'test@example.com'
]);

echo "Workflow started: $workflowId\n";
```

### Step 5: Run the Worker (The "Heartbeat")

When a workflow sleeps (e.g., `wait('3 days')`), it saves its state to the database and stops executing.
To wake it up when the time comes, you need a **Worker** process running in the background.

```php
// worker.php
use Hibernator\Worker\WorkflowWorker;

// ... setup $store and $orchestrator as above ...

$worker = new WorkflowWorker($store, $orchestrator);

echo "Worker started. Press Ctrl+C to stop.\n";
$worker->start(intervalSeconds: 5); 
```

Run this script in a supervisor process or a terminal:
```bash
php worker.php
```

---

## 📚 API Reference

### `Hibernator\Workflow\WorkflowContext`

The static API used inside your Workflow classes.

#### `execute(ActivityInterface $activity): mixed`
Yields execution to the orchestrator to run an activity.
-   **Returns**: The result of the activity's `handle()` method.
-   **Behavior**: If the activity was already run in history, returns the cached result.

#### `wait(string $duration): void`
Pauses the workflow for a specified duration.
-   **$duration**: A string understandable by PHP's `DateTime::modify` (e.g., `"+1 day"`, `"30 minutes"`).

#### `sideEffect(callable $callable): mixed`
Executes a non-deterministic snippet of code (like generating a random ID) and checkpoints the result so it is deterministic on replay.

```php
$uuid = yield WorkflowContext::sideEffect(fn() => uniqid());
```

---

## ⚙️ How It Works (Under the Hood)

Hibernator uses the **Event Sourcing** / **Replay Pattern**. 

1.  **Run 1**: You start the workflow. It runs until it hits an Activity. It executes the Activity, saves the result to the `history` table, and continues.
2.  **Sleep**: It hits a `wait()` command. It saves a "timer started" state and updates the workflow status to `sleeping`. The PHP process exits.
3.  **Wake Up**: The Worker sees the `wake_up_time` has passed. It calls `Orchestrator->run()`.
4.  **Replay**: The workflow starts **from the beginning**. 
    -   It hits the first Activity. It sees in `history` that it's already done. It **skips** execution and returns the saved result.
    -   It hits the `wait()`. It sees the timer is done. It continues.
    -   It executes the *next* step (new code).

## Testing

Hibernator is designed for testing. You can easily test 7-day workflows in 1 second by mocking time.

See `tests/TimeSkippingTest.php` in the repository for a full example of how to use a fake time source to verify your long-running logic instantly.

## License

MIT License. Free to use for personal and commercial projects.
