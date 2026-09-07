# Wireframe — Connect an existing Synaplan account (track 6)

Journey **J-NC-1**. One generic confirm card for every partner client.

Banned in primary copy: token exchange, auth code, provisioning,
instance_secret.

## Nextcloud (personal settings)

```text
┌─ Synaplan ────────────────────────────────────────────────────────┐
│ Not connected                                                     │
│ Use your existing Synaplan account from Nextcloud.                │
│                                                                   │
│ [ Connect Synaplan ]                                              │
└───────────────────────────────────────────────────────────────────┘

after success:

┌─ Synaplan ────────────────────────────────────────────────────────┐
│ Connected as Ada · since 7 Sep                                    │
│ Files actions run as this account.                                │
│                                                                   │
│ [ Disconnect ]                                                    │
└───────────────────────────────────────────────────────────────────┘
```

Email already taken in `link` mode: "An account with this email
exists — connect it" + the same **Connect Synaplan** button. No
"provision failed" error.

## Synaplan confirm (`/connect/platform`)

```text
┌─────────────────────────────────────────────┐
│ Connect Nextcloud?                          │
│                                             │
│ Nextcloud at files.example.org              │
│ as jdoe                                     │
│                                             │
│ This lets Nextcloud use your Synaplan       │
│ account for chat, files and knowledge.      │
│                                             │
│ Signed in as ada@example.com                │
│ Not you? Sign out                           │
│                                             │
│ [ Cancel ]              [ Connect ]         │
└─────────────────────────────────────────────┘
```

Unknown `client`, failed state, or expired code: one sentence +
**Back to Nextcloud** (or "Close" for Outlook). Never a stack or
the `link_code`.

## Linked platforms (Manage → Connections)

```text
┌─ Linked platforms ────────────────────────────────────────────────┐
│ Nextcloud · files.example.org · jdoe · last used today            │
│                                                 [ Disconnect ]    │
└───────────────────────────────────────────────────────────────────┘
```

Empty: "Connect from Nextcloud (or ownCloud) settings — you will come
back here after you confirm. You do not type a key."
