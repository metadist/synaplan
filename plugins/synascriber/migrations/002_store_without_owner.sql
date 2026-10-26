-- synaScriber migration 002, run per user on install; idempotent.
-- Placeholders: :userId, :group (P_synascriber).
-- Up to 0.3.0 sessions were stored under the plugin owner's account
-- (setting owner_user_id). They belong to the installation (user id 0)
-- now; once the setting is gone this file changes nothing.

UPDATE IGNORE plugin_data
SET user_id = 0
WHERE plugin_name = 'synascriber'
  AND user_id = (
    SELECT CAST(BVALUE AS UNSIGNED) FROM BCONFIG
    WHERE BOWNERID = 0 AND BGROUP = :group AND BSETTING = 'owner_user_id'
  )
  AND user_id > 0;

DELETE FROM BCONFIG
WHERE BOWNERID = 0 AND BGROUP = :group AND BSETTING = 'owner_user_id';
