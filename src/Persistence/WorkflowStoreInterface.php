<?php

namespace Hibernator\Persistence;

interface WorkflowStoreInterface
{
    /**
     * Create a new workflow instance in the store.
     *
     * @param string $workflowId
     * @param string $workflowClass
     * @param array $args
     * @return void
     */
    public function createWorkflow(string $workflowId, string $workflowClass, array $args): void;

    /**
     * Load a workflow's current state.
     *
     * @param string $workflowId
     * @return array|null Returns ['status' => string, 'class' => string, 'args' => array, 'created_at' => string] or null if not found.
     */
    public function loadWorkflow(string $workflowId): ?array;

    /**
     * Save an event to the workflow history.
     *
     * @param string $workflowId
     * @param string $eventType
     * @param mixed $result
     * @return void
     */
    public function saveEvent(string $workflowId, string $eventType, mixed $result): void;

    /**
     * Retrieve the full history of a workflow.
     *
     * @param string $workflowId
     * @return array List of ['type' => string, 'result' => mixed, 'timestamp' => string]
     */
    public function getHistory(string $workflowId): array;

    /**
     * Find workflows that are sleeping and ready to be woken up.
     *
     * @return array List of workflow IDs
     */
    public function pollForReadyWorkflows(): array;

    /**
     * Update the status of a workflow (e.g., 'running', 'sleeping', 'completed', 'failed').
     *
     * @param string $workflowId
     * @param string $status
     * @param \DateTimeImmutable|null $wakeUpTime If transitioning to sleeping, when to wake up.
     * @return void
     */
    public function updateStatus(string $workflowId, string $status, ?\DateTimeImmutable $wakeUpTime = null): void;

    /**
     * Get the current time (allows for mocking).
     *
     * @return \DateTimeImmutable
     */
    public function getCurrentTime(): \DateTimeImmutable;
}
