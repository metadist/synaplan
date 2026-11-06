-- Synafastbill Plugin Migration 001: Initial Setup
-- Runs per-user when the plugin is installed.
-- Seeds non-secret defaults into BCONFIG (group P_synafastbill).
-- FastBill credentials are NOT stored here; they live in plugin_data
-- (type=config, key=settings) so the API key can be masked/omitted.
-- Placeholders: :userId, :group

INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (:userId, :group, 'enabled', '1');

INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (:userId, :group, 'default_language', 'en');

INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (:userId, :group, 'command_model', 'default');

INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (:userId, :group, 'default_currency', 'EUR');

INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (:userId, :group, 'default_vat_percent', '19');

INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (:userId, :group, 'default_unit', 'hours');
