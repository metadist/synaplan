# Wireframe — People → Users

Flag `IAM.GROUPS_ENABLED` on. Operate → People.

```text
┌─────────────────────────────────────────────────────────────┐
│ People                                                      │
│ Users and groups on this instance.                          │
│                                                             │
│ [ Users ]  Groups                                           │
├─────────────────────────────────────────────────────────────┤
│ [ search by email…                              ] [refresh] │
│                                                             │
│ ID   Email              Level   Type   Groups   Sign-in  … │
│ #12  ada@example.com    PRO     WEB    Sales    OIDC     … │
│ #11  bob@example.com    NEW     WEB    —        —        … │
│                                                             │
│ Groups and Sign-in columns appear only on this page.        │
│ Impersonate / delete stay as on the old Users tab.          │
└─────────────────────────────────────────────────────────────┘
```

When the flag is off, this page is not in the nav. The Operate Users tab
keeps the old table (no extra columns).
