-- BroGent plugin uses plugin_data table for storage
-- No plugin-specific tables needed

-- Data types used:
-- - device: Paired browser extension devices
-- - task: Task definitions (reusable automation recipes)
-- - run: Task execution instances
-- - pairing_code: Temporary pairing codes
-- - approval: Pending approval requests

-- Example device data structure:
-- {
--   "name": "Chrome on MacBook",
--   "platform": "macOS",
--   "browser": "chrome",
--   "extensionVersion": "0.1.0",
--   "token": "sk_dev_...",
--   "scopes": ["runs:claim", "runs:events"],
--   "lastSeen": "2026-02-01T18:00:00Z",
--   "createdAt": "2026-02-01T16:00:00Z"
-- }

-- Example run data structure:
-- {
--   "taskId": "task_01H...",
--   "deviceId": "dev_01H...",
--   "status": "running",
--   "inputs": {"to": "+49...", "message": "Hello"},
--   "leaseId": "lease_01H...",
--   "leaseExpiresAt": "2026-02-01T18:05:00Z",
--   "events": [...],
--   "artifacts": [...],
--   "createdAt": "2026-02-01T18:00:00Z"
-- }

SELECT 1; -- Placeholder for migration system
