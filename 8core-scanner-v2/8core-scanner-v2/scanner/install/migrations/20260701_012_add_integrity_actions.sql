-- Add action queue for integrity trash/replace operations that require elevated permissions
CREATE TABLE IF NOT EXISTS scanner_integrity_actions (
    id            INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    result_id     INT          NOT NULL,
    action        VARCHAR(50)  NOT NULL,
    status        VARCHAR(50)  NOT NULL DEFAULT 'pending',
    source_path   VARCHAR(1024) NOT NULL,
    target_path   VARCHAR(1024) NOT NULL,
    relative_path VARCHAR(1024) NOT NULL,
    requested_by  VARCHAR(190) NULL,
    error_message TEXT         NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    executed_at   DATETIME     NULL,
    INDEX idx_sia_status    (status),
    INDEX idx_sia_result_id (result_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Allow pending_action as a valid result status
ALTER TABLE scanner_integrity_results
    MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'new';
