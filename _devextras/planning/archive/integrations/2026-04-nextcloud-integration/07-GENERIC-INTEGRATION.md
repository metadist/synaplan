# Step 7: Generic Integration Standards

To ensure "Synaplan OpenCloud" works across various platforms (Nextcloud, OwnCloud, Seafile, custom apps), we adhere to strict open standards.

## 1. Authentication Standard
- **Method:** API Key.
- **Header:** `X-API-Key`.
- **Why:** Simple, stateless, works with `curl`, easy to implement in any language.
- **Future:** OAuth2 support for "Login with Synaplan" flow (better for multi-user setups).

## 2. Context Passing (The "Context Protocol")
When opening Synaplan from an external app, we use standard URL parameters.

**Deep Link Pattern:**
`https://synaplan.example.com/chat/new?source={SOURCE}&context={CONTEXT_TYPE}&ref={REF_ID}&content={CONTENT_URI}`

| Parameter | Description | Example |
|-----------|-------------|---------|
| `source` | Identifier of the calling app | `nextcloud`, `vscode`, `chrome_ext` |
| `context` | Type of context provided | `file`, `selection`, `url` |
| `ref` | ID in the source system | `file_12345` |
| `filename` | Name of the file | `report.pdf` |
| `content` | (Optional) Raw text or public URL | `https://cloud.com/s/xyz` |

**Example:**
`https://synaplan.com/chat/new?source=nextcloud&context=file&filename=Q3_Report.pdf&ref=nc_file_99`

## 3. Frontend Integration (Web Components)
To allow embedding Synaplan UI into *any* web app (not just Nextcloud Vue), we will eventually offer **Web Components**.

**`synaplan-embed.js`**
```html
<synaplan-chat
  api-url="https://synaplan.com"
  api-key="sk_..."
  context-file="report.pdf"
  mode="sidebar"
></synaplan-chat>
```

**Strategy:**
1.  **Nextcloud MVP:** Native Vue components (tight integration).
2.  **OpenCloud:** Web Components (Custom Elements) that can be dropped into OwnCloud, WordPress, or any HTML page.

## 4. Data Interchange
- **Format:** JSON.
- **Streaming:** Server-Sent Events (SSE) for chat. Standard event format:
  ```
  event: data
  data: {"chunk": "Hello", "type": "text"}
  ```
- **Files:** Multipart/form-data for uploads.

## 5. "OpenCloud" Plugin Specification
Any plugin claiming "Synaplan OpenCloud" compatibility must support:
1.  **Settings:** URL + API Key configuration.
2.  **Actions:**
    - `Summarize` (Text-to-Text).
    - `Chat` (Text-stream).
3.  **Fallback:** Always offer a link to the full Synaplan Web UI.
