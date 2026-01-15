-- SortX Plugin Migration 001: Initial Setup
-- This migration is run per-user when the plugin is activated
-- Placeholders: :userId, :group

-- Store plugin activation status
INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (:userId, :group, 'enabled', '1');

-- Store default settings
INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (:userId, :group, 'max_file_size_mb', '50');

INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (:userId, :group, 'rate_limit_per_hour', '100');
