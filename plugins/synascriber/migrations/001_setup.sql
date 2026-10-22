-- synaScriber migration 001, run per user on install.
-- Placeholders: :userId, :group (P_synascriber).
-- The first account that installs the plugin becomes its owner: the
-- transcriber's API key belongs to it and sessions are stored under it.

INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (:userId, :group, 'enabled', '1');

INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (0, :group, 'owner_user_id', :userId);
