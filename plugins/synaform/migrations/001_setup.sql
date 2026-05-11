-- Synaform Plugin Migration 001: Initial Setup
-- Run per-user when the plugin is installed.
-- Uses generic plugin_data table for candidate/form/template storage (non-invasive).
-- Placeholders: :userId, :group
--
-- NOTE: PluginManager runs this file via Connection::executeStatement() with
-- bound parameters, so the file must contain exactly ONE prepared statement.
-- Seeding the BCONFIG defaults as a single multi-row INSERT keeps that
-- contract while still being idempotent thanks to INSERT IGNORE.

INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES
    (:userId, :group, 'enabled',             '1'),
    (:userId, :group, 'default_language',    'de'),
    (:userId, :group, 'company_name',        ''),
    (:userId, :group, 'extraction_model',    'default'),
    (:userId, :group, 'validation_model',    'default'),
    (:userId, :group, 'default_template_id', '');
