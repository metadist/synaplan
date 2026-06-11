-- Synamail Plugin Migration 001: Initial Setup
-- Run per-user when the plugin is installed.
-- Profiles live in the generic plugin_data table (non-invasive, no schema change).
-- Placeholders: :userId, :group

INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (:userId, :group, 'enabled', '1');

INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (:userId, :group, 'profile_language', 'auto');

INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (:userId, :group, 'max_summary_words', '150');
