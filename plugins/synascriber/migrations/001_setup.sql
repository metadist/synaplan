-- synaScriber migration 001, run per user on install.
-- Placeholders: :userId, :group (P_synascriber).

INSERT IGNORE INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
VALUES (:userId, :group, 'enabled', '1');
