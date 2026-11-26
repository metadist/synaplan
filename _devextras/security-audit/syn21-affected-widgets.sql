-- SYN-21: which widgets pass a visitor id to the owner's API?
-- Read-only (SELECT only). MariaDB. Run against the production database.
--
-- A widget is affected when a URL with {externalUserId} is fetched for it:
--   config_url : the widget's own "User Data Integration" URL
--   flow_url   : an API response in the flow rules of its task prompt
-- The backend only fetches either when the prompt has flow rules at all
-- (has_flow_rules = 1). Rows with has_flow_rules = 0 are configured but
-- dormant: nothing is fetched today, so nothing breaks for them.

-- 1) Totals
SELECT
    COUNT(*)                                             AS widgets_total,
    SUM(config_url)                                      AS with_config_url,
    SUM(flow_url)                                        AS with_flow_url,
    SUM((config_url OR flow_url) AND has_flow_rules)     AS affected_live,
    SUM(config_url AND NOT has_flow_rules)               AS configured_but_dormant,
    COUNT(DISTINCT IF((config_url OR flow_url) AND has_flow_rules, owner_id, NULL)) AS affected_owners
FROM (
    SELECT
        w.BID,
        w.BOWNERID AS owner_id,
        (w.BCONFIG LIKE '%{externalUserId}%')                         AS config_url,
        COALESCE(MAX(m.BMETAVALUE LIKE '%{externalUserId}%'), 0)      AS flow_url,
        COALESCE(MAX(m.BMETAVALUE IS NOT NULL AND m.BMETAVALUE <> ''), 0) AS has_flow_rules
    FROM BWIDGETS w
    LEFT JOIN BPROMPTS p
           ON p.BTOPIC = w.BTASKPROMPT
          AND p.BOWNERID IN (w.BOWNERID, 0)
    LEFT JOIN BPROMPTMETA m
           ON m.BPROMPTID = p.BID
          AND m.BMETAKEY = 'widgetFlowRules'
    GROUP BY w.BID, w.BOWNERID, w.BCONFIG
) t;

-- 2) The affected widgets, one row each, to contact their owners.
--    Contains e-mail addresses: keep the result internal.
SELECT
    w.BWIDGETID                                   AS widget_id,
    w.BNAME                                       AS widget_name,
    w.BSTATUS                                     AS status,
    u.BMAIL                                       AS owner_mail,
    u.BUSERLEVEL                                  AS owner_level,
    (w.BCONFIG LIKE '%{externalUserId}%')         AS config_url,
    COALESCE(MAX(m.BMETAVALUE LIKE '%{externalUserId}%'), 0) AS flow_url,
    COALESCE(MAX(m.BMETAVALUE IS NOT NULL AND m.BMETAVALUE <> ''), 0) AS has_flow_rules,
    JSON_UNQUOTE(JSON_EXTRACT(w.BCONFIG, '$.sessionMode')) AS session_mode,
    FROM_UNIXTIME(w.BUPDATED)                     AS last_updated
FROM BWIDGETS w
JOIN BUSER u ON u.BID = w.BOWNERID
LEFT JOIN BPROMPTS p
       ON p.BTOPIC = w.BTASKPROMPT
      AND p.BOWNERID IN (w.BOWNERID, 0)
LEFT JOIN BPROMPTMETA m
       ON m.BPROMPTID = p.BID
      AND m.BMETAKEY = 'widgetFlowRules'
GROUP BY w.BID, w.BWIDGETID, w.BNAME, w.BSTATUS, u.BMAIL, u.BUSERLEVEL, w.BCONFIG, w.BUPDATED
HAVING config_url = 1 OR flow_url = 1
ORDER BY has_flow_rules DESC, last_updated DESC;
