-- Synamail plugin: per-user settings. Profiles live in plugin_data.
-- Placeholders: :userId, :group

INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (:userId, :group, 'enabled', '1');

INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (:userId, :group, 'profile_language', 'auto');

INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (:userId, :group, 'max_summary_words', '150');
