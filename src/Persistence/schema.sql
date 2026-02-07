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
