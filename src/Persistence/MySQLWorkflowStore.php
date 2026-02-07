<?php

namespace Hibernator\Persistence;

use PDO;
use RuntimeException;
use DateTimeImmutable;
use DateTimeInterface;

class MySQLWorkflowStore implements WorkflowStoreInterface
{
    private PDO $pdo;
    private string $tablePrefix;

    public function __construct(PDO $pdo, string $tablePrefix = 'hibernator_')
    {
        $this->pdo = $pdo;
        $this->tablePrefix = $tablePrefix;
    }

    public function createWorkflow(string $workflowId, string $workflowClass, array $args): void
    {
        $now = $this->getCurrentTime()->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->tablePrefix}workflows (id, class, args, status, created_at, updated_at)
            VALUES (:id, :class, :args, 'running', :created_at, :updated_at)
        ");

        $stmt->execute([
            ':id' => $workflowId,
            ':class' => $workflowClass,
            ':args' => json_encode($args),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    public function loadWorkflow(string $workflowId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM {$this->tablePrefix}workflows WHERE id = :id
        ");
        $stmt->execute([':id' => $workflowId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'status' => $row['status'],
            'class' => $row['class'],
            'args' => json_decode($row['args'], true),
            'created_at' => $row['created_at'],
            'wake_up_time' => $row['wake_up_time'] ?? null,
        ];
    }

    public function saveEvent(string $workflowId, string $eventType, mixed $result): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->tablePrefix}history (workflow_id, event_type, result, created_at)
            VALUES (:workflow_id, :event_type, :result, :created_at)
        ");

        $stmt->execute([
            ':workflow_id' => $workflowId,
            ':event_type' => $eventType,
            ':result' => json_encode($result),
            ':created_at' => $this->getCurrentTime()->format('Y-m-d H:i:s'),
        ]);
    }

    public function getHistory(string $workflowId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT event_type, result, created_at 
            FROM {$this->tablePrefix}history 
            WHERE workflow_id = :workflow_id 
            ORDER BY id ASC
        ");
        $stmt->execute([':workflow_id' => $workflowId]);

        $history = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $history[] = [
                'type' => $row['event_type'],
                'result' => json_decode($row['result'], true),
                'timestamp' => $row['created_at'],
            ];
        }

        return $history;
    }

    public function updateStatus(string $workflowId, string $status, ?DateTimeImmutable $wakeUpTime = null): void
    {
        $sql = "UPDATE {$this->tablePrefix}workflows SET status = :status, updated_at = :updated_at";
        $params = [
            ':status' => $status,
            ':id' => $workflowId,
            ':updated_at' => $this->getCurrentTime()->format('Y-m-d H:i:s'),
        ];

        if ($wakeUpTime) {
            $sql .= ", wake_up_time = :wake_up_time";
            $params[':wake_up_time'] = $wakeUpTime->format('Y-m-d H:i:s');
        } else {
            // If we are not sleeping, clear the wake_up_time
            if ($status !== 'sleeping') {
                $sql .= ", wake_up_time = NULL";
            }
        }

        $sql .= " WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function pollForReadyWorkflows(): array
    {
        $now = $this->getCurrentTime()->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            SELECT id FROM {$this->tablePrefix}workflows
            WHERE status = 'sleeping' 
            AND wake_up_time <= :now
            LIMIT 10
        ");
        $stmt->execute([':now' => $now]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getCurrentTime(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
