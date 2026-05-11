const TX_VERSION = "v3.1.0";

export default {
  mount(el, context) {
    const { userId, apiBaseUrl, pluginBaseUrl } = context;
    const BASE = `${apiBaseUrl}/api/v1/user/${userId}/plugins/synaform`;
    const ASSET_BASE = pluginBaseUrl;

    // =========================================================================
    // State
    // =========================================================================

    let t = {};
    let _loadedLang = "";
    let _langPollTimer = null;
    let _pendingTemplateFile = null;

    const state = {
      loading: true,
      error: null,
      config: {},
      forms: [],
      templates: [],
      datasets: [],

      // Route
      view: "collections",
      collectionId: null,
      datasetId: null,
      tab: "overview",

      // Page-level UI state
      collectionsSearch: "",
      newCollectionOpen: false,
      editingCollection: null,
      showCollectionHelp: false,
      showVariablesHelp: false,
      showTemplatesHelp: false,
      showExportHelp: false,

      // Dataset editing state
      datasetsSearch: "",
      datasetsSortNewest: true,
      datasetsPage: 0,
      selectedDataset: null,
      newDatasetOpen: false,
      datasetExtracting: false,
      datasetGenerating: false,
      datasetParsing: false,
      datasetParseStep: 0,
      datasetParseStartedAt: null,
      datasetParseElapsedSec: 0,
      datasetParseFileCount: 0,
      datasetParseHasImages: false,
      datasetParseTimer: null,
      datasetVariables: null,
      datasetVariablesLoading: false,
      selectedGenerateTemplate: null,
      editingVarKey: null,
      reorderingFields: false,
      urlFormOpen: false,
      urlAdding: false,
      urlAddError: null,

      // Live preview
      previewVisible: true,
      previewTemplateId: null,
      previewSkeleton: null,
      previewLoading: false,
      previewError: null,
      previewDebounceTimer: null,
      previewPdfUrl: null,
      previewPdfLoading: false,
      previewPdfError: null,
      previewPdfUnavailable: false,

      // Variables editing state
      variablesDraft: null,
      variablesDirty: false,
      variablesImportOpen: false,
      variablesImportParsing: false,
      variablesImportFields: null,
      variablesImportError: null,
      variablesImportText: "",
      expandedDesignerIdx: null,

      // Import from Target Template state
      variablesTplImportOpen: false,
      variablesTplImportTemplateId: null,
      variablesTplImportLoading: false,
      variablesTplImportError: null,
      variablesTplImportResult: null,
      variablesTplImportSelection: {},

      // Template management state
      selectedTemplateDetail: null,
      templateMatchOpen: false,
      templateMatchAddKey: null,

      // Export state
      exportStatus: "",
      exportFrom: "",
      exportTo: "",
      exportSearch: "",

      // Danger zone
      dangerConfirmText: "",

      // GDPR / data retention banner state — when truthy, renderDatasetsTab
      // suppresses the overdue banner for the current session even if datasets
      // remain past their expiry date. The user explicitly clicked Cancel.
      retentionBannerDismissed: false,
    };

    // =========================================================================
    // i18n
    // =========================================================================

    function T(key, fallback) {
      const parts = key.split(".");
      let val = t;
      for (const p of parts) {
        val = val?.[p];
        if (val === undefined) return fallback || key;
      }
      return val;
    }

    function Tf(key, vars = {}) {
      let out = T(key);
      for (const [k, v] of Object.entries(vars)) {
        out = out.replace(new RegExp(`\\{${k}\\}`, "g"), String(v));
      }
      return out;
    }

    async function loadTranslations(force) {
      const lang = localStorage.getItem("language") || "en";
      if (!force && lang === _loadedLang && Object.keys(t).length > 0)
        return false;
      const cb = `?v=${TX_VERSION}`;
      try {
        const res = await fetch(`${ASSET_BASE}/i18n/${lang}.json${cb}`);
        if (res.ok) {
          t = await res.json();
          _loadedLang = lang;
          return true;
        }
      } catch (_) {
        /* fallthrough */
      }
      if (lang !== "en") {
        try {
          const res = await fetch(`${ASSET_BASE}/i18n/en.json${cb}`);
          if (res.ok) {
            t = await res.json();
            _loadedLang = "en";
            return true;
          }
        } catch (_) {
          /* no translations available */
        }
      }
      return false;
    }

    function startLanguageWatcher() {
      if (_langPollTimer) return;
      _langPollTimer = setInterval(async () => {
        const current = localStorage.getItem("language") || "en";
        if (current !== _loadedLang) {
          const changed = await loadTranslations();
          if (changed && !state.loading) render();
        }
      }, 500);
    }

    // =========================================================================
    // API helpers
    // =========================================================================

    let _refreshPromise = null;

    async function refreshAccessToken() {
      if (_refreshPromise) return _refreshPromise;
      _refreshPromise = (async () => {
        try {
          const res = await fetch(`${apiBaseUrl}/api/v1/auth/refresh`, {
            method: "POST",
            credentials: "include",
          });
          return res.ok;
        } catch {
          return false;
        } finally {
          _refreshPromise = null;
        }
      })();
      return _refreshPromise;
    }

    async function api(path, opts = {}) {
      const doFetch = () =>
        fetch(`${BASE}${path}`, {
          credentials: "include",
          headers: { "Content-Type": "application/json", ...opts.headers },
          ...opts,
        });
      let res = await doFetch();
      if (res.status === 401) {
        const refreshed = await refreshAccessToken();
        if (refreshed) res = await doFetch();
      }
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error || `HTTP ${res.status}`);
      }
      return res.json();
    }

    async function apiUpload(path, file, extraFields = {}) {
      const doFetch = () => {
        const fd = new FormData();
        fd.append("file", file);
        for (const [k, v] of Object.entries(extraFields)) fd.append(k, v);
        return fetch(`${BASE}${path}`, {
          method: "POST",
          credentials: "include",
          body: fd,
        });
      };
      let res = await doFetch();
      if (res.status === 401) {
        const refreshed = await refreshAccessToken();
        if (refreshed) res = await doFetch();
      }
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error || `HTTP ${res.status}`);
      }
      return res.json();
    }

    // =========================================================================
    // Utilities
    // =========================================================================

    function escHtml(s) {
      return String(s ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
    }

    function formatDate(d) {
      if (!d) return "—";
      try {
        return new Date(d).toLocaleDateString(undefined, {
          year: "numeric",
          month: "short",
          day: "numeric",
        });
      } catch (_) {
        return d;
      }
    }

    function statusBadge(status) {
      const colors = {
        draft: "bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300",
        extracted:
          "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400",
        reviewed:
          "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400",
        generated:
          "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400",
        incomplete:
          "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400",
      };
      const label = T(`datasets.status_${status}`, status);
      const cls = colors[status] || colors.draft;
      return `<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}">${escHtml(label)}</span>`;
    }

    function datasetDisplayName(d) {
      const fv = d.field_values || {};
      const nameFromFields =
        fv.firstname || fv.lastname
          ? `${fv.firstname || ""} ${fv.lastname || ""}`.trim()
          : null;
      return nameFromFields || d.name || fv.fullname || `#${d.id}`;
    }

    function datasetHasDoc(d) {
      return (
        d.files?.cv || (d.files?.additional && d.files.additional.length > 0)
      );
    }

    function pluralize(n, singular, plural) {
      return n === 1 ? singular : plural;
    }

    function showToast(msg, type = "success") {
      const bg = type === "error" ? "#fef2f2" : "#f0fdf4";
      const color = type === "error" ? "#991b1b" : "#166534";
      const border = type === "error" ? "#fecaca" : "#bbf7d0";
      const div = document.createElement("div");
      div.style.cssText = `position:fixed;top:16px;right:16px;z-index:9999;background:${bg};color:${color};border:1px solid ${border};padding:10px 18px;border-radius:8px;font-size:14px;box-shadow:0 4px 12px rgba(0,0,0,.15);transition:opacity .3s;`;
      div.textContent = msg;
      document.body.appendChild(div);
      setTimeout(() => {
        div.style.opacity = "0";
        setTimeout(() => div.remove(), 300);
      }, 2700);
    }

    function collectionById(id) {
      return state.forms.find((f) => f.id == id) || null;
    }

    function datasetsForCollection(formId) {
      return state.datasets.filter((d) => d.form_id == formId);
    }

    function collectionTemplates(collection) {
      const ids = collection?.template_ids || [];
      return state.templates.filter((t) => ids.includes(t.id));
    }

    // =========================================================================
    // Live preview (Phase 4b)
    // =========================================================================

    /**
     * Collect the current value map from the rendered dataset form PLUS the
     * baseline from saved field_values and the last AI-extracted variables.
     * Priority: form input > AI-extracted value > saved field_values.
     */
    function buildPreviewValueMap(c, d) {
      const values = {};

      for (const [k, v] of Object.entries(d?.field_values || {})) {
        values[k] = v;
      }
      const extracted =
        (state.datasetVariables && state.datasetVariables.variables) || {};
      for (const [k, ent] of Object.entries(extracted)) {
        if (
          ent &&
          ent.value !== undefined &&
          ent.value !== null &&
          ent.value !== ""
        ) {
          values[k] = ent.value;
        }
      }

      const form = el?.querySelector("#tx-entry-data-form");
      if (form) {
        for (const f of c.fields || []) {
          if (f.type === "table") {
            const cols = f.columns || [];
            const rows = [];
            let ri = 0;
            while (
              form.querySelector(`[name="${f.key}__${ri}__${cols[0]?.key}"]`)
            ) {
              const row = {};
              for (const col of cols) {
                const cell = form.querySelector(
                  `[name="${f.key}__${ri}__${col.key}"]`,
                );
                const raw = cell?.value ?? "";
                if ((col.type || "text") === "list") {
                  row[col.key] = raw
                    .split(/\r?\n/)
                    .map((s) => s.trim())
                    .filter(Boolean);
                } else {
                  row[col.key] = raw;
                }
              }
              rows.push(row);
              ri++;
            }
            values[f.key] = rows;
            continue;
          }
          const input = form.querySelector(`[name="${f.key}"]`);
          if (!input) continue;
          if (f.type === "checkbox") values[f.key] = input.checked;
          else if (f.type === "list")
            values[f.key] = input.value
              .split("\n")
              .map((s) => s.trim())
              .filter(Boolean);
          else values[f.key] = input.value;
        }
      }

      return values;
    }

    /**
     * Resolve a single template placeholder key against the value map.
     * Understands: lists (joined by new lines), checkboxes (as glyphs for
     * checkb.X.yes / checkb.X.no, as "Ja"/"Nein" for plain checkbox keys),
     * and the {group}.{col} two-segment pattern used in row-template cells.
     */
    function resolvePlaceholderValue(key, values, c, rowCtx) {
      if (rowCtx && rowCtx.group) {
        const parts = key.split(".");
        if (parts.length === 2 && parts[0] === rowCtx.group && rowCtx.row) {
          const v = rowCtx.row[parts[1]];
          return v === undefined || v === null ? "" : String(v);
        }
      }

      // checkb.KEY.yes / checkb.KEY.no pair
      const mYes = /^checkb\.(.+)\.yes$/.exec(key);
      const mNo = /^checkb\.(.+)\.no$/.exec(key);
      if (mYes || mNo) {
        const base = mYes ? mYes[1] : mNo[1];
        const raw = values[base];
        const yes =
          raw === true ||
          String(raw).toLowerCase() === "true" ||
          String(raw).toLowerCase() === "ja" ||
          String(raw).toLowerCase() === "yes" ||
          raw === "on";
        const field = (c.fields || []).find((f) => f.key === base);
        const designer = (field && field.designer) || {};
        const on = designer.checked_glyph || "\u2612";
        const off = designer.unchecked_glyph || "\u2610";
        if (mYes) return yes ? on : off;
        return !yes ? on : off;
      }

      const field = (c.fields || []).find((f) => f.key === key);
      const raw = values[key];

      if (field && field.type === "list") {
        if (Array.isArray(raw)) return raw.filter(Boolean).join("\n");
        return raw || "";
      }
      if (field && field.type === "checkbox") {
        const yes =
          raw === true ||
          String(raw).toLowerCase() === "true" ||
          String(raw).toLowerCase() === "ja";
        return yes ? "Ja" : "Nein";
      }
      if (raw === undefined || raw === null) return "";
      if (Array.isArray(raw)) return raw.join(", ");
      if (typeof raw === "object") return JSON.stringify(raw);
      return String(raw);
    }

    /**
     * Rewrite the HTML skeleton in #tx-preview-root to reflect the current
     * value map. Runs entirely on the client — no server call.
     *
     * Repeating rows (data-tx-row-template="X") are cloned once per dataset
     * row, with each clone's [data-tx-key] filled from that row's column
     * values. The original template row is hidden; if there are no rows yet,
     * the original stays visible so the user sees placeholders.
     */
    function applyPreviewValues(rootEl, c, values) {
      if (!rootEl) return;

      // Pass 1: row-template expansion
      rootEl.querySelectorAll("tr[data-tx-row-template]").forEach((tmpl) => {
        const grp = tmpl.dataset.txRowTemplate;
        const parent = tmpl.parentElement;
        if (!parent) return;

        // Remove any previous clones (they carry data-tx-row-clone=group).
        parent
          .querySelectorAll(`tr[data-tx-row-clone="${grp}"]`)
          .forEach((n) => n.remove());

        const rows = values[grp];
        if (!Array.isArray(rows) || rows.length === 0) {
          tmpl.style.display = "";
          return;
        }
        tmpl.style.display = "none";

        // Resolve the table variable's columns, so we can tell which cell is a
        // list-typed column and needs to render as bullets inside the preview.
        const tableField = (c.fields || []).find(
          (f) => f.type === "table" && f.key === grp,
        );
        const columnTypeByKey = {};
        for (const col of tableField?.columns || []) {
          columnTypeByKey[col.key] = col.type || "text";
        }

        let insertAfter = tmpl;
        rows.forEach((row) => {
          const clone = tmpl.cloneNode(true);
          clone.removeAttribute("data-tx-row-template");
          clone.setAttribute("data-tx-row-clone", grp);
          clone.style.display = "";
          clone.querySelectorAll(".tx-ph[data-tx-key]").forEach((span) => {
            const parts = span.dataset.txKey.split(".");
            const colKey = parts.length === 2 ? parts[1] : null;
            const colType = colKey ? columnTypeByKey[colKey] : null;

            // List-typed cells render as a proper <ul> so the preview matches
            // the final .docx (one bullet per item).
            if (colType === "list" && colKey) {
              const raw = row[colKey];
              const items = Array.isArray(raw)
                ? raw
                : typeof raw === "string"
                  ? raw
                      .split(/\r?\n/)
                      .map((s) => s.trim())
                      .filter(Boolean)
                  : [];
              span.classList.remove("tx-empty-val", "tx-cb-on", "tx-cb-off");
              if (items.length === 0) {
                span.textContent = "(—)";
                span.classList.add("tx-empty-val");
                return;
              }
              span.classList.add("tx-filled");
              span.innerHTML = `<ul class="tx-preview-bullets">${items
                .map((it) => `<li>${escHtml(String(it))}</li>`)
                .join("")}</ul>`;
              return;
            }

            const val = resolvePlaceholderValue(span.dataset.txKey, values, c, {
              group: grp,
              row,
            });
            fillPlaceholderSpan(span, val);
          });
          parent.insertBefore(clone, insertAfter.nextSibling);
          insertAfter = clone;
        });
      });

      // Pass 2: every non-row placeholder not inside a template/clone
      rootEl.querySelectorAll(".tx-ph[data-tx-key]").forEach((span) => {
        if (span.closest("tr[data-tx-row-template]")) return;
        if (span.closest("tr[data-tx-row-clone]")) return;
        const raw = span.dataset.txRaw || span.dataset.txKey;
        const key = span.dataset.txKey;
        const field = (c.fields || []).find((f) => f.key === key);

        // Image-typed placeholders get an actual <img> tag so the preview looks
        // like the final document.
        if (field && field.type === "image") {
          fillImagePlaceholderSpan(span, values[key], field, raw);
          return;
        }

        const isCb = /^checkb\./.test(raw);
        const val = resolvePlaceholderValue(key, values, c, null);
        fillPlaceholderSpan(span, val, { rawKey: raw, isCheckbox: isCb });
      });
    }

    function fillImagePlaceholderSpan(span, meta, field, rawKey) {
      const d = state.selectedDataset;
      span.classList.remove(
        "tx-filled",
        "tx-empty-val",
        "tx-cb-on",
        "tx-cb-off",
      );
      if (!meta || typeof meta !== "object" || !meta.mime || !d) {
        span.textContent = "{{" + rawKey + "}}";
        span.classList.add("tx-empty-val");
        span.title = T("preview.missing_value");
        return;
      }
      const url = `${BASE}/candidates/${d.id}/image/${encodeURIComponent(field.key)}?v=${encodeURIComponent(meta.uploaded_at || "")}`;
      const w = (field.designer && field.designer.width) || 140;
      const h = (field.designer && field.designer.height) || 180;
      span.innerHTML = `<img src="${escHtml(url)}" alt="${escHtml(field.label || field.key)}" style="width:${w}px;height:${h}px;object-fit:cover;display:inline-block" />`;
      span.classList.add("tx-filled");
      span.title = meta.original_name || "";
    }

    function fillPlaceholderSpan(span, val, opts) {
      const rawKey =
        (opts && opts.rawKey) || span.dataset.txRaw || span.dataset.txKey;
      const isCb = opts && opts.isCheckbox;

      span.classList.remove(
        "tx-filled",
        "tx-empty-val",
        "tx-cb-on",
        "tx-cb-off",
      );

      if (val === undefined || val === null || val === "") {
        span.textContent = "{{" + rawKey + "}}";
        span.classList.add("tx-empty-val");
        span.title = T("preview.missing_value");
        return;
      }

      span.textContent = val;
      span.title = "";
      if (isCb) {
        // checked glyph vs unchecked
        if (val === "\u2612" || /^(x|y|yes|true|ja)$/i.test(val)) {
          span.classList.add("tx-cb-on");
        } else {
          span.classList.add("tx-cb-off");
        }
      } else {
        span.classList.add("tx-filled");
      }
    }

    function updatePreviewNow() {
      if (!state.selectedDataset) return;
      const c = collectionById(state.collectionId);
      if (!c) return;
      const rootEl = el?.querySelector("#tx-preview-root");
      if (!rootEl) return;
      const values = buildPreviewValueMap(c, state.selectedDataset);
      applyPreviewValues(rootEl, c, values);
    }

    function schedulePreviewUpdate() {
      if (state.previewDebounceTimer) clearTimeout(state.previewDebounceTimer);
      state.previewDebounceTimer = setTimeout(() => {
        state.previewDebounceTimer = null;
        updatePreviewNow();
      }, 150);
    }

    async function loadPreviewSkeleton(templateId, opts) {
      if (!templateId) return;
      const force = !!(opts && opts.force);

      // De-dupe: if a fetch for the same template is already in flight, skip.
      if (state.previewLoading && state.previewTemplateId === templateId)
        return;

      // Don't loop on a known failure for this template unless the caller explicitly
      // asked to retry (refresh button, template change).
      if (
        !force &&
        state.previewError &&
        state.previewTemplateId === templateId
      )
        return;

      state.previewTemplateId = templateId;
      state.previewLoading = true;
      state.previewError = null;
      state.previewSkeleton = null;
      render();
      try {
        await refreshAccessToken();
        const res = await api(
          `/templates/${encodeURIComponent(templateId)}/preview-html`,
        );
        state.previewSkeleton = {
          html: res.html || "",
          row_groups: res.row_groups || [],
          placeholders: res.placeholders || [],
          schema: res.schema_version || 0,
        };
      } catch (err) {
        state.previewError = err.message || String(err);
      }
      state.previewLoading = false;
      render();
      // After render, prime the preview with current values
      setTimeout(updatePreviewNow, 0);
    }

    // =========================================================================
    // Router
    // =========================================================================

    const VALID_TABS = [
      "overview",
      "variables",
      "templates",
      "datasets",
      "export",
      "danger",
    ];

    function parseHash() {
      const raw = window.location.hash.replace(/^#tx-?/, "");
      if (!raw) return { view: "collections" };
      const parts = raw.split("/");
      if (parts[0] === "settings") return { view: "settings" };
      if (parts[0] === "c" && parts[1]) {
        const res = { view: "collection", collectionId: parts[1] };
        if (parts[2] && VALID_TABS.includes(parts[2])) res.tab = parts[2];
        else res.tab = "overview";
        if (parts[2] === "datasets" && parts[3]) res.datasetId = parts[3];
        return res;
      }
      return { view: "collections" };
    }

    function applyHash() {
      const r = parseHash();
      state.view = r.view;
      state.collectionId = r.collectionId || null;
      state.tab = r.tab || "overview";
      state.datasetId = r.datasetId || null;
    }

    function writeHash() {
      let h = "#tx-";
      if (state.view === "settings") h += "settings";
      else if (state.view === "collection" && state.collectionId) {
        h += `c/${state.collectionId}/${state.tab || "overview"}`;
        if (state.tab === "datasets" && state.datasetId)
          h += `/${state.datasetId}`;
      } else h = "#tx-";
      if (window.location.hash !== h) window.location.hash = h;
    }

    async function navigate(updates) {
      Object.assign(state, updates);
      // Reset transient state per view
      if (updates.view && updates.view !== "collection") {
        state.collectionId = null;
        state.datasetId = null;
        state.selectedDataset = null;
        state.newDatasetOpen = false;
        state.newCollectionOpen = false;
      }
      if (updates.view === "collection" || updates.tab) {
        state.selectedDataset = null;
        state.datasetId = updates.datasetId || null;
        state.datasetVariables = null;
        state.datasetExtracting = false;
        state.datasetGenerating = false;
        state.datasetParsing = false;
        state.datasetParseStep = 0;
        state.editingVarKey = null;
        state.reorderingFields = false;
        state.selectedGenerateTemplate = null;
        state.variablesDraft = null;
        state.variablesDirty = false;
        state.variablesImportOpen = false;
        state.variablesImportFields = null;
        state.variablesImportError = null;
        state.variablesImportText = "";
        state.selectedTemplateDetail = null;
        state.templateMatchOpen = false;
        state.templateMatchAddKey = null;
        state.dangerConfirmText = "";
      }
      writeHash();
      await loadTranslations();
      render();
      await loadData();
      if (
        state.view === "collection" &&
        state.tab === "datasets" &&
        state.datasetId
      ) {
        await selectDataset(state.datasetId);
      }
    }

    window.addEventListener("hashchange", () => {
      const prevView = state.view;
      const prevCollection = state.collectionId;
      const prevTab = state.tab;
      const prevDataset = state.datasetId;
      applyHash();
      if (
        prevView !== state.view ||
        prevCollection !== state.collectionId ||
        prevTab !== state.tab ||
        prevDataset !== state.datasetId
      ) {
        navigate({});
      }
    });

    // =========================================================================
    // Icons
    // =========================================================================

    const ICONS = {
      folder:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/></svg>',
      folderOpen:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v1H3V7zm0 3h18l-1.5 7a2 2 0 01-1.97 1.5H6.47A2 2 0 014.5 17L3 10z"/></svg>',
      variable:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v6m0 4v6M6 8v8M18 8v8M4 12h16"/></svg>',
      file: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
      database:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3" stroke-width="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5v6a9 3 0 0018 0V5M3 11v6a9 3 0 0018 0v-6"/></svg>',
      export:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m-4-4l4 4 4-4"/></svg>',
      warning:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>',
      gear: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
      plus: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>',
      upload:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>',
      trash:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>',
      download:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>',
      check:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>',
      back: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>',
      sparkle:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"/></svg>',
      doc: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>',
      edit: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>',
      info: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
      chevDown:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>',
      chevRight:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>',
      search:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>',
      sortDown:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4 4m0 0l4-4m-4 4V4"/></svg>',
      sortUp:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h9m5-4v12m0-12l4-4m-4 4l-4-4"/></svg>',
      arrowUp:
        '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>',
      arrowDown:
        '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>',
      grip: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 6h.01M12 6h.01M8 12h.01M12 12h.01M8 18h.01M12 18h.01"/></svg>',
      question:
        '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093M12 17h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
      close:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>',
      link: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>',
      refresh:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>',
      clock:
        '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    };

    // =========================================================================
    // Styles
    // =========================================================================

    const TX_STYLES = `
      .tx { color: var(--txt-primary); }
      .tx-secondary { color: var(--txt-secondary); }
      .tx-card { background: var(--bg-card); border-radius: .75rem; box-shadow: inset 0 0 0 1px var(--border-light), 0 1px 2px rgba(0,0,0,.04); }
      .tx-row { background: var(--bg-card); box-shadow: inset 0 0 0 1px var(--border-light); border-radius: .5rem; }
      .tx-row:hover { box-shadow: inset 0 0 0 1px var(--brand-alpha-light), 0 2px 6px rgba(0,0,0,.06); }
      .tx-input, .tx-select, .tx-textarea { background: var(--bg-app); color: var(--txt-primary); border: 1px solid var(--divider); border-radius: .375rem; padding: .5rem .75rem; font-size: .875rem; outline: none; width: 100%; }
      .tx-input:focus, .tx-select:focus, .tx-textarea:focus { border-color: var(--brand); box-shadow: 0 0 0 2px var(--brand-alpha-light); }
      .tx-input::placeholder, .tx-textarea::placeholder { color: var(--txt-secondary); opacity: .6; }
      .tx-btn { background: var(--brand); color: #fff; border: none; border-radius: .375rem; padding: .5rem 1rem; font-size: .875rem; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; gap: .375rem; transition: background .15s; }
      .tx-btn:hover { background: var(--brand-hover); }
      .tx-btn:disabled { opacity: .5; cursor: not-allowed; }
      .tx-btn-sm { padding: .375rem .75rem; font-size: .8125rem; }
      .tx-btn-danger { background: var(--status-error); }
      .tx-btn-danger:hover { opacity: .85; }
      .tx-btn-ghost { background: transparent; color: var(--brand); box-shadow: inset 0 0 0 1px var(--divider); }
      .tx-btn-ghost:hover { background: var(--brand-alpha-light); }
      .tx-btn-link { background: transparent; color: var(--brand); padding: 0; border: none; text-decoration: none; }
      .tx-btn-link:hover { text-decoration: underline; }
      .tx-link { color: var(--brand); cursor: pointer; }
      .tx-link:hover { text-decoration: underline; }
      .tx-label { display: block; font-size: .8125rem; font-weight: 500; color: var(--txt-primary); margin-bottom: .25rem; }
      .tx-hint { font-size: .75rem; color: var(--txt-secondary); margin-top: .375rem; line-height: 1.4; }
      .tx-badge { display: inline-flex; align-items: center; padding: .125rem .5rem; border-radius: .25rem; font-size: .75rem; font-weight: 500; }
      .tx-render-badge { display: inline-flex; align-items: center; gap: .25rem; padding: .125rem .5rem; border-radius: .375rem; font-size: .6875rem; font-weight: 500; white-space: nowrap; border: 1px solid transparent; }
      .tx-render-badge > span[aria-hidden="true"] { font-size: .875rem; line-height: 1; }
      .tx-badge-list  { background: color-mix(in srgb, var(--brand) 10%, transparent); color: var(--brand); border-color: color-mix(in srgb, var(--brand) 25%, transparent); }
      .tx-badge-check { background: color-mix(in srgb, var(--status-success, #10b981) 12%, transparent); color: var(--status-success, #10b981); border-color: color-mix(in srgb, var(--status-success, #10b981) 30%, transparent); }
      .tx-badge-table { background: color-mix(in srgb, var(--status-warning, #f59e0b) 12%, transparent); color: var(--status-warning, #f59e0b); border-color: color-mix(in srgb, var(--status-warning, #f59e0b) 30%, transparent); }
      .tx-badge-image { background: color-mix(in srgb, var(--status-info, #3b82f6) 12%, transparent); color: var(--status-info, #3b82f6); border-color: color-mix(in srgb, var(--status-info, #3b82f6) 30%, transparent); }
      .tx-badge-plain { background: var(--bg-chip); color: var(--txt-secondary); border-color: var(--divider); }
      .tx-divider { border-color: var(--divider); }
      .tx-drop { border: 2px dashed var(--divider); border-radius: .5rem; background: var(--bg-card); text-align: center; padding: 1.25rem; cursor: pointer; transition: border-color .15s, background .15s; }
      .tx-drop:hover { border-color: var(--brand); background: var(--brand-alpha-light); }
      .tx-help-trigger { display: inline-flex; align-items: center; justify-content: center; width: 1.1rem; height: 1.1rem; border-radius: 9999px; color: var(--txt-secondary); cursor: pointer; border: none; background: transparent; padding: 0; }
      .tx-help-trigger:hover { color: var(--brand); background: var(--brand-alpha-light); }
      .tx-modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 9998; display: flex; align-items: center; justify-content: center; padding: 1rem; }
      .tx-modal { background: var(--bg-card); border-radius: .75rem; box-shadow: 0 20px 40px rgba(0,0,0,.2); max-width: 560px; width: 100%; max-height: 90vh; overflow-y: auto; }
      .tx-tab { display: inline-flex; align-items: center; gap: .5rem; padding: .625rem 1rem; font-size: .875rem; font-weight: 500; white-space: nowrap; transition: color .15s, border-color .15s; border-bottom: 2px solid transparent; color: var(--txt-secondary); cursor: pointer; background: transparent; border-left: none; border-right: none; border-top: none; }
      .tx-tab:hover { color: var(--txt-primary); }
      .tx-tab.active { color: var(--brand); border-bottom-color: var(--brand); }
      .tx-tab.danger { color: var(--status-error); opacity: .85; }
      .tx-tab.danger.active { border-bottom-color: var(--status-error); }
      .tx-collection-card { position: relative; padding: 1.25rem; background: var(--bg-card); color: var(--txt-primary); border: none; border-radius: .75rem; box-shadow: inset 0 0 0 1px var(--border-light); transition: box-shadow .15s, transform .15s; cursor: pointer; display: flex; flex-direction: column; gap: .75rem; width: 100%; font: inherit; text-align: left; }
      .tx-collection-card:hover { box-shadow: inset 0 0 0 1px var(--brand-alpha-light), 0 6px 16px rgba(0,0,0,.08); transform: translateY(-1px); }
      .tx-collection-card:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
      .tx-callout { padding: .75rem 1rem; border-radius: .5rem; background: var(--brand-alpha-light); color: var(--txt-primary); font-size: .8125rem; }
      .tx-danger-zone { border: 1px solid color-mix(in srgb, var(--status-error) 40%, transparent); border-radius: .75rem; padding: 1.25rem; background: color-mix(in srgb, var(--status-error) 6%, var(--bg-card)); }

      /* Live preview (Phase 4b) */
      .tx-split { display: grid; gap: 1rem; grid-template-columns: 1fr; }
      @media (min-width: 1100px) { .tx-split.has-preview { grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr); align-items: start; } }
      .tx-preview-panel { position: sticky; top: 1rem; max-height: calc(100vh - 2rem); overflow: hidden; display: flex; flex-direction: column; }
      .tx-preview-panel .tx-preview-toolbar { display: flex; align-items: center; gap: .5rem; padding: .5rem .75rem; border-bottom: 1px solid var(--divider); background: var(--bg-card); }
      .tx-preview-panel .tx-preview-viewport { flex: 1; overflow: auto; padding: 1.25rem; background: #ffffff; color: #1a1a1a; border-radius: 0 0 .75rem .75rem; }
      .tx-preview { font-family: "Calibri", "Helvetica Neue", Arial, sans-serif; font-size: 11pt; line-height: 1.35; max-width: 780px; margin: 0 auto; color: #1a1a1a; }
      .tx-preview h1 { font-size: 18pt; font-weight: 700; margin: .6em 0 .3em; color: #1a1a1a; }
      .tx-preview h2 { font-size: 14pt; font-weight: 700; margin: .6em 0 .3em; }
      .tx-preview h3 { font-size: 12pt; font-weight: 700; margin: .6em 0 .3em; }
      .tx-preview p { margin: 0 0 .4em; }
      .tx-preview p.tx-empty { margin: .2em 0; min-height: 1em; }
      .tx-preview p.tx-li { margin-left: 1.25em; position: relative; }
      .tx-preview p.tx-li::before { content: "\\2022"; position: absolute; left: -1em; color: #555; }
      .tx-preview table.tx-tbl { border-collapse: collapse; width: 100%; margin: .3em 0 .6em; }
      .tx-preview table.tx-tbl td { border: 1px solid #e0e0e0; padding: .25em .5em; vertical-align: top; }
      .tx-preview .tx-b { font-weight: 700; }
      .tx-preview .tx-i { font-style: italic; }
      .tx-preview .tx-u { text-decoration: underline; }
      .tx-preview .tx-ph { background: rgba(255, 221, 89, .45); padding: 0 .1em; border-radius: 2px; transition: background .15s; }
      .tx-preview .tx-ph.tx-filled { background: rgba(77, 200, 142, .18); }
      .tx-preview .tx-ph.tx-empty-val { background: rgba(220, 53, 69, .12); color: #a22; font-style: italic; }
      .tx-preview .tx-ph.tx-cb-on { background: transparent; color: #1a1a1a; font-weight: 700; }
      .tx-preview .tx-ph.tx-cb-off { background: transparent; color: #777; }
      .tx-preview ul.tx-preview-bullets { list-style: disc; margin: 0; padding-left: 1.1em; }
      .tx-preview ul.tx-preview-bullets li { margin: 0 0 .15em; }
      .tx-preview-missing { padding: 2rem; text-align: center; color: var(--txt-secondary); font-size: .875rem; }
    `;

    // =========================================================================
    // Render engine
    // =========================================================================

    function render() {
      el.innerHTML = `
        <style>${TX_STYLES}</style>
        <div class="tx max-w-6xl mx-auto px-4 py-6">
          ${renderHeader()}
          ${renderNav()}
          <div id="tx-content" class="mt-4">${renderView()}</div>
        </div>`;
      bindEvents();
    }

    function renderHeader() {
      return `<div class="flex items-start justify-between mb-1">
        <div>
          <h1 class="text-2xl font-bold tracking-tight">${T("app.title")}</h1>
          <p class="text-xs tx-secondary mt-0.5">${T("app.tagline")}</p>
        </div>
        <span class="text-xs text-gray-400 font-mono">${TX_VERSION}</span>
      </div>`;
    }

    function renderNav() {
      const items = [
        { id: "collections", icon: ICONS.folder, label: T("nav.collections") },
        { id: "settings", icon: ICONS.gear, label: T("nav.settings") },
      ];
      const activeId = state.view === "collection" ? "collections" : state.view;
      return `<nav class="flex gap-1 overflow-x-auto" style="border-bottom:1px solid var(--divider)">
        ${items
          .map((item) => {
            const active = item.id === activeId;
            const style = active
              ? "border-bottom:2px solid var(--brand);color:var(--brand)"
              : "border-bottom:2px solid transparent;color:var(--txt-secondary)";
            return `<button data-nav="${item.id}" class="flex items-center gap-1.5 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors" style="${style}">${item.icon} ${item.label}</button>`;
          })
          .join("")}
      </nav>`;
    }

    function renderView() {
      if (state.loading) return renderLoading();
      if (state.error) return renderError(state.error);
      switch (state.view) {
        case "settings":
          return renderSettings();
        case "collection":
          return renderCollection();
        case "collections":
        default:
          return renderCollectionsList();
      }
    }

    function renderLoading() {
      return `<div class="flex items-center justify-center py-12">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500"></div>
        <span class="ml-3 text-gray-500 dark:text-gray-400">${T("app.loading")}</span>
      </div>`;
    }

    function renderError(msg) {
      return `<div class="rounded-lg border border-red-200 bg-red-50 dark:bg-red-900/20 dark:border-red-800 p-4 mt-4">
        <p class="text-red-600 dark:text-red-400">${T("app.error")}: ${escHtml(msg)}</p>
      </div>`;
    }

    function helpTrigger(topicKey) {
      return `<button type="button" data-help="${topicKey}" class="tx-help-trigger" title="${T("app.help")}">${ICONS.question}</button>`;
    }

    function renderHelpModal() {
      const openTopics = {
        collections: state.showCollectionHelp,
        variables: state.showVariablesHelp,
        templates: state.showTemplatesHelp,
        export: state.showExportHelp,
      };
      const active = Object.keys(openTopics).find((k) => openTopics[k]);
      if (!active) return "";
      const titleKey =
        active === "collections"
          ? "collections.help_what_is_title"
          : `${active}.help_title`;
      const bodyKey =
        active === "collections"
          ? "collections.help_what_is_body"
          : `${active}.help_body`;
      const body = T(bodyKey).replace(/\n/g, "<br>");
      return `<div class="tx-modal-bg" data-action="close-help">
        <div class="tx-modal p-6" onclick="event.stopPropagation()">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-lg font-semibold">${T(titleKey)}</h3>
            <button data-action="close-help" class="tx-help-trigger">${ICONS.close}</button>
          </div>
          <p class="text-sm leading-relaxed tx-secondary">${body}</p>
        </div>
      </div>`;
    }

    // =========================================================================
    // Collections list view
    // =========================================================================

    function renderCollectionsList() {
      const q = state.collectionsSearch.trim().toLowerCase();
      const list = (state.forms || []).filter((f) => {
        if (!q) return true;
        return (
          f.name?.toLowerCase().includes(q) ||
          f.description?.toLowerCase().includes(q)
        );
      });

      const cards = list
        .map((c) => {
          const datasets = datasetsForCollection(c.id);
          const templates = collectionTemplates(c);
          const vars = c.fields?.length || 0;
          return `<button type="button" data-open-collection="${c.id}" class="tx-collection-card text-left" aria-label="${T("collections.card_open")}: ${escHtml(c.name)}">
            <div class="flex items-start gap-3">
              <span class="inline-flex items-center justify-center w-9 h-9 rounded-full flex-shrink-0" style="background:var(--brand-alpha-light);color:var(--brand)">${ICONS.folder}</span>
              <div class="min-w-0 flex-1">
                <div class="font-semibold text-base truncate">${escHtml(c.name)}</div>
                ${
                  c.description
                    ? `<p class="text-xs tx-secondary mt-0.5 line-clamp-2">${escHtml(c.description)}</p>`
                    : ""
                }
              </div>
            </div>
            <div class="flex items-center gap-4 text-xs tx-secondary">
              <span class="inline-flex items-center gap-1">${ICONS.variable} <span>${vars} ${pluralize(vars, T("collections.card_variables_one"), T("collections.card_variables"))}</span></span>
              <span class="inline-flex items-center gap-1">${ICONS.file} <span>${templates.length} ${pluralize(templates.length, T("collections.card_templates_one"), T("collections.card_templates"))}</span></span>
              <span class="inline-flex items-center gap-1">${ICONS.database} <span>${datasets.length} ${pluralize(datasets.length, T("collections.card_datasets_one"), T("collections.card_datasets"))}</span></span>
            </div>
            <div class="flex items-center justify-between pt-2" style="border-top:1px solid var(--divider)">
              <span class="text-xs tx-secondary">${formatDate(c.created_at)}</span>
              <span class="text-sm tx-link font-medium">${T("collections.card_open")} →</span>
            </div>
          </button>`;
        })
        .join("");

      const emptyHelp = `<div class="tx-card p-6 text-center">
        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full mb-3" style="background:var(--bg-chip);color:var(--txt-secondary)">${ICONS.folder}</div>
        <h3 class="font-semibold text-lg mb-1">${T("collections.empty")}</h3>
        <p class="text-sm tx-secondary" style="max-width:440px;margin-left:auto;margin-right:auto">${T("collections.empty_hint")}</p>
      </div>`;

      return `<div class="mt-4 space-y-5">
        <div class="flex items-start justify-between gap-3 flex-wrap">
          <div>
            <div class="flex items-center gap-2 mb-1">
              <h2 class="text-xl font-semibold">${T("collections.title")}</h2>
              ${helpTrigger("collections")}
            </div>
            <p class="text-sm tx-secondary" style="max-width:640px">${T("collections.subtitle")}</p>
          </div>
          <div class="flex items-center gap-2">
            ${
              state.forms.length > 0
                ? `<div class="relative">
                    <span class="absolute top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" style="left:12px">${ICONS.search}</span>
                    <input id="tx-collections-search" type="text" value="${escHtml(state.collectionsSearch)}" placeholder="${T("app.search")}" class="tx-input" style="padding-left:36px;min-width:220px" />
                  </div>`
                : ""
            }
            <button data-action="new-collection" class="tx-btn">${ICONS.plus} ${T("collections.new")}</button>
          </div>
        </div>
        ${
          state.forms.length === 0
            ? emptyHelp
            : `<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">${cards}</div>`
        }
        ${state.newCollectionOpen ? renderCollectionEditor() : ""}
        ${renderHelpModal()}
      </div>`;
    }

    function renderCollectionEditor() {
      const editing = state.editingCollection;
      const isNew = !editing;
      return `<div class="tx-modal-bg" data-action="close-collection-editor">
        <div class="tx-modal p-6" onclick="event.stopPropagation()">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold">${isNew ? T("collections.new") : T("app.edit")}</h3>
            <button data-action="close-collection-editor" class="tx-help-trigger">${ICONS.close}</button>
          </div>
          <form id="tx-collection-form" class="space-y-4">
            <div>
              <label class="tx-label">${T("collections.name_label")}</label>
              <input name="name" value="${escHtml(editing?.name || "")}" class="tx-input" placeholder="${T("collections.name_placeholder")}" required autofocus />
            </div>
            <div>
              <label class="tx-label">${T("collections.description_label")}</label>
              <textarea name="description" rows="2" class="tx-textarea" placeholder="${T("collections.description_placeholder")}">${escHtml(editing?.description || "")}</textarea>
            </div>
            <div>
              <label class="tx-label">${T("collections.language_label")}</label>
              <select name="language" class="tx-select">
                <option value="de"${(editing?.language || state.config.default_language || "de") === "de" ? " selected" : ""}>${T("settings.lang_de")}</option>
                <option value="en"${(editing?.language || state.config.default_language) === "en" ? " selected" : ""}>${T("settings.lang_en")}</option>
                <option value="es"${editing?.language === "es" ? " selected" : ""}>${T("settings.lang_es")}</option>
                <option value="tr"${editing?.language === "tr" ? " selected" : ""}>${T("settings.lang_tr")}</option>
              </select>
              <p class="tx-hint">${T("collections.language_hint")}</p>
            </div>
            <div class="flex items-center gap-2 pt-2">
              <button type="submit" class="tx-btn">${isNew ? T("collections.create_and_open") : T("collections.save_and_open")}</button>
              <button type="button" data-action="close-collection-editor" class="tx-btn tx-btn-ghost">${T("app.cancel")}</button>
            </div>
          </form>
        </div>
      </div>`;
    }

    // =========================================================================
    // Collection detail view (tabbed)
    // =========================================================================

    function renderCollection() {
      const c = collectionById(state.collectionId);
      if (!c) {
        return `<div class="mt-4 tx-card p-6">
          <button data-nav="collections" class="flex items-center gap-1 text-sm transition-colors mb-3" style="color:var(--txt-secondary)">${ICONS.back} ${T("collection.breadcrumb")}</button>
          <p class="text-sm">${T("collection.not_found")}</p>
        </div>`;
      }

      const tabs = [
        { id: "overview", label: T("collection.tab_overview") },
        { id: "variables", label: T("collection.tab_variables") },
        { id: "templates", label: T("collection.tab_templates") },
        { id: "datasets", label: T("collection.tab_datasets") },
        { id: "export", label: T("collection.tab_export") },
        { id: "danger", label: T("collection.tab_danger"), danger: true },
      ];

      return `<div class="mt-4 space-y-5">
        <div>
          <button data-nav="collections" class="flex items-center gap-1 text-sm transition-colors" style="color:var(--txt-secondary)">${ICONS.back} ${T("collection.breadcrumb")}</button>
          <div class="flex items-start justify-between gap-3 mt-2">
            <div class="min-w-0 flex-1">
              <h2 class="text-2xl font-bold tracking-tight truncate flex items-center gap-2">
                <span style="color:var(--brand)">${ICONS.folderOpen}</span>
                ${escHtml(c.name)}
              </h2>
              ${c.description ? `<p class="text-sm tx-secondary mt-1">${escHtml(c.description)}</p>` : ""}
            </div>
            <button data-action="edit-collection" class="tx-btn tx-btn-sm tx-btn-ghost">${ICONS.edit} ${T("app.edit")}</button>
          </div>
        </div>

        <nav class="flex gap-1 overflow-x-auto" style="border-bottom:1px solid var(--divider)">
          ${tabs
            .map(
              (t) =>
                `<button data-tab="${t.id}" class="tx-tab${state.tab === t.id ? " active" : ""}${t.danger ? " danger" : ""}">${t.label}</button>`,
            )
            .join("")}
        </nav>

        <div>${renderCollectionTab(c)}</div>
        ${state.editingCollection && !state.newCollectionOpen ? renderCollectionEditor() : ""}
        ${renderHelpModal()}
      </div>`;
    }

    function renderCollectionTab(c) {
      switch (state.tab) {
        case "variables":
          return renderVariablesTab(c);
        case "templates":
          return renderTemplatesTab(c);
        case "datasets":
          return renderDatasetsTab(c);
        case "export":
          return renderExportTab(c);
        case "danger":
          return renderDangerTab(c);
        case "overview":
        default:
          return renderOverviewTab(c);
      }
    }

    // --- Overview tab ---

    function renderOverviewTab(c) {
      const datasets = datasetsForCollection(c.id);
      const templates = collectionTemplates(c);
      const vars = c.fields?.length || 0;
      const hasVars = vars > 0;
      const hasTpls = templates.length > 0;
      const hasDatasets = datasets.length > 0;
      const hasGenerated = datasets.some(
        (d) =>
          d.status === "generated" ||
          (d.documents && Object.keys(d.documents).length > 0),
      );

      const stats = `<div class="grid grid-cols-3 gap-3">
        ${statCard(T("collection.overview_stats_variables"), vars, ICONS.variable, "variables")}
        ${statCard(T("collection.overview_stats_templates"), templates.length, ICONS.file, "templates")}
        ${statCard(T("collection.overview_stats_datasets"), datasets.length, ICONS.database, "datasets")}
      </div>`;

      // 4-step wizard. Each step advertises its "current" status based on
      // the first unfinished prerequisite and exposes a CTA that navigates
      // to the relevant tab.
      const steps = [
        {
          key: "variables",
          num: 1,
          done: hasVars,
          icon: ICONS.variable,
          title: T("collection.overview_step1_title"),
          text: T("collection.overview_step1"),
          cta: T("collection.goto_variables"),
          tab: "variables",
        },
        {
          key: "templates",
          num: 2,
          done: hasTpls,
          icon: ICONS.file,
          title: T("collection.overview_step2_title"),
          text: T("collection.overview_step2"),
          cta: T("collection.goto_templates"),
          tab: "templates",
        },
        {
          key: "datasets",
          num: 3,
          done: hasDatasets,
          icon: ICONS.database,
          title: T("collection.overview_step3_title"),
          text: T("collection.overview_step3"),
          cta: T("collection.goto_datasets"),
          tab: "datasets",
        },
        {
          key: "generate",
          num: 4,
          done: hasGenerated,
          icon: ICONS.doc,
          title: T("collection.overview_step4_title"),
          text: T("collection.overview_step4"),
          cta: T("collection.goto_datasets"),
          tab: "datasets",
        },
      ];
      const activeStepIdx = steps.findIndex((s) => !s.done);

      const stepsMarkup = steps
        .map((s, i) => {
          const active = i === activeStepIdx;
          const prevDone = i === 0 || steps[i - 1].done;
          const disabled = !s.done && !prevDone;
          const bg = s.done
            ? "background:var(--status-success);color:#fff"
            : active
              ? "background:var(--brand);color:#fff"
              : "background:var(--bg-chip);color:var(--txt-secondary)";
          const cardStyle = active
            ? "box-shadow:inset 0 0 0 2px var(--brand), 0 4px 12px rgba(0,0,0,.06);"
            : "";
          return `<button type="button" data-tab="${s.tab}"${disabled ? " disabled" : ""} class="tx-row text-left p-4 flex items-start gap-3 transition-all" style="${cardStyle}${disabled ? "opacity:.55;pointer-events:none;" : ""}">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full text-sm font-bold flex-shrink-0" style="${bg}">${s.done ? "✓" : s.num}</span>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-1.5 text-sm font-semibold">
                <span>${s.icon}</span>
                <span>${s.title}</span>
              </div>
              <div class="text-xs tx-secondary mt-1">${s.text}</div>
              ${!disabled ? `<div class="text-xs mt-2 font-medium" style="color:var(--brand)">${s.cta} →</div>` : ""}
            </div>
          </button>`;
        })
        .join("");

      let callout = "";
      if (!hasVars) {
        callout = `<div class="tx-callout flex items-center gap-2">${ICONS.info} <span>${T("collection.summary_no_vars")}</span> <button data-tab="variables" class="tx-link ml-auto text-sm font-medium">${T("collection.goto_variables")} →</button></div>`;
      } else if (!hasTpls) {
        callout = `<div class="tx-callout flex items-center gap-2">${ICONS.info} <span>${T("collection.summary_no_templates")}</span> <button data-tab="templates" class="tx-link ml-auto text-sm font-medium">${T("collection.goto_templates")} →</button></div>`;
      } else {
        callout = `<div class="tx-callout flex items-center gap-2" style="background:color-mix(in srgb, var(--status-success) 10%, var(--bg-card));color:var(--txt-primary)">${ICONS.check} <span>${T("collection.summary_ready")}</span> <button data-tab="datasets" class="tx-link ml-auto text-sm font-medium">${T("collection.goto_datasets")} →</button></div>`;
      }

      const recentDatasets = datasets
        .slice()
        .sort(
          (a, b) =>
            new Date(b.updated_at || b.created_at || 0) -
            new Date(a.updated_at || a.created_at || 0),
        )
        .slice(0, 5);

      const recentRows =
        recentDatasets.length > 0
          ? recentDatasets
              .map(
                (d) =>
                  `<button data-open-dataset="${d.id}" class="tx-row w-full text-left p-3 flex items-center justify-between">
                    <div>
                      <div class="font-medium text-sm">${escHtml(datasetDisplayName(d))}</div>
                      <div class="text-xs tx-secondary mt-0.5">${formatDate(d.updated_at || d.created_at)}</div>
                    </div>
                    ${statusBadge(d.status || "draft")}
                  </button>`,
              )
              .join("")
          : `<p class="text-sm tx-secondary py-2">${T("collection.overview_datasets_empty")}</p>`;

      return `<div class="space-y-4">
        ${stats}
        ${callout}
        <div class="tx-card p-5">
          <h3 class="text-sm font-semibold mb-3">${T("collection.overview_steps_title")}</h3>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">${stepsMarkup}</div>
        </div>
        <div class="tx-card p-5">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold">${T("collection.overview_datasets_title")}</h3>
            ${hasVars ? `<button data-action="new-dataset" class="tx-btn tx-btn-sm">${ICONS.plus} ${T("collection.overview_add_dataset")}</button>` : ""}
          </div>
          <div class="space-y-1.5">${recentRows}</div>
        </div>
      </div>`;
    }

    function statCard(label, value, icon, tab) {
      return `<button data-tab="${tab}" class="tx-row p-4 text-left flex items-center gap-3 transition-transform hover:translate-y-[-1px]">
        <span class="inline-flex items-center justify-center w-9 h-9 rounded-full" style="background:var(--brand-alpha-light);color:var(--brand)">${icon}</span>
        <div>
          <div class="text-xl font-bold leading-none">${value}</div>
          <div class="text-xs tx-secondary mt-1">${label}</div>
        </div>
      </button>`;
    }

    // --- Variables tab ---

    function renderVariablesTab(c) {
      const fields = state.variablesDraft || c.fields || [];

      if (state.variablesTplImportOpen) return renderVariablesTplImport(c);
      if (state.variablesImportOpen) return renderVariablesImport(c);

      const attachedTemplates = collectionTemplates(c);
      const hasTemplates = attachedTemplates.length > 0;
      const tplTitle = hasTemplates
        ? T("variables.import_from_template_hint")
        : T("variables.import_no_templates");

      const header = `<div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <div class="flex items-center gap-2 mb-1">
            <h3 class="text-lg font-semibold">${T("variables.title")}</h3>
            ${helpTrigger("variables")}
          </div>
          <p class="text-sm tx-secondary" style="max-width:640px">${T("variables.subtitle")}</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <button data-action="variables-import-template" class="tx-btn tx-btn-sm tx-btn-ghost" title="${escHtml(tplTitle)}"${hasTemplates ? "" : " disabled"}>${ICONS.doc} ${T("variables.import_from_template")}</button>
          <button data-action="variables-import" class="tx-btn tx-btn-sm tx-btn-ghost" title="${T("variables.import_hint")}">${ICONS.upload} ${T("variables.import_from_text")}</button>
          <button data-action="add-variable" class="tx-btn tx-btn-sm">${ICONS.plus} ${T("variables.new_field")}</button>
        </div>
      </div>`;

      let body = "";
      if (fields.length === 0) {
        body = `<div class="tx-card p-8 text-center">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-full mb-3" style="background:var(--bg-chip);color:var(--txt-secondary)">${ICONS.variable}</div>
          <h4 class="font-semibold text-lg mb-1">${T("variables.empty")}</h4>
          <p class="text-sm tx-secondary" style="max-width:440px;margin-left:auto;margin-right:auto">${T("variables.empty_hint")}</p>
        </div>`;
      } else {
        body = `<form id="tx-variables-form" class="space-y-2">
          ${fields.map((fd, idx) => renderVariableEditor(fd, idx)).join("")}
          <div class="flex items-center gap-2 pt-2">
            <button type="submit" class="tx-btn tx-btn-sm"${state.variablesDirty ? "" : " disabled"}>${ICONS.check} ${T("app.save")}</button>
            ${state.variablesDirty ? `<button type="button" data-action="variables-reset" class="tx-btn tx-btn-sm tx-btn-ghost">${T("app.reset")}</button>` : ""}
          </div>
        </form>`;
      }

      return `<div class="space-y-4">${header}${body}</div>`;
    }

    /**
     * Small visual hint for each variable row so the user can see what
     * shape it will render as in the final Word document:
     *   list     -> one bullet paragraph per item
     *   checkbox -> \u2612 / \u2610 glyph pair (or "Ja"/"Nein" text fallback)
     *   table    -> repeating row clone with per-column types
     *   image    -> embedded <w:drawing>
     *   other    -> plain inline text substitution
     */
    function renderFieldRenderingBadge(fd) {
      const t = fd.type || "text";
      const map = {
        list: { glyph: "\u2022", cls: "tx-badge-list" },
        checkbox: { glyph: "\u2612", cls: "tx-badge-check" },
        table: { glyph: "\u229E", cls: "tx-badge-table" },
        image: { glyph: "\u25A3", cls: "tx-badge-image" },
        textarea: { glyph: "\u00B6", cls: "tx-badge-plain" },
        select: { glyph: "\u25BC", cls: "tx-badge-plain" },
        date: { glyph: "\u2637", cls: "tx-badge-plain" },
        number: { glyph: "#", cls: "tx-badge-plain" },
      };
      if (!map[t]) return "";
      const labelText = T(
        `variables.render_badge_${t}`,
        T(`variables.type_${t}`, t),
      );
      return `<span class="tx-render-badge ${map[t].cls}" title="${escHtml(labelText)}">
        <span aria-hidden="true">${map[t].glyph}</span>
        <span>${escHtml(labelText)}</span>
      </span>`;
    }

    function renderVariableEditor(fd, idx) {
      const fields = state.variablesDraft || [];
      const total = fields.length;
      const optsStr = Array.isArray(fd.options) ? fd.options.join(", ") : "";
      const types = [
        "text",
        "textarea",
        "select",
        "list",
        "date",
        "number",
        "checkbox",
        "table",
        "image",
      ];
      const showDesigner = ["list", "table", "checkbox", "image"].includes(
        fd.type,
      );
      const designerOpen = state.expandedDesignerIdx === idx;
      const badge = renderFieldRenderingBadge(fd);
      return `<div class="tx-row p-3 space-y-2" data-field-idx="${idx}">
        <div class="flex items-start gap-2">
          <div class="flex flex-col gap-0.5 pt-1 flex-shrink-0">
            <button type="button" data-action="var-move-up" data-idx="${idx}" class="p-0.5 rounded transition-colors" style="color:var(--txt-secondary)${idx === 0 ? ";opacity:0.3" : ""}" ${idx === 0 ? "disabled" : ""}>${ICONS.arrowUp}</button>
            <button type="button" data-action="var-move-down" data-idx="${idx}" class="p-0.5 rounded transition-colors" style="color:var(--txt-secondary)${idx === total - 1 ? ";opacity:0.3" : ""}" ${idx === total - 1 ? "disabled" : ""}>${ICONS.arrowDown}</button>
          </div>
          <div class="flex-1 grid grid-cols-2 sm:grid-cols-4 gap-2">
            <input name="fk_${idx}" value="${escHtml(fd.key || "")}" placeholder="${T("variables.field_key")}" class="tx-input" />
            <input name="fl_${idx}" value="${escHtml(fd.label || "")}" placeholder="${T("variables.field_label")}" class="tx-input" />
            <select name="ft_${idx}" class="tx-select">
              ${types
                .map(
                  (tp) =>
                    `<option value="${tp}"${fd.type === tp ? " selected" : ""}>${T(`variables.type_${tp}`, tp)}</option>`,
                )
                .join("")}
            </select>
            <label class="flex items-center gap-1.5 text-sm" style="color:var(--txt-primary)">
              <input type="checkbox" name="fr_${idx}" ${fd.required ? "checked" : ""} class="h-4 w-4" style="accent-color:var(--brand)" />
              <span>${T("variables.field_required")}</span>
            </label>
          </div>
          ${badge ? `<div class="flex-shrink-0 pt-1">${badge}</div>` : ""}
          <button type="button" data-action="var-remove" data-idx="${idx}" class="p-1 transition-colors" style="color:var(--txt-secondary)" title="${T("variables.remove_field")}">${ICONS.trash}</button>
        </div>
        ${fd.type === "select" ? `<input name="fo_${idx}" value="${escHtml(optsStr)}" placeholder="${T("variables.field_options_hint")}" class="tx-input text-xs" />` : ""}
        ${fd.type === "table" ? renderVariableColumnEditor(idx, fd.columns || []) : ""}
        <div class="flex items-start gap-2">
          <input name="fh_${idx}" value="${escHtml(fd.hint || "")}" placeholder="${T("variables.field_hint")}" class="tx-input text-xs" />
          <button type="button" class="tx-help-trigger mt-1" title="${T("variables.field_hint_info")}">${ICONS.question}</button>
        </div>
        ${
          showDesigner
            ? `<div style="border-top:1px dashed var(--divider);padding-top:.5rem">
                <button type="button" data-action="var-designer-toggle" data-idx="${idx}" class="flex items-center gap-1 text-xs tx-link">
                  ${designerOpen ? ICONS.chevDown : ICONS.chevRight}
                  ${T("variables.designer_title")}
                </button>
                ${designerOpen ? renderVariableDesigner(idx, fd) : ""}
              </div>`
            : ""
        }
      </div>`;
    }

    function renderVariableDesigner(idx, fd) {
      const d = fd.designer || {};
      if (fd.type === "list") {
        const style = d.list_style || "ul";
        return `<div class="mt-2 p-3 rounded space-y-3" style="background:var(--bg-app)">
          <p class="text-xs tx-secondary">${T("variables.designer_list_hint")}</p>
          <div>
            <label class="tx-label">${T("variables.designer_list_style")}</label>
            <div class="flex items-center gap-3 text-sm">
              <label class="flex items-center gap-1.5">
                <input type="radio" name="fd_${idx}_style" value="ul" ${style === "ul" ? "checked" : ""} />
                <span>${T("variables.designer_style_ul")}</span>
              </label>
              <label class="flex items-center gap-1.5">
                <input type="radio" name="fd_${idx}_style" value="ol" ${style === "ol" ? "checked" : ""} />
                <span>${T("variables.designer_style_ol")}</span>
              </label>
            </div>
          </div>
          <label class="flex items-center gap-1.5 text-sm">
            <input type="checkbox" name="fd_${idx}_prevent_orphans" ${d.prevent_orphans ? "checked" : ""} class="h-4 w-4" style="accent-color:var(--brand)" />
            <span>${T("variables.designer_prevent_orphans")}</span>
          </label>
          <p class="text-xs tx-secondary">${T("variables.designer_prevent_orphans_hint")}</p>
          <div class="pt-2 border-t" style="border-color:var(--divider)">
            <label class="tx-label">${T("variables.designer_list_spacing")}</label>
            <p class="text-xs tx-secondary mb-2" style="margin-top:-.125rem">${T("variables.designer_list_spacing_hint")}</p>
            <label class="flex items-center gap-1.5 text-sm">
              <input type="checkbox" name="fd_${idx}_top_blank_line" ${d.top_blank_line ? "checked" : ""} class="h-4 w-4" style="accent-color:var(--brand)" />
              <span>${T("variables.designer_list_top_blank")}</span>
            </label>
            <label class="flex items-center gap-1.5 text-sm">
              <input type="checkbox" name="fd_${idx}_bottom_blank_line" ${d.bottom_blank_line ? "checked" : ""} class="h-4 w-4" style="accent-color:var(--brand)" />
              <span>${T("variables.designer_list_bottom_blank")}</span>
            </label>
          </div>
        </div>`;
      }
      if (fd.type === "table") {
        return `<div class="mt-2 p-3 rounded space-y-2" style="background:var(--bg-app)">
          <p class="text-xs tx-secondary">${T("variables.designer_table_hint")}</p>
          <label class="flex items-center gap-1.5 text-sm">
            <input type="checkbox" name="fd_${idx}_repeat_header" ${d.repeat_header !== false ? "checked" : ""} class="h-4 w-4" style="accent-color:var(--brand)" />
            <span>${T("variables.designer_repeat_header")}</span>
          </label>
          <label class="flex items-center gap-1.5 text-sm">
            <input type="checkbox" name="fd_${idx}_prevent_row_break" ${d.prevent_row_break !== false ? "checked" : ""} class="h-4 w-4" style="accent-color:var(--brand)" />
            <span>${T("variables.designer_prevent_row_break")}</span>
          </label>
          <label class="flex items-center gap-1.5 text-sm">
            <input type="checkbox" name="fd_${idx}_keep_with_prev" ${d.keep_with_prev ? "checked" : ""} class="h-4 w-4" style="accent-color:var(--brand)" />
            <span>${T("variables.designer_keep_with_prev")}</span>
          </label>
        </div>`;
      }
      if (fd.type === "checkbox") {
        const clickable = d.clickable_checkbox !== false;
        return `<div class="mt-2 p-3 rounded space-y-3" style="background:var(--bg-app)">
          <p class="text-xs tx-secondary">${T("variables.designer_checkbox_hint")}</p>
          <label class="flex items-center gap-1.5 text-sm">
            <input type="checkbox" name="fd_${idx}_clickable_checkbox" ${clickable ? "checked" : ""} class="h-4 w-4" style="accent-color:var(--brand)" />
            <span>${T("variables.designer_checkbox_clickable")}</span>
          </label>
          <p class="text-xs tx-secondary" style="margin-top:-.125rem">${T("variables.designer_checkbox_clickable_hint")}</p>
          <div>
            <label class="tx-label">${T("variables.designer_checkbox_glyphs")}</label>
            <p class="text-xs tx-secondary mb-2" style="margin-top:-.125rem">${T("variables.designer_checkbox_glyphs_hint")}</p>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="text-xs tx-secondary">${T("variables.designer_checked_glyph")}</label>
                <input name="fd_${idx}_checked_glyph" value="${escHtml(d.checked_glyph || "☒")}" class="tx-input text-center" maxlength="4" style="max-width:4rem" />
              </div>
              <div>
                <label class="text-xs tx-secondary">${T("variables.designer_unchecked_glyph")}</label>
                <input name="fd_${idx}_unchecked_glyph" value="${escHtml(d.unchecked_glyph || "☐")}" class="tx-input text-center" maxlength="4" style="max-width:4rem" />
              </div>
            </div>
          </div>
          <div>
            <label class="tx-label">${T("variables.designer_checkbox_labels")}</label>
            <p class="text-xs tx-secondary mb-2" style="margin-top:-.125rem">${T("variables.designer_checkbox_labels_hint")}</p>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="text-xs tx-secondary">${T("variables.designer_yes_label")}</label>
                <input name="fd_${idx}_yes_label" value="${escHtml(d.yes_label || "")}" placeholder="Ja" class="tx-input" style="max-width:6rem" />
              </div>
              <div>
                <label class="text-xs tx-secondary">${T("variables.designer_no_label")}</label>
                <input name="fd_${idx}_no_label" value="${escHtml(d.no_label || "")}" placeholder="Nein" class="tx-input" style="max-width:6rem" />
              </div>
            </div>
          </div>
        </div>`;
      }
      if (fd.type === "image") {
        return `<div class="mt-2 p-3 rounded space-y-2" style="background:var(--bg-app)">
          <p class="text-xs tx-secondary">${T("variables.designer_image_hint")}</p>
          <div class="grid grid-cols-3 gap-2 items-center">
            <div>
              <label class="tx-label">${T("variables.designer_image_width")}</label>
              <input type="number" name="fd_${idx}_width" value="${escHtml(String(d.width || 140))}" min="16" max="1600" class="tx-input" />
            </div>
            <div>
              <label class="tx-label">${T("variables.designer_image_height")}</label>
              <input type="number" name="fd_${idx}_height" value="${escHtml(String(d.height || 180))}" min="16" max="2000" class="tx-input" />
            </div>
            <label class="flex items-center gap-1.5 text-sm mt-5">
              <input type="checkbox" name="fd_${idx}_preserve_ratio" ${d.preserve_ratio ? "checked" : ""} class="h-4 w-4" style="accent-color:var(--brand)" />
              <span>${T("variables.designer_image_preserve_ratio")}</span>
            </label>
          </div>
        </div>`;
      }
      return "";
    }

    function renderVariableColumnEditor(fieldIdx, columns) {
      const colTypes = ["text", "textarea", "list", "date", "number"];
      const colRows = columns
        .map((col, ci) => {
          const curType = col.type || "text";
          const typeOpts = colTypes
            .map(
              (ct) =>
                `<option value="${ct}"${ct === curType ? " selected" : ""}>${T(`variables.col_type_${ct}`, ct)}</option>`,
            )
            .join("");
          return `<div class="flex items-center gap-2" data-col-idx="${ci}">
            <input name="fc_${fieldIdx}_ck_${ci}" value="${escHtml(col.key || "")}" placeholder="${T("variables.column_key")}" class="tx-input text-xs" style="flex:1" />
            <input name="fc_${fieldIdx}_cl_${ci}" value="${escHtml(col.label || "")}" placeholder="${T("variables.column_label")}" class="tx-input text-xs" style="flex:1" />
            <select name="fc_${fieldIdx}_ct_${ci}" class="tx-select text-xs" style="width:7.5rem">${typeOpts}</select>
            <button type="button" data-action="var-col-remove" data-field-idx="${fieldIdx}" data-col-idx="${ci}" class="p-0.5" style="color:var(--txt-secondary)">${ICONS.trash}</button>
          </div>`;
        })
        .join("");
      return `<div class="ml-0 p-2 rounded" style="background:var(--bg-app);border:1px dashed var(--divider)">
        <div class="flex items-center justify-between mb-1.5">
          <span class="text-xs font-medium tx-secondary">${T("variables.table_columns")}</span>
          <button type="button" data-action="var-col-add" data-field-idx="${fieldIdx}" class="tx-link text-xs flex items-center gap-1">${ICONS.plus} ${T("variables.add_column")}</button>
        </div>
        <p class="text-xs tx-secondary mb-1">${T("variables.column_type_hint")}</p>
        <div class="space-y-1.5">${colRows}</div>
      </div>`;
    }

    function renderVariablesImport(c) {
      const parsed = state.variablesImportFields;
      const hasPreview = parsed && parsed.length > 0;
      return `<div class="space-y-4">
        <button data-action="variables-import-close" class="flex items-center gap-1 text-sm transition-colors" style="color:var(--txt-secondary)">${ICONS.back} ${T("app.back")}</button>
        <h3 class="text-lg font-semibold">${T("variables.import_from_text")}</h3>
        <div class="tx-card p-5 space-y-3">
          <label class="tx-label">${T("variables.import_hint")}</label>
          <textarea id="tx-import-text" class="tx-textarea" rows="8" placeholder="${T("variables.import_hint")}" style="font-family:monospace;font-size:.8rem">${escHtml(state.variablesImportText || "")}</textarea>
          <div class="flex items-center gap-3">
            <span class="text-xs tx-secondary">…or drop a .docx:</span>
            <input type="file" id="tx-import-file" accept=".docx" class="text-sm" />
          </div>
          <div class="flex items-center gap-2">
            <button data-action="variables-import-parse" class="tx-btn tx-btn-sm"${state.variablesImportParsing ? " disabled" : ""}>
              ${state.variablesImportParsing ? `<span class="animate-spin inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full"></span>` : ICONS.sparkle}
              ${state.variablesImportParsing ? T("app.loading") : T("variables.from_template")}
            </button>
            ${state.variablesImportError ? `<span class="text-sm" style="color:var(--status-error)">${escHtml(state.variablesImportError)}</span>` : ""}
          </div>
        </div>
        ${hasPreview ? renderVariablesImportPreview(parsed) : ""}
      </div>`;
    }

    function renderVariablesTplImport(c) {
      const templates = collectionTemplates(c);
      if (templates.length === 0) {
        return `<div class="space-y-4">
          <button data-action="variables-tplimport-close" class="flex items-center gap-1 text-sm transition-colors" style="color:var(--txt-secondary)">${ICONS.back} ${T("app.back")}</button>
          <h3 class="text-lg font-semibold">${T("variables.import_dialog_title")}</h3>
          <div class="tx-card p-5">
            <p class="text-sm">${T("variables.import_no_templates")}</p>
          </div>
        </div>`;
      }

      const pickerOptions = templates
        .map(
          (t) =>
            `<option value="${escHtml(t.id)}"${state.variablesTplImportTemplateId === t.id ? " selected" : ""}>${escHtml(t.name || t.id)} \u2014 ${t.placeholder_count ?? (t.placeholders?.length || 0)} ${T("templates.placeholder_count")}</option>`,
        )
        .join("");

      const loading = state.variablesTplImportLoading;
      const error = state.variablesTplImportError;
      const result = state.variablesTplImportResult;

      let body = "";
      if (loading) {
        body = `<div class="tx-card p-5 text-center">
          <span class="animate-spin inline-block w-5 h-5 border-2 border-current border-t-transparent rounded-full"></span>
          <p class="text-sm tx-secondary mt-2">${T("app.loading")}</p>
        </div>`;
      } else if (error) {
        body = `<div class="tx-card p-5"><p class="text-sm" style="color:var(--status-error)">${escHtml(error)}</p></div>`;
      } else if (result) {
        body = renderVariablesTplImportPreview(result);
      }

      return `<div class="space-y-4">
        <button data-action="variables-tplimport-close" class="flex items-center gap-1 text-sm transition-colors" style="color:var(--txt-secondary)">${ICONS.back} ${T("app.back")}</button>
        <h3 class="text-lg font-semibold">${T("variables.import_dialog_title")}</h3>
        <p class="text-sm tx-secondary" style="max-width:640px">${T("variables.import_dialog_subtitle")}</p>
        <div class="tx-card p-5 space-y-3">
          <label class="tx-label">${T("variables.import_pick_template")}</label>
          <div class="flex items-center gap-2 flex-wrap">
            <select id="tx-tplimport-picker" class="tx-select" style="max-width:360px">${pickerOptions}</select>
            <button data-action="variables-tplimport-load" class="tx-btn tx-btn-sm"${loading ? " disabled" : ""}>${ICONS.sparkle} ${T("variables.detect_placeholders")}</button>
          </div>
          <p class="text-xs tx-secondary">${T("variables.import_pick_template_hint")}</p>
        </div>
        ${body}
      </div>`;
    }

    function renderVariablesTplImportPreview(result) {
      const suggestions = result.suggestions || [];
      const sel = state.variablesTplImportSelection || {};
      const summary = result.summary || {};

      const summaryText = T("variables.import_summary")
        .replace("{total}", result.placeholder_count ?? 0)
        .replace("{tables}", summary.tables || 0)
        .replace("{checkboxes}", summary.checkboxes || 0)
        .replace("{lists}", summary.lists || 0)
        .replace("{texts}", summary.texts || 0)
        .replace("{duplicate}", summary.duplicate || 0);

      const rows = suggestions
        .map((f, i) => {
          const isDup = f._status === "duplicate";
          const checked = sel[i] === true;
          const extras = describeFieldExtras(f);
          return `<tr class="tx-divider border-t${isDup ? " opacity-60" : ""}">
            <td class="py-2 px-3"><input type="checkbox" data-action="variables-tplimport-toggle" data-idx="${i}" ${checked ? "checked" : ""} ${isDup ? "" : ""} class="h-4 w-4" style="accent-color:var(--brand)" /></td>
            <td class="py-2 px-3 font-mono text-xs">${escHtml(f.key)}${isDup ? ` <span class="tx-secondary text-xs">(${T("variables.import_duplicate_key")})</span>` : ""}</td>
            <td class="py-2 px-3 text-sm">${escHtml(f.label || "")}</td>
            <td class="py-2 px-3 text-xs">${escHtml(T(`variables.type_${f.type}`, f.type))}</td>
            <td class="py-2 px-3 text-xs tx-secondary">${escHtml(extras)}</td>
          </tr>`;
        })
        .join("");

      const selectedCount = Object.values(sel).filter(Boolean).length;
      const applyLabel =
        selectedCount > 0
          ? T("variables.import_apply_count").replace("{count}", selectedCount)
          : T("variables.import_apply_none");

      return `<div class="tx-card p-5 space-y-3">
        <div class="flex items-center justify-between gap-3 flex-wrap">
          <h4 class="text-sm font-medium">${T("variables.import_placeholders_label")}</h4>
          <p class="text-xs tx-secondary">${escHtml(summaryText)}</p>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead>
              <tr class="tx-divider border-b text-xs tx-secondary uppercase tracking-wider">
                <th class="py-2 px-3">${T("variables.import_col_use")}</th>
                <th class="py-2 px-3">${T("variables.import_col_key")}</th>
                <th class="py-2 px-3">${T("variables.import_col_label")}</th>
                <th class="py-2 px-3">${T("variables.import_col_type")}</th>
                <th class="py-2 px-3">${T("variables.import_col_extras")}</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        <div class="flex items-center gap-2">
          <button data-action="variables-tplimport-apply" class="tx-btn tx-btn-sm"${selectedCount === 0 ? " disabled" : ""}>${ICONS.check} ${escHtml(applyLabel)}</button>
          <button data-action="variables-tplimport-close" class="tx-btn tx-btn-sm tx-btn-ghost">${T("app.cancel")}</button>
        </div>
      </div>`;
    }

    function describeFieldExtras(f) {
      if (f.type === "table") {
        const cols = (f.columns || []).map((c) => c.key).join(", ");
        return T("variables.import_extra_table_cols")
          .replace("{count}", (f.columns || []).length)
          .replace("{cols}", cols);
      }
      if (f.type === "checkbox") return T("variables.import_extra_checkbox");
      if (f.type === "list") return T("variables.import_extra_list");
      return T("variables.import_extra_text");
    }

    function renderVariablesImportPreview(parsed) {
      const rows = parsed
        .map(
          (f, i) =>
            `<tr class="tx-divider border-t">
              <td class="py-2 px-3 font-mono text-xs">${escHtml(f.key)}</td>
              <td class="py-2 px-3 text-sm">${escHtml(f.label || "")}</td>
              <td class="py-2 px-3 text-xs">${escHtml(T(`variables.type_${f.type || "text"}`, f.type || "text"))}</td>
              <td class="py-2 px-3 text-xs tx-secondary">${escHtml(f.hint || "")}</td>
              <td class="py-2 px-3">
                <button data-action="variables-import-remove" data-idx="${i}" class="p-1" style="color:var(--txt-secondary)">${ICONS.trash}</button>
              </td>
            </tr>`,
        )
        .join("");
      return `<div class="tx-card p-5 space-y-3">
        <h4 class="text-sm font-medium">${parsed.length} fields parsed</h4>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead>
              <tr class="tx-divider border-b text-xs tx-secondary uppercase tracking-wider">
                <th class="py-2 px-3">${T("variables.field_key")}</th>
                <th class="py-2 px-3">${T("variables.field_label")}</th>
                <th class="py-2 px-3">${T("variables.field_type")}</th>
                <th class="py-2 px-3">${T("variables.field_hint")}</th>
                <th class="py-2 px-3"></th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        <div class="flex items-center gap-2">
          <button data-action="variables-import-apply" class="tx-btn tx-btn-sm">${ICONS.check} ${T("app.continue")}</button>
          <button data-action="variables-import-close" class="tx-btn tx-btn-sm tx-btn-ghost">${T("app.cancel")}</button>
        </div>
      </div>`;
    }

    // --- Templates tab ---

    function renderTemplatesTab(c) {
      const templates = collectionTemplates(c);

      const header = `<div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <div class="flex items-center gap-2 mb-1">
            <h3 class="text-lg font-semibold">${T("templates.title")}</h3>
            ${helpTrigger("templates")}
          </div>
          <p class="text-sm tx-secondary" style="max-width:640px">${T("templates.subtitle")}</p>
        </div>
      </div>`;

      const inheritLabel = c?.language
        ? `${T("templates.language_inherit")} (${c.language.toUpperCase()})`
        : T("templates.language_inherit");
      const langOptions = `
        <option value="">${escHtml(inheritLabel)}</option>
        <option value="de">Deutsch (DE)</option>
        <option value="en">English (EN)</option>
        <option value="es">Espa\u00f1ol (ES)</option>
        <option value="fr">Fran\u00e7ais (FR)</option>
        <option value="it">Italiano (IT)</option>
        <option value="tr">T\u00fcrk\u00e7e (TR)</option>
        <option value="pt">Portugu\u00eas (PT)</option>
        <option value="nl">Nederlands (NL)</option>
        <option value="pl">Polski (PL)</option>
      `;

      const drop = `<div id="tx-template-drop" class="tx-drop">
        <div class="inline-flex items-center justify-center w-10 h-10 rounded-full mb-2" style="background:var(--brand-alpha-light);color:var(--brand)">${ICONS.upload}</div>
        <p class="text-sm font-medium">${T("templates.drop_hint")}</p>
        <input type="file" id="tx-template-file" accept=".docx" class="hidden" />
        <div id="tx-template-upload-form" class="mt-3 hidden">
          <div class="flex flex-col gap-2 max-w-md mx-auto">
            <div class="flex items-center gap-2">
              <input type="text" id="tx-template-name" placeholder="${T("templates.name_label")}" class="tx-input" style="flex:1" />
            </div>
            <div class="flex flex-col gap-1">
              <label class="text-xs tx-secondary text-left" for="tx-template-language">${T("templates.language_label")}</label>
              <select id="tx-template-language" class="tx-input">${langOptions}</select>
              <p class="text-xs tx-secondary text-left">${T("templates.language_hint")}</p>
            </div>
            <button id="tx-upload-template-btn" class="tx-btn tx-btn-sm self-end">${ICONS.upload} ${T("templates.upload")}</button>
          </div>
        </div>
      </div>`;

      const buildLangSelect = (tpl) => {
        const cur = (tpl.language || "").toLowerCase();
        const opts = [
          ["", inheritLabel],
          ["de", "DE"],
          ["en", "EN"],
          ["es", "ES"],
          ["fr", "FR"],
          ["it", "IT"],
          ["tr", "TR"],
          ["pt", "PT"],
          ["nl", "NL"],
          ["pl", "PL"],
        ];
        return `<select data-template-language-set="${tpl.id}" class="tx-input" style="height:28px;font-size:0.75rem;padding:0 0.4rem" title="${escHtml(T("templates.language_hint"))}">
          ${opts.map(([v, l]) => `<option value="${v}"${v === cur ? " selected" : ""}>${escHtml(l)}</option>`).join("")}
        </select>`;
      };

      const list = templates.length
        ? templates
            .map(
              (tpl) => `<div class="tx-row p-4">
                <div class="flex items-center justify-between gap-3">
                  <div class="min-w-0 flex-1">
                    <div class="font-medium truncate">${escHtml(tpl.name)}</div>
                    <div class="text-xs tx-secondary mt-0.5">${formatDate(tpl.created_at)}${tpl.placeholder_count != null ? ` · ${tpl.placeholder_count} ${T("templates.placeholders")}` : ""}</div>
                  </div>
                  <div class="flex items-center gap-1">
                    ${buildLangSelect(tpl)}
                    <button data-action="template-check-match" data-template-id="${tpl.id}" class="tx-btn tx-btn-sm tx-btn-ghost" title="${T("templates.check_match")}">${ICONS.check} ${T("templates.check_match")}</button>
                    <button data-download-template="${tpl.id}" class="p-1.5 text-gray-400 hover:text-blue-500 rounded transition-colors" title="${T("app.download")}">${ICONS.download}</button>
                    <button data-detach-template="${tpl.id}" class="p-1.5 text-gray-400 hover:text-red-500 rounded transition-colors" title="${T("app.delete")}">${ICONS.trash}</button>
                  </div>
                </div>
              </div>`,
            )
            .join("")
        : `<div class="tx-card p-6 text-center">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full mb-3" style="background:var(--bg-chip);color:var(--txt-secondary)">${ICONS.file}</div>
            <p class="text-sm tx-secondary">${T("templates.empty_hint")}</p>
          </div>`;

      return `<div class="space-y-4">
        ${header}
        ${drop}
        <div class="space-y-2">${list}</div>
        ${state.selectedTemplateDetail ? renderTemplateMatching() : ""}
      </div>`;
    }

    function renderTemplateMatching() {
      const tpl = state.selectedTemplateDetail;
      const c = collectionById(state.collectionId);
      const phs = tpl.placeholders || [];
      const fieldMap = Object.fromEntries(
        (c.fields || []).map((f) => [f.key, f]),
      );

      const structural = phs.filter(
        (p) =>
          p.type === "block_marker" ||
          (p.key || p.name || "").startsWith("#") ||
          (p.key || p.name || "").startsWith("/"),
      );
      const data = phs.filter((p) => !structural.includes(p));
      const seen = new Set();
      const deduped = [];
      for (const p of data) {
        const k = (p.key || p.name || "")
          .replace(/^checkb\./, "")
          .split(".")[0];
        if (!seen.has(k)) {
          seen.add(k);
          deduped.push({ ...p, matchKey: k });
        }
      }
      let matched = 0;

      /**
       * Human-readable rendering hint for a placeholder in the template:
       * is it a `{{checkb.X.yes/no}}` glyph pair (pretty) or a plain
       * `{{X}}` checkbox (renders as text "Ja"/"Nein")? For list / table
       * types we reuse the rendering-badge glyph so the user gets a
       * glanceable mode summary.
       */
      const modeHintFor = (ph, f) => {
        const rawKey = (ph.key || ph.name || "").toString();
        const isGlyphPair = rawKey.startsWith("checkb.");
        if (f && f.type === "checkbox") {
          return (
            renderFieldRenderingBadge(f) +
            (isGlyphPair
              ? `<span class="tx-hint" style="margin:0 0 0 .5rem">${T("templates.match_checkbox_glyph")}</span>`
              : `<span class="tx-hint" style="margin:0 0 0 .5rem;color:var(--status-warning,#d97706)">${T("templates.match_checkbox_plain")}</span>`)
          );
        }
        if (f) return renderFieldRenderingBadge(f);
        return "";
      };

      const rows = deduped
        .map((ph) => {
          const f = fieldMap[ph.matchKey];
          const isAdding = state.templateMatchAddKey === ph.matchKey;
          if (f) {
            matched++;
            const hint = modeHintFor(ph, f);
            return `<tr class="tx-divider border-t">
              <td class="py-2.5 px-3"><span class="font-mono text-sm">${escHtml(ph.key || ph.name)}</span></td>
              <td class="py-2.5 px-3">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="inline-flex items-center gap-1.5 text-sm" style="color:var(--status-success)">
                    ${ICONS.check}
                    <span class="font-medium">${escHtml(f.label || f.key)}</span>
                  </span>
                  ${hint}
                </div>
              </td>
            </tr>`;
          }
          if (isAdding) {
            const typeOpts = [
              "text",
              "textarea",
              "select",
              "list",
              "date",
              "number",
              "checkbox",
            ]
              .map(
                (tp) =>
                  `<option value="${tp}"${tp === (ph.type === "list" ? "list" : ph.type === "checkbox" ? "checkbox" : "text") ? " selected" : ""}>${T(`variables.type_${tp}`, tp)}</option>`,
              )
              .join("");
            return `<tr class="tx-divider border-t">
              <td class="py-2.5 px-3"><span class="font-mono text-sm">${escHtml(ph.key || ph.name)}</span></td>
              <td class="py-2.5 px-3">
                <div class="space-y-2" data-add-form-key="${escHtml(ph.matchKey)}">
                  <div class="grid grid-cols-2 gap-2" style="max-width:20rem">
                    <input name="add_label" placeholder="${T("variables.field_label")}" class="tx-input" value="${escHtml(ph.matchKey.replace(/[-_]/g, " ").replace(/\b\w/g, (c) => c.toUpperCase()))}" />
                    <select name="add_type" class="tx-select">${typeOpts}</select>
                  </div>
                  <input name="add_hint" placeholder="${T("variables.field_hint")}" class="tx-input" />
                  <div class="flex items-center gap-2">
                    <button data-action="template-match-save" data-key="${escHtml(ph.matchKey)}" class="tx-btn tx-btn-sm">${ICONS.check} ${T("app.add")}</button>
                    <button data-action="template-match-cancel" class="tx-btn tx-btn-sm tx-btn-ghost">${T("app.cancel")}</button>
                  </div>
                </div>
              </td>
            </tr>`;
          }
          return `<tr class="tx-divider border-t">
            <td class="py-2.5 px-3"><span class="font-mono text-sm">${escHtml(ph.key || ph.name)}</span></td>
            <td class="py-2.5 px-3">
              <span class="inline-flex items-center gap-1.5 text-sm" style="color:var(--status-warning,#d97706)">
                ${ICONS.warning}
                <span>${T("templates.match_unmatched")}</span>
              </span>
              <button data-action="template-match-add" data-key="${escHtml(ph.matchKey)}" class="ml-2 tx-btn tx-btn-sm tx-btn-ghost">${ICONS.plus} ${T("templates.match_add_field")}</button>
            </td>
          </tr>`;
        })
        .join("");

      const allMatched = matched === deduped.length;
      const summary = allMatched
        ? T("templates.match_all_covered")
        : Tf("templates.match_summary", { matched, total: deduped.length });

      // Health tips — non-blocking, glanceable guidance the user can act on
      // before generating. Three sources of tips:
      //   1. A checkbox-typed field whose template uses the plain `{{X}}`
      //      form instead of the `{{checkb.X.yes/no}}` pair (renders as
      //      text "Ja/Nein", not \u2612/\u2610 glyphs).
      //   2. A declared list-typed field with NO matching placeholder in
      //      the template at all (user added a variable but forgot to use
      //      it in the Word document).
      //   3. An image-typed field missing from the template.
      const tips = [];
      const phRawKeys = new Set(phs.map((p) => p.key || p.name || ""));
      const glyphCbKeys = new Set();
      for (const raw of phRawKeys) {
        const m = raw.match(/^checkb\.(.+?)\.(?:yes|no)$/);
        if (m) glyphCbKeys.add(m[1]);
      }
      for (const f of c.fields || []) {
        if (!f.key) continue;
        const plainUsed = phRawKeys.has(f.key);
        if (f.type === "checkbox" && plainUsed && !glyphCbKeys.has(f.key)) {
          tips.push({
            level: "warn",
            html: Tf("templates.tip_checkbox_plain", {
              key: `<code>${escHtml(f.key)}</code>`,
              pair: `<code>{{checkb.${escHtml(f.key)}.yes}} Ja  {{checkb.${escHtml(f.key)}.no}} Nein</code>`,
            }),
          });
        }
        if (f.type === "list" && !plainUsed) {
          tips.push({
            level: "info",
            html: Tf("templates.tip_list_unused", {
              key: `<code>{{${escHtml(f.key)}}}</code>`,
            }),
          });
        }
        if (f.type === "image" && !plainUsed) {
          tips.push({
            level: "info",
            html: Tf("templates.tip_image_unused", {
              key: `<code>{{${escHtml(f.key)}}}</code>`,
            }),
          });
        }
      }

      const tipsHtml = tips.length
        ? `<div class="mt-3 space-y-1.5">
            ${tips
              .map((t) => {
                const color =
                  t.level === "warn"
                    ? "var(--status-warning,#d97706)"
                    : "var(--txt-secondary)";
                const icon =
                  t.level === "warn"
                    ? ICONS.warning
                    : ICONS.info || ICONS.question;
                return `<div class="flex items-start gap-1.5 text-xs" style="color:${color}">
                ${icon}
                <span>${t.html}</span>
              </div>`;
              })
              .join("")}
          </div>`
        : "";

      return `<div class="tx-card p-5 mt-2">
        <div class="flex items-center justify-between mb-3">
          <h4 class="font-medium text-sm">${escHtml(tpl.name)} · ${summary}</h4>
          <button data-action="template-match-close" class="tx-link text-sm">${T("app.close")}</button>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead>
              <tr class="tx-divider border-b text-xs tx-secondary uppercase tracking-wider">
                <th class="py-2 px-3" style="width:45%">Placeholder</th>
                <th class="py-2 px-3">Variable</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        ${tipsHtml}
        ${
          structural.length > 0
            ? `<div class="mt-3 text-xs tx-secondary">
                <span class="font-medium">${T("templates.match_structural")}:</span>
                ${structural.map((p) => `<code class="ml-1">${escHtml(p.key || p.name)}</code>`).join(", ")}
              </div>`
            : ""
        }
      </div>`;
    }

    // --- Datasets tab ---

    const DATASETS_PER_PAGE = 25;

    function renderDatasetsTab(c) {
      if (state.selectedDataset) return renderDatasetDetail(c);
      if (state.newDatasetOpen) return renderNewDataset(c);

      const fields = c.fields || [];
      if (fields.length === 0) {
        return `<div class="tx-card p-6 text-center">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-full mb-3" style="background:var(--bg-chip);color:var(--txt-secondary)">${ICONS.variable}</div>
          <h4 class="font-semibold text-lg mb-1">${T("collection.summary_no_vars")}</h4>
          <button data-tab="variables" class="tx-btn tx-btn-sm mt-3">${T("collection.goto_variables")}</button>
        </div>`;
      }

      const allDatasets = datasetsForCollection(c.id);
      const q = state.datasetsSearch.trim().toLowerCase();
      let filtered = allDatasets;
      if (q) {
        filtered = allDatasets.filter((d) => {
          const hay = [
            datasetDisplayName(d),
            ...Object.values(d.field_values || {}).map(String),
            ...Object.values(d.ai_extracted || {}).map(String),
          ]
            .join(" ")
            .toLowerCase();
          return q.split(/\s+/).every((tok) => hay.includes(tok));
        });
      }
      filtered.sort(
        (a, b) =>
          (state.datasetsSortNewest ? -1 : 1) *
          (new Date(a.created_at || 0) - new Date(b.created_at || 0)),
      );

      const totalPages = Math.max(
        1,
        Math.ceil(filtered.length / DATASETS_PER_PAGE),
      );
      if (state.datasetsPage >= totalPages)
        state.datasetsPage = Math.max(0, totalPages - 1);
      const start = state.datasetsPage * DATASETS_PER_PAGE;
      const pageItems = filtered.slice(start, start + DATASETS_PER_PAGE);

      const header = `<div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h3 class="text-lg font-semibold mb-1">${T("datasets.title")}</h3>
          <p class="text-sm tx-secondary" style="max-width:640px">${T("datasets.subtitle")}</p>
        </div>
        <button data-action="new-dataset" class="tx-btn tx-btn-sm">${ICONS.plus} ${T("datasets.new")}</button>
      </div>`;

      // Surface a GDPR-style overdue banner so the user can bulk-delete
      // datasets whose retention window (delete_after_months) has elapsed.
      const overdueDatasets = state.retentionBannerDismissed
        ? []
        : allDatasets.filter(datasetIsOverdue);
      const overdueBanner = renderOverdueBanner(overdueDatasets);

      if (allDatasets.length === 0) {
        return `<div class="space-y-4">
          ${header}
          ${overdueBanner}
          <div class="tx-card p-8 text-center">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full mb-3" style="background:var(--bg-chip);color:var(--txt-secondary)">${ICONS.database}</div>
            <h4 class="font-semibold text-lg mb-1">${T("datasets.empty")}</h4>
            <p class="text-sm tx-secondary" style="max-width:440px;margin-left:auto;margin-right:auto">${T("datasets.empty_hint")}</p>
          </div>
        </div>`;
      }

      const toolbar = `<div class="flex items-center gap-2">
        <div class="relative flex-1">
          <span class="absolute top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" style="left:12px">${ICONS.search}</span>
          <input id="tx-datasets-search" type="text" value="${escHtml(state.datasetsSearch)}" placeholder="${T("datasets.search_placeholder")}" class="tx-input" style="padding-left:36px" />
        </div>
        <button id="tx-datasets-sort" class="tx-btn tx-btn-sm tx-btn-ghost" title="${state.datasetsSortNewest ? T("datasets.sort_newest") : T("datasets.sort_oldest")}">
          ${state.datasetsSortNewest ? ICONS.sortDown : ICONS.sortUp}
          <span class="text-xs">${state.datasetsSortNewest ? T("datasets.sort_newest") : T("datasets.sort_oldest")}</span>
        </button>
      </div>`;

      const rows = pageItems.length
        ? pageItems
            .map(
              (d) =>
                `<div class="tx-row p-4 flex items-center justify-between gap-3">
                  <button type="button" data-open-dataset="${d.id}" class="min-w-0 flex-1 text-left bg-transparent border-0 p-0 cursor-pointer" style="color:inherit;font:inherit">
                    <div class="font-medium truncate">${escHtml(datasetDisplayName(d))}</div>
                    <div class="text-xs tx-secondary mt-0.5">${formatDate(d.created_at)}</div>
                  </button>
                  <div class="flex items-center gap-3">
                    ${statusBadge(d.status || "draft")}
                    <span class="text-xs ${datasetHasDoc(d) ? "text-green-600 dark:text-green-400" : "tx-secondary"}">${datasetHasDoc(d) ? T("datasets.doc_uploaded") : T("datasets.doc_missing")}</span>
                    <button data-delete-dataset="${d.id}" class="p-1.5 text-gray-400 hover:text-red-500 rounded transition-colors" title="${T("app.delete")}">${ICONS.trash}</button>
                  </div>
                </div>`,
            )
            .join("")
        : `<p class="text-sm tx-secondary py-6 text-center">${T("datasets.no_results")}</p>`;

      return `<div class="space-y-4">
        ${header}
        ${overdueBanner}
        ${toolbar}
        <div class="space-y-2">${rows}</div>
        ${totalPages > 1 ? renderPagination(state.datasetsPage, totalPages, filtered.length) : ""}
      </div>`;
    }

    function renderPagination(current, totalPages, totalItems) {
      const from = current * DATASETS_PER_PAGE + 1;
      const to = Math.min((current + 1) * DATASETS_PER_PAGE, totalItems);
      const isFirst = current === 0;
      const isLast = current >= totalPages - 1;
      const pages = [];
      if (totalPages <= 7) {
        for (let i = 0; i < totalPages; i++) pages.push(i);
      } else {
        pages.push(0);
        if (current > 2) pages.push(-1);
        const lo = Math.max(1, current - 1);
        const hi = Math.min(totalPages - 2, current + 1);
        for (let i = lo; i <= hi; i++) pages.push(i);
        if (current < totalPages - 3) pages.push(-1);
        pages.push(totalPages - 1);
      }
      const btnBase =
        "inline-flex items-center justify-center rounded transition-colors";
      const btnStyle = "min-width:2rem;height:2rem;font-size:.8125rem;";
      const pageBtns = pages
        .map((p) => {
          if (p === -1)
            return `<span class="px-1 tx-secondary" style="font-size:.8125rem">…</span>`;
          const active = p === current;
          const bg = active
            ? "background:var(--brand);color:#fff;"
            : "color:var(--txt-primary);";
          return `<button data-page="${p}" class="${btnBase}${active ? " font-semibold" : ""}" style="${btnStyle}${bg}">${p + 1}</button>`;
        })
        .join("");
      return `<div class="flex items-center justify-between pt-2" style="border-top:1px solid var(--divider)">
        <span class="text-xs tx-secondary">${from}–${to} / ${totalItems}</span>
        <div class="flex items-center gap-1">
          <button data-page-prev class="${btnBase}" style="${btnStyle}${isFirst ? "opacity:.35;pointer-events:none;" : ""}color:var(--txt-primary);">${ICONS.back}</button>
          ${pageBtns}
          <button data-page-next class="${btnBase}" style="${btnStyle}${isLast ? "opacity:.35;pointer-events:none;" : ""}color:var(--txt-primary);transform:scaleX(-1);">${ICONS.back}</button>
        </div>
      </div>`;
    }

    function renderNewDataset(c) {
      return `<div class="space-y-4">
        <button data-action="datasets-back" class="flex items-center gap-1 text-sm transition-colors" style="color:var(--txt-secondary)">${ICONS.back} ${T("app.back")}</button>
        <div class="tx-card p-6 max-w-lg">
          <h3 class="text-lg font-semibold mb-4">${T("datasets.new")}</h3>
          <form id="tx-new-dataset-form" class="space-y-4">
            <div>
              <label class="tx-label">${T("app.name")}</label>
              <input name="name" class="tx-input" placeholder="${T("datasets.name_placeholder")}" required autofocus />
            </div>
            <p class="text-xs tx-secondary">${T("datasets.new_hint")}</p>
            <button type="submit" class="tx-btn">${T("datasets.create_and_open")}</button>
          </form>
        </div>
      </div>`;
    }

    // ---------------------------------------------------------------------
    // GDPR / data retention helpers
    // ---------------------------------------------------------------------

    const RETENTION_MONTHS_OPTIONS = [0, 3, 6, 9, 12, 18];

    function datasetExpiresAt(d) {
      // Backend already returns a computed `expires_at`. We re-derive it on
      // the client for legacy entries (or in case the timestamp is stale)
      // so the UI stays accurate even before the next API roundtrip.
      const months = parseInt(d?.delete_after_months, 10);
      if (!months || months <= 0) return null;
      const base = new Date(d.updated_at || d.created_at || Date.now());
      if (Number.isNaN(base.getTime())) return null;
      const out = new Date(base);
      out.setMonth(out.getMonth() + months);
      return out;
    }

    function datasetIsOverdue(d) {
      const exp = datasetExpiresAt(d);
      if (!exp) return false;
      return exp.getTime() < Date.now();
    }

    function renderRetentionPicker(d) {
      const cur = parseInt(d?.delete_after_months, 10) || 0;
      const opts = RETENTION_MONTHS_OPTIONS.map((m) => {
        const label =
          m === 0
            ? T("datasets.retention_never")
            : Tf("datasets.retention_months", { months: m });
        return `<option value="${m}"${m === cur ? " selected" : ""}>${escHtml(label)}</option>`;
      }).join("");
      return `<label class="flex items-center gap-1.5 text-xs tx-secondary" title="${escHtml(T("datasets.retention_hint"))}">
        ${ICONS.clock || ICONS.calendar || ""}
        <span>${T("datasets.retention_label")}:</span>
        <select data-action="set-retention" class="tx-input" style="height:30px;padding:0 0.4rem;font-size:0.75rem">${opts}</select>
      </label>`;
    }

    function renderExpiryHint(d) {
      const exp = datasetExpiresAt(d);
      if (!exp) return "";
      const overdue = exp.getTime() < Date.now();
      const formatted = exp.toISOString().slice(0, 10);
      const cls = overdue
        ? "color:var(--status-error);font-weight:500"
        : "color:var(--txt-secondary)";
      const text = overdue
        ? Tf("datasets.retention_expired_at", { date: formatted })
        : Tf("datasets.retention_expires_at", { date: formatted });
      return ` · <span style="${cls}">${escHtml(text)}</span>`;
    }

    function renderOverdueBanner(overdueDatasets) {
      if (!overdueDatasets.length) return "";
      const count = overdueDatasets.length;
      return `<div class="tx-card p-4" style="background:rgba(220,38,38,0.08);border-color:var(--status-error)">
        <div class="flex items-start justify-between gap-3 flex-wrap">
          <div class="flex-1 min-w-0">
            <div class="font-semibold" style="color:var(--status-error)">
              ${ICONS.warning} ${Tf("datasets.retention_overdue_title", { count })}
            </div>
            <p class="text-sm tx-secondary mt-1">${T("datasets.retention_overdue_hint")}</p>
            <ul class="text-xs tx-secondary mt-2 space-y-0.5">
              ${overdueDatasets
                .slice(0, 5)
                .map(
                  (d) =>
                    `<li>${escHtml(datasetDisplayName(d))} — ${escHtml(formatDate(d.updated_at || d.created_at))}</li>`,
                )
                .join("")}
              ${overdueDatasets.length > 5 ? `<li>… ${overdueDatasets.length - 5} more</li>` : ""}
            </ul>
          </div>
          <div class="flex items-center gap-2">
            <button data-action="retention-dismiss" class="tx-btn tx-btn-sm tx-btn-ghost">${T("app.cancel")}</button>
            <button data-action="retention-delete-overdue" class="tx-btn tx-btn-sm" style="background:var(--status-error);color:white">${ICONS.trash} ${T("datasets.retention_delete_now")}</button>
          </div>
        </div>
      </div>`;
    }

    function renderDatasetDetail(c) {
      const d = state.selectedDataset;
      const templates = collectionTemplates(c);
      const hasPreview = templates.length > 0 && state.previewVisible;
      const canShowPreview = templates.length > 0 && !state.previewVisible;

      const retention = renderRetentionPicker(d);
      const expiryHint = renderExpiryHint(d);
      const header = `<div class="tx-card p-5">
        <div class="flex items-center justify-between gap-3 flex-wrap">
          <div class="min-w-0 flex-1">
            <h3 class="text-lg font-semibold truncate">${escHtml(datasetDisplayName(d))}</h3>
            <div class="text-xs tx-secondary mt-0.5">${T("app.created")}: ${formatDate(d.created_at)}${expiryHint}</div>
          </div>
          <div class="flex items-center gap-2 flex-wrap">
            ${retention}
            ${statusBadge(d.status || "draft")}
            ${canShowPreview ? `<button data-action="preview-show" class="tx-btn tx-btn-sm tx-btn-ghost" title="${T("preview.show")}">${ICONS.doc} ${T("preview.show")}</button>` : ""}
            <button data-delete-dataset="${d.id}" class="tx-btn tx-btn-sm tx-btn-ghost" style="color:var(--status-error)">${ICONS.trash}</button>
          </div>
        </div>
      </div>`;

      const leftColumn = `<div class="space-y-4">
        ${renderDatasetDataSection(c, d)}
        ${renderDatasetFilesSection(d)}
        ${renderDatasetExtractionSection(d)}
        ${renderDatasetVariablesSection(d)}
        ${renderDatasetGenerateSection(c, d)}
      </div>`;

      const rightColumn = hasPreview
        ? `<div class="tx-card tx-preview-panel" id="tx-preview-panel">${renderDatasetPreviewPanel(c, d, templates)}</div>`
        : "";

      const splitClass = hasPreview ? "tx-split has-preview" : "tx-split";

      return `<div class="space-y-4">
        <button data-action="datasets-back" class="flex items-center gap-1 text-sm transition-colors" style="color:var(--txt-secondary)">${ICONS.back} ${T("app.back")}</button>
        ${header}
        <div class="${splitClass}">
          ${leftColumn}
          ${rightColumn}
        </div>
      </div>`;
    }

    function renderDatasetPreviewPanel(c, d, templates) {
      const currentId =
        state.previewTemplateId &&
        templates.some((t) => t.id === state.previewTemplateId)
          ? state.previewTemplateId
          : templates[0].id;

      const options = templates
        .map(
          (t) =>
            `<option value="${escHtml(t.id)}"${t.id === currentId ? " selected" : ""}>${escHtml(t.name || t.id)}</option>`,
        )
        .join("");

      const toolbar = `<div class="tx-preview-toolbar">
        <span class="text-xs uppercase tracking-wider tx-secondary" style="letter-spacing:.04em">${T("preview.title")}</span>
        <select id="tx-preview-template-picker" class="tx-select" style="max-width:220px;padding:.25rem .5rem;font-size:.8125rem">${options}</select>
        <button data-action="preview-refresh" class="tx-btn tx-btn-sm tx-btn-ghost" title="${T("preview.refresh")}">${ICONS.refresh}</button>
        <button data-action="preview-render-pdf" class="tx-btn tx-btn-sm tx-btn-ghost" title="${T("preview.render_pdf_hint")}"${state.previewPdfLoading ? " disabled" : ""}>
          ${state.previewPdfLoading ? `<span class="animate-spin inline-block w-3.5 h-3.5 border-2 border-current border-t-transparent rounded-full"></span>` : ICONS.sparkle}
          ${T("preview.render_pdf")}
        </button>
        <button data-action="preview-hide" class="tx-btn tx-btn-sm tx-btn-ghost ml-auto" title="${T("preview.hide")}">${ICONS.close}</button>
      </div>`;

      let body = "";
      if (state.previewPdfUrl) {
        body = `<div class="tx-preview-viewport" style="padding:0;background:#f6f6f6">
          <div class="flex items-center gap-2 p-2" style="border-bottom:1px solid var(--divider);background:var(--bg-card)">
            <span class="text-xs tx-secondary">${T("preview.showing_pdf")}</span>
            <button data-action="preview-back-html" class="tx-btn tx-btn-sm tx-btn-ghost ml-auto">${T("preview.back_to_html")}</button>
          </div>
          <iframe src="${escHtml(state.previewPdfUrl)}" style="width:100%;height:calc(100vh - 9rem);border:0"></iframe>
        </div>`;
      } else if (state.previewLoading) {
        body = `<div class="tx-preview-missing">
          <span class="animate-spin inline-block w-5 h-5 border-2 border-current border-t-transparent rounded-full"></span>
          <p class="mt-2">${T("preview.loading")}</p>
        </div>`;
      } else if (state.previewError) {
        body = `<div class="tx-preview-missing"><p>${escHtml(state.previewError)}</p></div>`;
      } else if (state.previewSkeleton && state.previewSkeleton.html) {
        const banner = state.previewPdfUnavailable
          ? `<div class="tx-callout m-3" style="font-size:.75rem">${T("preview.pdf_unavailable")}</div>`
          : "";
        body = `<div class="tx-preview-viewport">
          ${banner}
          <div id="tx-preview-root" data-template-id="${escHtml(currentId)}">${state.previewSkeleton.html}</div>
        </div>`;
      } else {
        body = `<div class="tx-preview-missing"><p>${T("preview.loading")}</p></div>`;
      }

      return toolbar + body;
    }

    function renderDatasetDataSection(c, d) {
      const formData = d.field_values || {};
      const fields = c.fields || [];
      const isReordering = state.reorderingFields;
      let inner = "";
      if (fields.length === 0) {
        inner = `<p class="text-sm tx-secondary">${T("app.no_data")}</p>`;
      } else if (isReordering) {
        inner = `<div id="tx-entry-data-form" class="grid grid-cols-1 sm:grid-cols-2 gap-2">${fields
          .map(
            (
              f,
              idx,
            ) => `<div class="flex items-start gap-1.5 group rounded-lg p-2" style="border:1px dashed var(--divider);background:var(--bg-secondary)" data-field-idx="${idx}">
              <div class="flex flex-col gap-0.5 mt-1 shrink-0">
                <button type="button" data-action="df-up" data-idx="${idx}" class="p-0.5" style="color:var(--txt-secondary)${idx === 0 ? ";opacity:0.3" : ""}" ${idx === 0 ? "disabled" : ""}>${ICONS.arrowUp}</button>
                <button type="button" data-action="df-down" data-idx="${idx}" class="p-0.5" style="color:var(--txt-secondary)${idx === fields.length - 1 ? ";opacity:0.3" : ""}" ${idx === fields.length - 1 ? "disabled" : ""}>${ICONS.arrowDown}</button>
              </div>
              <div class="flex-1 min-w-0">
                <span class="text-sm font-medium">${escHtml(f.label || f.key)}</span>
                <span class="text-xs tx-secondary ml-1">(${escHtml(T(`variables.type_${f.type || "text"}`, f.type || "text"))})</span>
              </div>
            </div>`,
          )
          .join("")}</div>`;
      } else {
        inner = `<form id="tx-entry-data-form" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          ${fields.map((f) => renderFormFieldWithValue(f, formData[f.key])).join("")}
          <div class="sm:col-span-2"><button type="submit" class="tx-btn tx-btn-sm">${T("app.save")}</button></div>
        </form>`;
      }
      return `<div class="tx-card p-5">
        <div class="flex items-center gap-2 mb-3">
          <h4 class="text-sm font-semibold uppercase tracking-wider tx-secondary flex items-center gap-2">${ICONS.variable} ${T("datasets.section_data")}</h4>
          <span class="text-xs tx-secondary">— ${T("datasets.section_data_hint")}</span>
          ${
            fields.length > 1
              ? `<button data-action="toggle-reorder" class="ml-auto text-xs tx-link flex items-center gap-1">${ICONS.grip} ${isReordering ? T("app.done") : T("variables.reorder")}</button>`
              : ""
          }
        </div>
        ${inner}
      </div>`;
    }

    function renderFormFieldWithValue(field, value) {
      const fid = `tx-field-${escHtml(field.key)}`;
      const req = field.required ? "required" : "";
      const reqMark = field.required
        ? ' <span class="text-red-500">*</span>'
        : "";
      const hint = field.hint
        ? `<p class="tx-hint">${escHtml(field.hint)}</p>`
        : "";
      const wideTypes = ["textarea", "list", "table", "image"];
      const span = wideTypes.includes(field.type) ? "sm:col-span-2" : "";

      if (field.type === "checkbox") {
        return `<div class="${span}">
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" id="${fid}" name="${escHtml(field.key)}" ${value === true || value === "true" || value === "Ja" ? "checked" : ""} class="rounded h-4 w-4" style="accent-color:var(--brand)" />
            <span class="text-sm font-medium">${escHtml(field.label || field.key)}${reqMark}</span>
          </label>${hint}
        </div>`;
      }

      if (field.type === "image") {
        const meta = value && typeof value === "object" ? value : null;
        const d = state.selectedDataset;
        const thumbUrl =
          meta && d
            ? `${BASE}/candidates/${d.id}/image/${encodeURIComponent(field.key)}?v=${encodeURIComponent(meta.uploaded_at || "")}`
            : null;
        const hasImage = !!thumbUrl;
        return `<div class="${span}">
          ${label}
          <div class="flex items-start gap-3 p-3 rounded" style="background:var(--bg-app);border:1px dashed var(--divider)">
            <div style="width:96px;height:120px;flex:none;background:var(--bg-card);border-radius:.375rem;overflow:hidden;display:flex;align-items:center;justify-content:center;color:var(--txt-secondary)">
              ${hasImage ? `<img src="${escHtml(thumbUrl)}" alt="${escHtml(field.label || field.key)}" style="width:100%;height:100%;object-fit:cover" />` : ICONS.doc}
            </div>
            <div class="flex-1 min-w-0 space-y-2">
              <p class="text-sm tx-secondary">${hasImage ? T("datasets.image_uploaded") : T("datasets.image_empty")}</p>
              ${hasImage && meta?.original_name ? `<p class="text-xs tx-secondary truncate">${escHtml(meta.original_name)}</p>` : ""}
              <div class="flex items-center gap-2">
                <label class="tx-btn tx-btn-sm tx-btn-ghost cursor-pointer">
                  ${ICONS.upload} ${hasImage ? T("datasets.image_replace") : T("datasets.image_upload")}
                  <input type="file" data-action="image-upload" data-key="${escHtml(field.key)}" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp" class="hidden" />
                </label>
                ${hasImage ? `<button type="button" data-action="image-remove" data-key="${escHtml(field.key)}" class="tx-btn tx-btn-sm tx-btn-ghost" style="color:var(--status-error)">${ICONS.trash} ${T("app.delete")}</button>` : ""}
              </div>
              ${hint}
            </div>
          </div>
        </div>`;
      }

      const label = `<label for="${fid}" class="tx-label">${escHtml(field.label || field.key)}${reqMark}</label>`;
      let input;
      switch (field.type) {
        case "textarea":
          input = `<textarea id="${fid}" name="${escHtml(field.key)}" class="tx-textarea" rows="3" ${req}>${escHtml(String(value ?? ""))}</textarea>`;
          break;
        case "select": {
          const opts = (field.options || [])
            .map((o) => {
              const v = typeof o === "object" ? o.value : o;
              const l = typeof o === "object" ? o.label || o.value : o;
              const sel = String(value) === String(v) ? " selected" : "";
              return `<option value="${escHtml(v)}"${sel}>${escHtml(l)}</option>`;
            })
            .join("");
          input = `<select id="${fid}" name="${escHtml(field.key)}" class="tx-select" ${req}><option value="">—</option>${opts}</select>`;
          break;
        }
        case "list": {
          const lv = Array.isArray(value)
            ? value.join("\n")
            : String(value ?? "");
          input = `<textarea id="${fid}" name="${escHtml(field.key)}" class="tx-textarea" rows="3" placeholder="${T("variables.list_placeholder")}" ${req}>${escHtml(lv)}</textarea>`;
          break;
        }
        case "date":
          input = `<input type="date" id="${fid}" name="${escHtml(field.key)}" value="${escHtml(String(value ?? ""))}" class="tx-input" ${req} />`;
          break;
        case "number":
          input = `<input type="number" id="${fid}" name="${escHtml(field.key)}" value="${escHtml(String(value ?? ""))}" class="tx-input" ${req} />`;
          break;
        case "table": {
          const cols = field.columns || [];
          const rows = Array.isArray(value) ? value : [];
          const hc = cols
            .map(
              (c) =>
                `<th class="py-1.5 px-2 text-xs tx-secondary font-medium">${escHtml(c.label || c.key)}</th>`,
            )
            .join("");
          const dr = rows
            .map((row, ri) => {
              const cells = cols
                .map((c) => {
                  const colType = c.type || "text";
                  const rawVal = row[c.key];
                  // List-typed columns store arrays; display as one-per-line
                  // inside a textarea. On save the harvest layer splits back.
                  if (colType === "list") {
                    const asArr = Array.isArray(rawVal)
                      ? rawVal
                      : String(rawVal ?? "")
                          .split(/\r?\n/)
                          .filter((s) => s.trim() !== "");
                    const txt = asArr.join("\n");
                    return `<td class="py-1 px-1" style="vertical-align:top"><textarea name="${escHtml(field.key)}__${ri}__${escHtml(c.key)}" rows="${Math.max(2, asArr.length)}" class="tx-textarea text-xs" style="padding:.25rem .375rem;min-height:2.25rem;font-size:.75rem" placeholder="${T("datasets.table_list_placeholder")}">${escHtml(txt)}</textarea></td>`;
                  }
                  if (colType === "textarea") {
                    return `<td class="py-1 px-1" style="vertical-align:top"><textarea name="${escHtml(field.key)}__${ri}__${escHtml(c.key)}" rows="2" class="tx-textarea text-xs" style="padding:.25rem .375rem;font-size:.75rem">${escHtml(String(rawVal ?? ""))}</textarea></td>`;
                  }
                  const inputType =
                    colType === "number"
                      ? "number"
                      : colType === "date"
                        ? "date"
                        : "text";
                  return `<td class="py-1 px-1"><input type="${inputType}" name="${escHtml(field.key)}__${ri}__${escHtml(c.key)}" value="${escHtml(String(rawVal ?? ""))}" class="tx-input text-xs" style="padding:.25rem .375rem" /></td>`;
                })
                .join("");
              return `<tr class="tx-divider border-t">${cells}<td class="py-1 px-1 text-center" style="vertical-align:top"><button type="button" data-action="remove-table-row" data-field-key="${escHtml(field.key)}" data-row-idx="${ri}" class="p-0.5" style="color:var(--txt-secondary)">${ICONS.trash}</button></td></tr>`;
            })
            .join("");
          const empty =
            rows.length === 0
              ? `<tr><td colspan="${cols.length + 1}" class="py-3 text-center text-xs tx-secondary">${T("datasets.table_empty")}</td></tr>`
              : "";
          input = `<div class="overflow-x-auto rounded" style="border:1px solid var(--divider)">
            <table class="w-full text-left">
              <thead><tr class="tx-divider border-b">${hc}<th class="py-1.5 px-2" style="width:2rem"></th></tr></thead>
              <tbody>${dr}${empty}</tbody>
            </table>
          </div>
          <button type="button" data-action="add-table-row" data-field-key="${escHtml(field.key)}" class="mt-1.5 tx-link text-xs flex items-center gap-1">${ICONS.plus} ${T("datasets.add_row")}</button>
          <input type="hidden" name="${escHtml(field.key)}__table_marker" value="1" />`;
          break;
        }
        default:
          input = `<input type="text" id="${fid}" name="${escHtml(field.key)}" value="${escHtml(String(value ?? ""))}" class="tx-input" ${req} />`;
      }
      return `<div class="${span}">${label}${input}${hint}</div>`;
    }

    function renderDatasetFilesSection(d) {
      const cv = d.files?.cv;
      const additional = d.files?.additional || [];
      const urls = d.files?.urls || [];
      const all = [];
      if (cv) all.push({ ...cv, slot: "cv", slotIndex: 0 });
      for (let i = 0; i < additional.length; i++)
        all.push({ ...additional[i], slot: "additional", slotIndex: i });

      const urlRows = urls
        .map((u, i) => {
          const ok = u.text_snippet && !u.fetch_error;
          const warning = u.fetch_error && !u.text_snippet;
          const statusIcon = ok
            ? `<span style="color:var(--status-success)" title="${T("datasets.url_fetched")}">${ICONS.check}</span>`
            : warning
              ? `<span style="color:var(--status-error)" title="${escHtml(u.fetch_error || "")}">${ICONS.warning}</span>`
              : `<span style="color:var(--status-warning,#d97706)" title="${escHtml(u.fetch_error || "")}">${ICONS.warning}</span>`;
          const kindBadge = u.kind
            ? `<span class="tx-badge" style="background:var(--bg-chip);color:var(--txt-secondary)">${escHtml(u.kind)}</span>`
            : "";
          return `<div class="flex items-center gap-2 py-1.5 group">
            ${statusIcon}
            <a href="${escHtml(u.url)}" target="_blank" rel="noopener noreferrer" class="text-xs flex-1 truncate tx-link" title="${escHtml(u.url)}">${escHtml(u.label || u.host || u.url)}</a>
            ${kindBadge}
            ${u.text_snippet ? `<span class="text-xs tx-secondary">${u.text_snippet.length.toLocaleString()} chars</span>` : ""}
            <button data-action="refresh-url" data-url-index="${i}" class="p-1 rounded opacity-0 group-hover:opacity-100" style="color:var(--txt-secondary)" title="${T("datasets.url_refetch")}">${ICONS.refresh || ICONS.sparkle}</button>
            <button data-action="delete-source-file" data-slot="urls" data-slot-index="${i}" class="p-1 rounded opacity-0 group-hover:opacity-100" style="color:var(--txt-secondary)">${ICONS.trash}</button>
          </div>`;
        })
        .join("");

      const list =
        all.length || urls.length
          ? all
              .map(
                (f) =>
                  `<div class="flex items-center gap-2 py-1.5 group">
                  <span style="color:var(--status-success)">${ICONS.check}</span>
                  <span class="text-xs flex-1 truncate">${escHtml(f.filename)}</span>
                  <span class="text-xs tx-secondary">${f.size ? (f.size / 1024 > 1024 ? (f.size / 1048576).toFixed(1) + " MB" : Math.round(f.size / 1024) + " KB") : ""}</span>
                  <button data-action="delete-source-file" data-slot="${escHtml(f.slot)}" data-slot-index="${f.slotIndex}" class="p-1 rounded opacity-0 group-hover:opacity-100" style="color:var(--txt-secondary)">${ICONS.trash}</button>
                </div>`,
              )
              .join("") + urlRows
          : `<p class="text-xs tx-secondary py-2">${T("datasets.no_files")}</p>`;

      let progress = "";
      if (state.datasetParsing) {
        const steps = [
          T("datasets.analyze_step_upload"),
          T("datasets.analyze_step_ocr"),
          T("datasets.analyze_step_ai"),
          T("datasets.analyze_step_done"),
        ];
        const step = state.datasetParseStep || 0;
        const elapsed = state.datasetParseElapsedSec || 0;
        const fileCount = state.datasetParseFileCount || 0;
        const hasImages = !!state.datasetParseHasImages;
        const expectedSec = Math.max(8, fileCount * (hasImages ? 12 : 4));
        const timeBasedPct = Math.min(
          85,
          Math.round((elapsed / expectedSec) * 85),
        );
        const pct = Math.max(15 + step * 25, timeBasedPct);
        const cappedPct = Math.min(95, pct);
        const statusLine =
          step >= 2
            ? T("datasets.analyze_status_running_ai")
            : Tf("datasets.analyze_status_reading_files", { count: fileCount });
        const hintLine = hasImages
          ? T("datasets.analyze_status_image_hint")
          : T("datasets.analyze_status_doc_hint");
        progress = `<div class="mt-3 space-y-2">
          <div class="flex items-center justify-between gap-2 text-sm font-medium" style="color:var(--brand)">
            <div class="flex items-center gap-2">
              <div class="animate-spin rounded-full h-4 w-4 border-2 border-current border-t-transparent"></div>
              ${T("datasets.analyzing")}
            </div>
            <span class="text-xs font-mono tx-secondary" aria-live="polite">${Tf("datasets.analyze_status_elapsed", { seconds: elapsed })}</span>
          </div>
          <div class="w-full rounded-full h-2" style="background:var(--divider)">
            <div class="h-2 rounded-full transition-all duration-700" style="width:${cappedPct}%;background:var(--brand)"></div>
          </div>
          <div class="flex justify-between text-xs tx-secondary">
            ${steps.map((s, i) => `<span${i <= step ? ' style="color:var(--brand);font-weight:500"' : ""}>${s}</span>`).join("")}
          </div>
          <div class="text-xs tx-secondary">${escHtml(statusLine)}</div>
          <div class="text-xs tx-secondary" style="opacity:0.85">${escHtml(hintLine)}</div>
          <div class="text-xs" style="color:var(--status-warning,#d97706);opacity:0.9">${escHtml(T("datasets.analyze_dont_close"))}</div>
        </div>`;
      }

      const hasAnySource = all.length > 0 || urls.length > 0;
      const urlFormOpen = state.urlFormOpen;
      const urlFormMarkup = urlFormOpen
        ? `<div class="mt-2 p-3 rounded tx-card space-y-2" style="background:var(--bg-app)">
            <label class="tx-label">${T("datasets.url_add_label")}</label>
            <input id="tx-url-input" type="url" class="tx-input" placeholder="${T("datasets.url_add_placeholder")}" />
            <input id="tx-url-label" type="text" class="tx-input" placeholder="${T("datasets.url_label_placeholder")}" />
            <div class="flex items-center gap-2">
              <button data-action="save-url" class="tx-btn tx-btn-sm"${state.urlAdding ? " disabled" : ""}>
                ${state.urlAdding ? `<span class="animate-spin inline-block w-3 h-3 border-2 border-white border-t-transparent rounded-full"></span>` : ICONS.upload}
                ${state.urlAdding ? T("datasets.url_adding") : T("datasets.url_add_btn")}
              </button>
              <button data-action="cancel-url" class="tx-btn tx-btn-sm tx-btn-ghost">${T("app.cancel")}</button>
              ${state.urlAddError ? `<span class="text-xs" style="color:var(--status-error)">${escHtml(state.urlAddError)}</span>` : ""}
            </div>
            <p class="text-xs tx-secondary">${T("datasets.url_add_hint")}</p>
          </div>`
        : "";

      return `<div class="tx-card p-5">
        <div class="flex items-center gap-2 mb-3">
          <h4 class="text-sm font-semibold uppercase tracking-wider tx-secondary flex items-center gap-2">${ICONS.file} ${T("datasets.section_files")}</h4>
          <span class="text-xs tx-secondary">— ${T("datasets.section_files_hint")}</span>
        </div>
        <div class="space-y-1">${list}</div>
        <div class="mt-3 flex items-center gap-2 flex-wrap">
          <label class="tx-btn tx-btn-sm tx-btn-ghost cursor-pointer">
            ${ICONS.upload} ${all.length ? T("datasets.upload_more") : T("datasets.upload_doc")}
            <input type="file" id="tx-doc-upload" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.tiff,.tif,.bmp,.txt,.rtf,.odt,.xls,.xlsx,.pptx" multiple class="hidden" />
          </label>
          <button data-action="toggle-url-form" class="tx-btn tx-btn-sm tx-btn-ghost">
            ${ICONS.link || ICONS.sparkle} ${T("datasets.url_add_btn")}
          </button>
          ${
            hasAnySource && !state.datasetParsing
              ? `<button data-action="parse-documents" class="tx-btn tx-btn-sm">${ICONS.sparkle} ${T("datasets.parse_btn")}</button>
                 <button type="button" class="tx-help-trigger" title="${T("datasets.parse_hint")}">${ICONS.question}</button>`
              : ""
          }
        </div>
        ${urlFormMarkup}
        ${progress}
      </div>`;
    }

    // Status-only display for the AI extraction stage. The actual
    // trigger is the unified "Read files & auto-fill" button in the
    // Source Documents section above; this card only surfaces the
    // outcome (and the model used) so users still get feedback that
    // their data has been extracted.
    function renderDatasetExtractionSection(d) {
      const isExtracted =
        d.status === "extracted" ||
        d.status === "reviewed" ||
        d.status === "generated";
      if (!isExtracted && !state.datasetParsing) {
        return "";
      }
      const info = d.ai_extracted || {};
      const modelInfo = info.model_used
        ? ` · ${T("datasets.extract_model")}: <span class="font-mono text-xs">${escHtml(info.model_used)}</span>`
        : "";
      const line = state.datasetParsing
        ? `<div class="flex items-center gap-2 text-sm" style="color:var(--brand)">
            <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-current"></div>
            ${T("datasets.extract_running")}
          </div>`
        : `<div class="flex items-center gap-2 text-sm" style="color:var(--status-success)">
            ${ICONS.check} ${T("datasets.extract_done")}${modelInfo}
          </div>`;
      return `<div class="tx-card p-5">
        <div class="flex items-center gap-2 mb-3">
          <h4 class="text-sm font-semibold uppercase tracking-wider tx-secondary flex items-center gap-2">${ICONS.sparkle} ${T("datasets.section_extraction")}</h4>
          <span class="text-xs tx-secondary">— ${T("datasets.section_extraction_hint")}</span>
        </div>
        ${line}
      </div>`;
    }

    function renderDatasetVariablesSection(d) {
      if (state.datasetVariablesLoading) {
        return `<div class="tx-card p-5">
          <h4 class="text-sm font-semibold uppercase tracking-wider tx-secondary flex items-center gap-2 mb-3">${ICONS.variable} ${T("datasets.section_variables")}</h4>
          <div class="flex items-center gap-2 py-4">
            <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-blue-500"></div>
            <span class="text-sm tx-secondary">${T("app.loading")}</span>
          </div>
        </div>`;
      }
      const vars = state.datasetVariables;
      if (!vars || vars.length === 0) {
        return `<div class="tx-card p-5">
          <h4 class="text-sm font-semibold uppercase tracking-wider tx-secondary flex items-center gap-2 mb-3">${ICONS.variable} ${T("datasets.section_variables")}</h4>
          <p class="text-sm tx-secondary">${T("app.no_data")}</p>
        </div>`;
      }
      const regular = vars.filter((v) => v.type !== "table");
      const tables = vars.filter((v) => v.type === "table");
      const rows = regular.map((v) => renderVarRow(v, d.id)).join("");
      const tblGroups = tables.map((v) => renderTableGroup(v)).join("");
      return `<div class="tx-card p-5">
        <h4 class="text-sm font-semibold uppercase tracking-wider tx-secondary flex items-center gap-2 mb-3">${ICONS.variable} ${T("datasets.section_variables")}</h4>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead>
              <tr class="tx-divider border-b text-xs tx-secondary uppercase tracking-wider">
                <th class="py-2 px-3">${T("datasets.var_key")}</th>
                <th class="py-2 px-3">${T("datasets.var_value")}</th>
                <th class="py-2 px-3">${T("datasets.var_source")}</th>
                <th class="py-2 px-3 text-right"></th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        ${tblGroups}
      </div>`;
    }

    function renderVarRow(v, datasetId) {
      const isEditing = state.editingVarKey === v.key;
      const src =
        v.source === "ai"
          ? T("datasets.source_ai")
          : v.source === "override"
            ? T("datasets.source_override")
            : T("datasets.source_form");
      const srcCls =
        v.source === "ai"
          ? "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400"
          : v.source === "override"
            ? "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400"
            : "bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300";
      let disp;
      if (Array.isArray(v.value)) {
        disp = v.value.map((i) => escHtml(String(i))).join("<br>");
      } else {
        disp =
          v.value != null && v.value !== ""
            ? escHtml(String(v.value))
            : `<span class="text-gray-400 italic">${T("datasets.var_not_set")}</span>`;
      }
      if (isEditing) {
        const ev = escHtml(
          Array.isArray(v.value) ? v.value.join("\n") : String(v.value ?? ""),
        );
        return `<tr class="tx-divider border-t bg-blue-50/50 dark:bg-blue-900/10">
          <td class="py-2 px-3 font-mono text-sm align-top">${escHtml(v.key)}</td>
          <td class="py-2 px-3" colspan="2">
            <textarea id="tx-override-input" class="tx-textarea" rows="2">${ev}</textarea>
          </td>
          <td class="py-2 px-3 text-right align-top">
            <div class="flex items-center gap-1 justify-end">
              <button data-action="save-override" data-var-key="${escHtml(v.key)}" class="p-1 rounded" style="color:var(--status-success)" title="${T("app.save")}">${ICONS.check}</button>
              <button data-action="cancel-override" class="p-1 rounded" style="color:var(--txt-secondary)">${ICONS.close}</button>
            </div>
          </td>
        </tr>`;
      }
      return `<tr class="tx-divider border-t">
        <td class="py-2 px-3 font-mono text-sm">${escHtml(v.key)}</td>
        <td class="py-2 px-3 text-sm">${disp}</td>
        <td class="py-2 px-3"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${srcCls}">${src}</span></td>
        <td class="py-2 px-3 text-right">
          <button data-action="override-var" data-var-key="${escHtml(v.key)}" class="text-xs tx-link flex items-center gap-1 ml-auto">${ICONS.edit} ${T("datasets.override")}</button>
        </td>
      </tr>`;
    }

    function renderTableGroup(v) {
      const rows = Array.isArray(v.value) ? v.value : [];
      if (rows.length === 0) return "";
      const cols =
        v.columns && v.columns.length > 0
          ? v.columns
          : Object.keys(rows[0] || {}).map((k) => ({ key: k, label: k }));
      const h = cols
        .map(
          (c) =>
            `<th class="py-1.5 px-2 text-xs tx-secondary font-medium">${escHtml(c.label || c.key)}</th>`,
        )
        .join("");
      const b = rows
        .map(
          (row) =>
            `<tr class="tx-divider border-t">${cols.map((c) => `<td class="py-1.5 px-2 text-xs">${escHtml(String(row[c.key] ?? ""))}</td>`).join("")}</tr>`,
        )
        .join("");
      return `<div class="mt-4">
        <h5 class="text-sm font-medium mb-2">${escHtml(v.label || v.key)} (${rows.length})</h5>
        <div class="overflow-x-auto">
          <table class="w-full text-left" style="border:1px solid var(--divider);border-radius:.375rem">
            <thead><tr class="tx-divider border-b">${h}</tr></thead>
            <tbody>${b}</tbody>
          </table>
        </div>
      </div>`;
    }

    function renderDatasetGenerateSection(c, d) {
      const hasVars =
        state.datasetVariables &&
        state.datasetVariables.filter((v) => v.type !== "table").length > 0;
      const tpls = collectionTemplates(c);
      if (!hasVars) {
        return `<div class="tx-card p-5">
          <h4 class="text-sm font-semibold uppercase tracking-wider tx-secondary flex items-center gap-2 mb-3">${ICONS.doc} ${T("datasets.section_generate")}</h4>
          <p class="text-sm tx-secondary">${T("datasets.generate_no_vars")}</p>
        </div>`;
      }
      const opts = tpls
        .map(
          (t) =>
            `<option value="${t.id}"${state.selectedGenerateTemplate == t.id ? " selected" : ""}>${escHtml(t.name)}</option>`,
        )
        .join("");
      const docsMap = d.documents || {};
      const docs = Object.values(docsMap);
      const docRows = docs
        .map(
          (doc) =>
            `<div class="flex items-center justify-between py-2 tx-divider border-b last:border-0">
              <div>
                <span class="text-sm font-medium">${escHtml(doc.template_name || doc.name || T("datasets.download_doc"))}</span>
                <span class="text-xs tx-secondary ml-2">${formatDate(doc.created_at || doc.generated_at)}</span>
              </div>
              <div class="flex items-center gap-2">
                <button data-action="download-doc" data-doc-id="${doc.id}" class="flex items-center gap-1 text-sm tx-link">${ICONS.download} ${T("datasets.download_doc")}</button>
                <button data-action="delete-doc" data-doc-id="${doc.id}" class="flex items-center gap-1 text-sm" style="color:var(--status-error)">${ICONS.trash}</button>
              </div>
            </div>`,
        )
        .join("");
      return `<div class="tx-card p-5">
        <div class="flex items-center gap-2 mb-3">
          <h4 class="text-sm font-semibold uppercase tracking-wider tx-secondary flex items-center gap-2">${ICONS.doc} ${T("datasets.section_generate")}</h4>
          <span class="text-xs tx-secondary">— ${T("datasets.section_generate_hint")}</span>
        </div>
        ${
          tpls.length
            ? `<div class="flex items-center gap-2 mb-4">
                <select id="tx-generate-template" class="tx-select flex-1">
                  <option value="">— ${T("datasets.generate_select_tpl")} —</option>
                  ${opts}
                </select>
                <button data-action="generate" class="tx-btn tx-btn-sm" ${!state.selectedGenerateTemplate || state.datasetGenerating ? "disabled" : ""}>
                  ${state.datasetGenerating ? `<div class="animate-spin rounded-full h-3 w-3 border-b-2 border-white"></div> ${T("datasets.generate_running")}` : `${ICONS.doc} ${T("datasets.generate_btn")}`}
                </button>
              </div>`
            : `<div class="tx-callout mb-4">${T("templates.empty_hint")} <button data-tab="templates" class="tx-link ml-2">${T("collection.goto_templates")} →</button></div>`
        }
        <div>
          <h5 class="text-xs font-medium tx-secondary uppercase tracking-wider mb-2">${T("datasets.generated_docs")}</h5>
          ${docs.length ? `<div class="rounded border px-3" style="border-color:var(--divider)">${docRows}</div>` : `<p class="text-sm tx-secondary">${T("datasets.no_generated_docs")}</p>`}
        </div>
      </div>`;
    }

    // --- Export tab ---

    function renderExportTab(c) {
      const allDatasets = datasetsForCollection(c.id);
      const filtered = filterDatasetsForExport(allDatasets);
      const fields = c.fields || [];
      const columns = [
        "id",
        "name",
        "status",
        "created_at",
        "updated_at",
        "source_files",
        "generated_documents",
        ...fields.map((f) => f.key),
      ];

      const header = `<div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <div class="flex items-center gap-2 mb-1">
            <h3 class="text-lg font-semibold">${T("export.title")}</h3>
            ${helpTrigger("export")}
          </div>
          <p class="text-sm tx-secondary" style="max-width:640px">${T("export.subtitle")}</p>
        </div>
      </div>`;

      if (allDatasets.length === 0) {
        return `<div class="space-y-4">${header}
          <div class="tx-card p-8 text-center">
            <p class="text-sm tx-secondary">${T("export.empty")}</p>
          </div>
        </div>`;
      }

      const filters = `<div class="tx-card p-5 space-y-3">
        <h4 class="text-sm font-semibold">${T("export.filters_title")}</h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="tx-label">${T("export.filter_status")}</label>
            <select id="tx-export-status" class="tx-select">
              <option value="">${T("export.filter_status_any")}</option>
              ${["draft", "extracted", "reviewed", "generated", "incomplete"]
                .map(
                  (s) =>
                    `<option value="${s}"${state.exportStatus === s ? " selected" : ""}>${T(`datasets.status_${s}`)}</option>`,
                )
                .join("")}
            </select>
          </div>
          <div>
            <label class="tx-label">${T("export.filter_search")}</label>
            <input id="tx-export-search" type="text" class="tx-input" value="${escHtml(state.exportSearch)}" placeholder="${T("app.search")}" />
          </div>
          <div>
            <label class="tx-label">${T("export.filter_from")}</label>
            <input id="tx-export-from" type="date" class="tx-input" value="${escHtml(state.exportFrom)}" />
          </div>
          <div>
            <label class="tx-label">${T("export.filter_to")}</label>
            <input id="tx-export-to" type="date" class="tx-input" value="${escHtml(state.exportTo)}" />
          </div>
        </div>
      </div>`;

      const preview = `<div class="tx-card p-5 space-y-3">
        <div class="flex items-center justify-between">
          <p class="text-sm font-medium">${filtered.length === 0 ? T("export.preview_none") : Tf("export.preview_count", { count: filtered.length })}</p>
          <button data-action="export-csv" class="tx-btn tx-btn-sm" ${filtered.length === 0 ? "disabled" : ""}>${ICONS.download} ${T("export.download_csv")}</button>
        </div>
        <div class="text-xs tx-secondary">
          <span class="font-medium">${T("export.preview_columns")}:</span>
          ${columns.map((col) => `<code class="inline-block mr-1 mb-1" style="background:var(--bg-chip);padding:.125rem .375rem;border-radius:.25rem">${escHtml(col)}</code>`).join("")}
        </div>
        <p class="tx-hint">${T("export.encoding_hint")}</p>
      </div>`;

      return `<div class="space-y-4">${header}${filters}${preview}</div>`;
    }

    function filterDatasetsForExport(list) {
      let out = list.slice();
      if (state.exportStatus)
        out = out.filter((d) => d.status === state.exportStatus);
      if (state.exportSearch) {
        const q = state.exportSearch.toLowerCase();
        out = out.filter((d) => {
          const hay = [
            datasetDisplayName(d),
            ...Object.values(d.field_values || {}).map(String),
            ...Object.values(d.ai_extracted || {}).map(String),
          ]
            .join(" ")
            .toLowerCase();
          return hay.includes(q);
        });
      }
      if (state.exportFrom) {
        const from = new Date(state.exportFrom).getTime();
        out = out.filter((d) => new Date(d.created_at || 0).getTime() >= from);
      }
      if (state.exportTo) {
        const to = new Date(state.exportTo).getTime() + 86400000; // inclusive end
        out = out.filter((d) => new Date(d.created_at || 0).getTime() < to);
      }
      return out;
    }

    // --- Danger zone ---

    function renderDangerTab(c) {
      const datasets = datasetsForCollection(c.id);
      const warning =
        datasets.length === 0
          ? T("danger.warning_no_datasets")
          : Tf("danger.warning_with_datasets", { count: datasets.length });
      const typed = state.dangerConfirmText || "";
      const matches = typed === c.name;
      return `<div class="space-y-4">
        <div class="tx-danger-zone space-y-3">
          <div class="flex items-start gap-2">
            <span style="color:var(--status-error)">${ICONS.warning}</span>
            <div>
              <h3 class="font-semibold text-base">${T("danger.title")}</h3>
              <p class="text-sm tx-secondary mt-1">${T("danger.subtitle")}</p>
            </div>
          </div>
          <p class="text-sm font-medium" style="color:var(--status-error)">${warning}</p>
          <div class="pt-2 space-y-2">
            <p class="text-sm font-medium">${T("danger.confirm_title")}</p>
            <p class="text-xs tx-secondary">${T("danger.confirm_body")} <code class="font-semibold" style="background:var(--bg-chip);padding:.125rem .375rem;border-radius:.25rem">${escHtml(c.name)}</code></p>
            <input id="tx-danger-input" class="tx-input" value="${escHtml(typed)}" placeholder="${T("danger.confirm_input_placeholder")}" style="max-width:320px;${typed && !matches ? "border-color:var(--status-error);" : ""}" />
            <p id="tx-danger-mismatch" class="text-xs" style="color:var(--status-error);${typed && !matches ? "" : "display:none"}">${T("danger.confirm_mismatch")}</p>
            <div>
              <button data-action="delete-collection" class="tx-btn tx-btn-sm tx-btn-danger" ${matches ? "" : "disabled"}>
                ${ICONS.trash} ${T("danger.confirm_delete_btn")}
              </button>
            </div>
          </div>
        </div>
      </div>`;
    }

    // =========================================================================
    // Settings view
    // =========================================================================

    function renderSettings() {
      const cfg = state.config || {};
      const modelDisplay =
        cfg.extraction_model && cfg.extraction_model !== "default"
          ? cfg.extraction_model
          : T("settings.model_auto");
      return `<div class="mt-4 space-y-4">
        <div class="tx-card p-6">
          <h3 class="text-lg font-semibold mb-4">${T("settings.title")}</h3>
          <form id="tx-settings-form" class="space-y-4 max-w-md">
            <div>
              <label class="tx-label">${T("settings.company_name")}</label>
              <input name="company_name" value="${escHtml(cfg.company_name || "")}" class="tx-input" />
              <p class="tx-hint">${T("settings.company_name_hint")}</p>
            </div>
            <div>
              <label class="tx-label">${T("settings.default_language")}</label>
              <select name="default_language" class="tx-select">
                <option value="de"${cfg.default_language === "de" ? " selected" : ""}>${T("settings.lang_de")}</option>
                <option value="en"${cfg.default_language === "en" ? " selected" : ""}>${T("settings.lang_en")}</option>
                <option value="es"${cfg.default_language === "es" ? " selected" : ""}>${T("settings.lang_es")}</option>
                <option value="tr"${cfg.default_language === "tr" ? " selected" : ""}>${T("settings.lang_tr")}</option>
              </select>
            </div>
            <div>
              <label class="tx-label">${T("settings.extraction_model")}</label>
              <input name="extraction_model" value="${escHtml(cfg.extraction_model || "default")}" class="tx-input" />
              <p class="tx-hint">${T("settings.extraction_model_hint")}</p>
            </div>
            <div>
              <label class="tx-label">${T("settings.validation_model")}</label>
              <input name="validation_model" value="${escHtml(cfg.validation_model || "default")}" class="tx-input" />
              <p class="tx-hint">${T("settings.validation_model_hint")}</p>
            </div>
            <div class="flex items-center gap-3">
              <button type="submit" class="tx-btn">${T("app.save")}</button>
              <span id="tx-settings-msg" class="text-sm hidden" style="color:var(--status-success)">${ICONS.check} ${T("app.saved")}</span>
            </div>
          </form>
        </div>
        <div class="tx-card p-6">
          <h4 class="text-sm font-semibold uppercase tracking-wider tx-secondary mb-3">${T("settings.info_title")}</h4>
          <div class="space-y-2 text-sm">
            <div class="flex justify-between py-1.5 tx-divider border-b">
              <span class="tx-secondary">${T("settings.active_model")}</span>
              <span class="font-mono font-medium">${escHtml(modelDisplay)}</span>
            </div>
            <div class="flex justify-between py-1.5 tx-divider border-b">
              <span class="tx-secondary">${T("settings.default_language")}</span>
              <span class="font-medium">${escHtml(T(`settings.lang_${cfg.default_language || "en"}`, cfg.default_language || "en"))}</span>
            </div>
            <div class="flex justify-between py-1.5">
              <span class="tx-secondary">${T("settings.ui_language")}</span>
              <span class="font-medium">${escHtml(T(`settings.lang_${_loadedLang || "en"}`, _loadedLang || "en"))}</span>
            </div>
          </div>
        </div>
      </div>`;
    }

    // =========================================================================
    // Event binding
    // =========================================================================

    function bindEvents() {
      // Top nav
      el.querySelectorAll("[data-nav]").forEach((btn) =>
        btn.addEventListener("click", (e) => {
          e.preventDefault();
          const v = btn.dataset.nav;
          if (v === "collections") navigate({ view: "collections" });
          else if (v === "settings") navigate({ view: "settings" });
        }),
      );

      // Collection tabs
      el.querySelectorAll("[data-tab]").forEach((btn) =>
        btn.addEventListener("click", (e) => {
          e.preventDefault();
          navigate({ tab: btn.dataset.tab });
        }),
      );

      // Collections list → open card
      el.querySelectorAll("[data-open-collection]").forEach((card) =>
        card.addEventListener("click", () => {
          navigate({
            view: "collection",
            collectionId: card.dataset.openCollection,
            tab: "overview",
          });
        }),
      );

      // Search on collections list
      const csearch = el.querySelector("#tx-collections-search");
      if (csearch) {
        csearch.addEventListener("input", () => {
          state.collectionsSearch = csearch.value;
          render();
          const again = el.querySelector("#tx-collections-search");
          if (again) {
            again.focus();
            again.selectionStart = again.selectionEnd = again.value.length;
          }
        });
      }

      // Help modal triggers
      el.querySelectorAll("[data-help]").forEach((btn) =>
        btn.addEventListener("click", () => {
          state.showCollectionHelp = btn.dataset.help === "collections";
          state.showVariablesHelp = btn.dataset.help === "variables";
          state.showTemplatesHelp = btn.dataset.help === "templates";
          state.showExportHelp = btn.dataset.help === "export";
          render();
        }),
      );
      el.querySelectorAll('[data-action="close-help"]').forEach((btn) =>
        btn.addEventListener("click", () => {
          state.showCollectionHelp = false;
          state.showVariablesHelp = false;
          state.showTemplatesHelp = false;
          state.showExportHelp = false;
          render();
        }),
      );

      // New collection
      el.querySelector('[data-action="new-collection"]')?.addEventListener(
        "click",
        () => {
          state.newCollectionOpen = true;
          state.editingCollection = null;
          render();
        },
      );
      el.querySelector('[data-action="edit-collection"]')?.addEventListener(
        "click",
        () => {
          const c = collectionById(state.collectionId);
          if (!c) return;
          state.editingCollection = JSON.parse(JSON.stringify(c));
          render();
        },
      );
      el.querySelectorAll('[data-action="close-collection-editor"]').forEach(
        (btn) =>
          btn.addEventListener("click", () => {
            state.newCollectionOpen = false;
            state.editingCollection = null;
            render();
          }),
      );
      const cForm = el.querySelector("#tx-collection-form");
      if (cForm) {
        cForm.addEventListener("submit", async (e) => {
          e.preventDefault();
          const fd = new FormData(cForm);
          const payload = {
            name: fd.get("name")?.toString().trim(),
            description: fd.get("description")?.toString().trim() || "",
            language:
              fd.get("language")?.toString() ||
              state.config.default_language ||
              "de",
          };
          if (!payload.name) return;
          try {
            if (state.editingCollection?.id) {
              await api(`/forms/${state.editingCollection.id}`, {
                method: "PUT",
                body: JSON.stringify(payload),
              });
              state.editingCollection = null;
              showToast(T("app.saved"));
              await fetchForms();
              render();
            } else {
              const res = await api("/forms", {
                method: "POST",
                body: JSON.stringify({ ...payload, fields: [] }),
              });
              state.newCollectionOpen = false;
              state.editingCollection = null;
              await fetchForms();
              navigate({
                view: "collection",
                collectionId: res.form.id,
                tab: "overview",
              });
            }
          } catch (err) {
            showToast(err.message, "error");
          }
        });
      }

      // Variables tab
      bindVariablesEvents();

      // Templates tab
      bindTemplatesEvents();

      // Datasets tab
      bindDatasetsEvents();

      // Export tab
      bindExportEvents();

      // Danger zone
      bindDangerEvents();

      // Settings
      const sForm = el.querySelector("#tx-settings-form");
      if (sForm) {
        sForm.addEventListener("submit", async (e) => {
          e.preventDefault();
          const body = Object.fromEntries(new FormData(sForm).entries());
          try {
            const res = await api("/config", {
              method: "PUT",
              body: JSON.stringify(body),
            });
            if (res.success) {
              state.config = res.config;
              const msg = el.querySelector("#tx-settings-msg");
              if (msg) {
                msg.classList.remove("hidden");
                setTimeout(() => msg.classList.add("hidden"), 2000);
              }
            }
          } catch (err) {
            showToast(err.message, "error");
          }
        });
      }
    }

    // --- Variables events ---

    function bindVariablesEvents() {
      const c = collectionById(state.collectionId);
      if (!c) return;

      // Ensure draft exists for editing
      function ensureDraft() {
        if (!state.variablesDraft) {
          state.variablesDraft = JSON.parse(JSON.stringify(c.fields || []));
          state.variablesDirty = false;
        }
      }

      function collectForm() {
        const form = el.querySelector("#tx-variables-form");
        if (!form || !state.variablesDraft) return;
        const fd = new FormData(form);
        const fields = state.variablesDraft;
        for (let idx = 0; idx < fields.length; idx++) {
          if (!fd.has(`fk_${idx}`)) continue;
          fields[idx].key =
            fd.get(`fk_${idx}`)?.toString().trim() || fields[idx].key;
          fields[idx].label =
            fd.get(`fl_${idx}`)?.toString().trim() || fields[idx].label;
          fields[idx].type =
            fd.get(`ft_${idx}`)?.toString() || fields[idx].type;
          fields[idx].required = fd.has(`fr_${idx}`);
          fields[idx].hint = fd.get(`fh_${idx}`)?.toString().trim() || "";
          if (fields[idx].type === "select") {
            const raw = fd.get(`fo_${idx}`)?.toString().trim() || "";
            fields[idx].options = raw
              ? raw
                  .split(",")
                  .map((o) => o.trim())
                  .filter(Boolean)
              : [];
          }
          if (fields[idx].type === "table") {
            const cols = fields[idx].columns || [];
            const validColTypes = [
              "text",
              "textarea",
              "list",
              "date",
              "number",
            ];
            for (let ci = 0; ci < cols.length; ci++) {
              if (fd.has(`fc_${idx}_ck_${ci}`)) {
                cols[ci].key =
                  fd.get(`fc_${idx}_ck_${ci}`)?.toString().trim() ||
                  cols[ci].key;
                cols[ci].label =
                  fd.get(`fc_${idx}_cl_${ci}`)?.toString().trim() ||
                  cols[ci].label;
                const ct = fd.get(`fc_${idx}_ct_${ci}`)?.toString();
                cols[ci].type = validColTypes.includes(ct)
                  ? ct
                  : cols[ci].type || "text";
              }
            }
          }
          // Designer config (collapsible per-field block).
          const designer = fields[idx].designer || {};
          if (fields[idx].type === "list") {
            const style = fd.get(`fd_${idx}_style`)?.toString();
            if (style === "ol" || style === "ul") designer.list_style = style;
            designer.prevent_orphans = fd.has(`fd_${idx}_prevent_orphans`);
            designer.top_blank_line = fd.has(`fd_${idx}_top_blank_line`);
            designer.bottom_blank_line = fd.has(`fd_${idx}_bottom_blank_line`);
          } else if (fields[idx].type === "table") {
            designer.repeat_header = fd.has(`fd_${idx}_repeat_header`);
            designer.prevent_row_break = fd.has(`fd_${idx}_prevent_row_break`);
            designer.keep_with_prev = fd.has(`fd_${idx}_keep_with_prev`);
          } else if (fields[idx].type === "checkbox") {
            const ch = fd.get(`fd_${idx}_checked_glyph`)?.toString().trim();
            const un = fd.get(`fd_${idx}_unchecked_glyph`)?.toString().trim();
            const yl = fd.get(`fd_${idx}_yes_label`)?.toString().trim();
            const nl = fd.get(`fd_${idx}_no_label`)?.toString().trim();
            if (ch) designer.checked_glyph = ch;
            if (un) designer.unchecked_glyph = un;
            if (yl) designer.yes_label = yl;
            if (nl) designer.no_label = nl;
            designer.clickable_checkbox = fd.has(
              `fd_${idx}_clickable_checkbox`,
            );
          } else if (fields[idx].type === "image") {
            const w = parseInt(fd.get(`fd_${idx}_width`) || "0", 10);
            const h = parseInt(fd.get(`fd_${idx}_height`) || "0", 10);
            if (w > 0) designer.width = w;
            if (h > 0) designer.height = h;
            designer.preserve_ratio = fd.has(`fd_${idx}_preserve_ratio`);
          }
          if (Object.keys(designer).length > 0) fields[idx].designer = designer;
        }
      }

      el.querySelector('[data-action="add-variable"]')?.addEventListener(
        "click",
        () => {
          ensureDraft();
          collectForm();
          state.variablesDraft.push({
            key: "",
            label: "",
            type: "text",
            required: false,
            source: "form",
          });
          state.variablesDirty = true;
          render();
        },
      );

      el.querySelectorAll('[data-action="var-remove"]').forEach((btn) =>
        btn.addEventListener("click", () => {
          ensureDraft();
          collectForm();
          if (!confirm(T("variables.confirm_delete_field"))) return;
          const idx = parseInt(btn.dataset.idx);
          state.variablesDraft.splice(idx, 1);
          state.variablesDirty = true;
          render();
        }),
      );

      el.querySelectorAll(
        '[data-action="var-move-up"], [data-action="var-move-down"]',
      ).forEach((btn) =>
        btn.addEventListener("click", () => {
          ensureDraft();
          collectForm();
          const idx = parseInt(btn.dataset.idx);
          const dir = btn.dataset.action === "var-move-up" ? -1 : 1;
          const n = idx + dir;
          if (n < 0 || n >= state.variablesDraft.length) return;
          [state.variablesDraft[idx], state.variablesDraft[n]] = [
            state.variablesDraft[n],
            state.variablesDraft[idx],
          ];
          state.variablesDirty = true;
          render();
        }),
      );

      el.querySelectorAll('[data-action="var-col-add"]').forEach((btn) =>
        btn.addEventListener("click", () => {
          ensureDraft();
          collectForm();
          const fIdx = parseInt(btn.dataset.fieldIdx);
          const f = state.variablesDraft[fIdx];
          if (!f) return;
          if (!f.columns) f.columns = [];
          f.columns.push({ key: "", label: "", type: "text" });
          state.variablesDirty = true;
          render();
        }),
      );

      el.querySelectorAll('[data-action="var-col-remove"]').forEach((btn) =>
        btn.addEventListener("click", () => {
          ensureDraft();
          collectForm();
          const fIdx = parseInt(btn.dataset.fieldIdx);
          const cIdx = parseInt(btn.dataset.colIdx);
          const f = state.variablesDraft[fIdx];
          if (!f?.columns) return;
          f.columns.splice(cIdx, 1);
          state.variablesDirty = true;
          render();
        }),
      );

      el.querySelectorAll('[data-action="var-designer-toggle"]').forEach(
        (btn) =>
          btn.addEventListener("click", () => {
            ensureDraft();
            collectForm();
            const idx = parseInt(btn.dataset.idx);
            state.expandedDesignerIdx =
              state.expandedDesignerIdx === idx ? null : idx;
            render();
          }),
      );

      const vForm = el.querySelector("#tx-variables-form");
      if (vForm) {
        vForm.addEventListener("input", () => {
          if (!state.variablesDirty) {
            state.variablesDirty = true;
            // Enable Save button without full re-render (preserves focus).
            const saveBtn = vForm.querySelector("button[type=submit]");
            if (saveBtn) saveBtn.disabled = false;
          }
        });
        vForm.addEventListener("change", (ev) => {
          // Type changes swap the markup (e.g. select→table), so full render.
          if (ev.target.name && ev.target.name.startsWith("ft_")) {
            state.variablesDirty = true;
            ensureDraft();
            collectForm();
            render();
          } else {
            state.variablesDirty = true;
          }
        });
        vForm.addEventListener("submit", async (e) => {
          e.preventDefault();
          ensureDraft();
          collectForm();
          const validFields = state.variablesDraft.filter((f) =>
            (f.key || "").trim(),
          );
          try {
            await api(`/forms/${c.id}`, {
              method: "PUT",
              body: JSON.stringify({ fields: validFields }),
            });
            showToast(T("variables.save_success"));
            state.variablesDraft = null;
            state.variablesDirty = false;
            await fetchForms();
            render();
          } catch (err) {
            showToast(err.message, "error");
          }
        });
      }

      el.querySelector('[data-action="variables-reset"]')?.addEventListener(
        "click",
        () => {
          state.variablesDraft = null;
          state.variablesDirty = false;
          render();
        },
      );

      el.querySelector('[data-action="variables-import"]')?.addEventListener(
        "click",
        () => {
          state.variablesImportOpen = true;
          state.variablesImportFields = null;
          state.variablesImportError = null;
          state.variablesImportText = "";
          render();
        },
      );
      el.querySelectorAll('[data-action="variables-import-close"]').forEach(
        (btn) =>
          btn.addEventListener("click", () => {
            state.variablesImportOpen = false;
            state.variablesImportFields = null;
            state.variablesImportError = null;
            state.variablesImportText = "";
            render();
          }),
      );
      el.querySelector(
        '[data-action="variables-import-parse"]',
      )?.addEventListener("click", async () => {
        const textarea = el.querySelector("#tx-import-text");
        const fileInput = el.querySelector("#tx-import-file");
        const file = fileInput?.files?.[0];
        const text = textarea?.value?.trim();
        if (!text && !file) {
          state.variablesImportError = "Paste text or upload a file first.";
          render();
          return;
        }
        state.variablesImportParsing = true;
        state.variablesImportError = null;
        state.variablesImportText = textarea?.value || "";
        render();
        try {
          await refreshAccessToken();
          let res;
          if (file) res = await apiUpload("/forms/import-parse", file);
          else
            res = await api("/forms/import-parse", {
              method: "POST",
              body: JSON.stringify({ text }),
            });
          state.variablesImportFields = res.fields || [];
        } catch (err) {
          state.variablesImportError = err.message;
        }
        state.variablesImportParsing = false;
        render();
      });
      el.querySelectorAll('[data-action="variables-import-remove"]').forEach(
        (btn) =>
          btn.addEventListener("click", () => {
            const idx = parseInt(btn.dataset.idx);
            state.variablesImportFields.splice(idx, 1);
            render();
          }),
      );
      el.querySelector(
        '[data-action="variables-import-apply"]',
      )?.addEventListener("click", async () => {
        ensureDraft();
        const incoming = state.variablesImportFields || [];
        const existingKeys = new Set(state.variablesDraft.map((f) => f.key));
        for (const f of incoming) {
          if (!f.key || existingKeys.has(f.key)) continue;
          state.variablesDraft.push(f);
        }
        state.variablesDirty = true;
        try {
          await api(`/forms/${c.id}`, {
            method: "PUT",
            body: JSON.stringify({ fields: state.variablesDraft }),
          });
          state.variablesDraft = null;
          state.variablesDirty = false;
          state.variablesImportOpen = false;
          state.variablesImportFields = null;
          showToast(T("variables.save_success"));
          await fetchForms();
          render();
        } catch (err) {
          showToast(err.message, "error");
        }
      });

      // --- Import from Target Template ---
      el.querySelector(
        '[data-action="variables-import-template"]',
      )?.addEventListener("click", () => {
        const tpls = collectionTemplates(c);
        state.variablesTplImportOpen = true;
        state.variablesTplImportTemplateId = tpls[0]?.id || null;
        state.variablesTplImportResult = null;
        state.variablesTplImportError = null;
        state.variablesTplImportSelection = {};
        render();
      });

      el.querySelectorAll('[data-action="variables-tplimport-close"]').forEach(
        (btn) =>
          btn.addEventListener("click", () => {
            state.variablesTplImportOpen = false;
            state.variablesTplImportTemplateId = null;
            state.variablesTplImportResult = null;
            state.variablesTplImportError = null;
            state.variablesTplImportSelection = {};
            render();
          }),
      );

      el.querySelector(
        '[data-action="variables-tplimport-load"]',
      )?.addEventListener("click", async () => {
        const picker = el.querySelector("#tx-tplimport-picker");
        const tplId = picker?.value;
        if (!tplId) return;
        state.variablesTplImportTemplateId = tplId;
        state.variablesTplImportLoading = true;
        state.variablesTplImportError = null;
        state.variablesTplImportResult = null;
        state.variablesTplImportSelection = {};
        render();
        try {
          await refreshAccessToken();
          const res = await api(
            `/templates/${encodeURIComponent(tplId)}/variable-suggestions?form_id=${encodeURIComponent(c.id)}`,
          );
          state.variablesTplImportResult = res;
          const sel = {};
          (res.suggestions || []).forEach((f, i) => {
            sel[i] = f._status !== "duplicate";
          });
          state.variablesTplImportSelection = sel;
        } catch (err) {
          state.variablesTplImportError = err.message;
        }
        state.variablesTplImportLoading = false;
        render();
      });

      el.querySelectorAll('[data-action="variables-tplimport-toggle"]').forEach(
        (cb) =>
          cb.addEventListener("change", () => {
            const idx = parseInt(cb.dataset.idx);
            state.variablesTplImportSelection[idx] = !!cb.checked;
            render();
          }),
      );

      el.querySelector(
        '[data-action="variables-tplimport-apply"]',
      )?.addEventListener("click", async () => {
        const res = state.variablesTplImportResult;
        if (!res) return;
        const sel = state.variablesTplImportSelection || {};
        const picked = (res.suggestions || []).filter((_, i) => sel[i]);
        if (picked.length === 0) return;

        ensureDraft();
        const existingKeys = new Set(state.variablesDraft.map((f) => f.key));
        for (const f of picked) {
          if (!f.key) continue;
          if (existingKeys.has(f.key)) continue;
          const clone = { ...f };
          delete clone._status;
          state.variablesDraft.push(clone);
          existingKeys.add(f.key);
        }
        state.variablesDirty = true;
        try {
          await api(`/forms/${c.id}`, {
            method: "PUT",
            body: JSON.stringify({ fields: state.variablesDraft }),
          });
          state.variablesDraft = null;
          state.variablesDirty = false;
          state.variablesTplImportOpen = false;
          state.variablesTplImportTemplateId = null;
          state.variablesTplImportResult = null;
          state.variablesTplImportSelection = {};
          showToast(T("variables.import_applied"));
          await fetchForms();
          render();
        } catch (err) {
          showToast(err.message, "error");
        }
      });
    }

    // --- Templates events ---

    function bindTemplatesEvents() {
      const c = collectionById(state.collectionId);
      if (!c) return;

      // Drag and drop
      const drop = el.querySelector("#tx-template-drop");
      const fi = el.querySelector("#tx-template-file");
      if (drop && fi) {
        drop.addEventListener("click", (e) => {
          if (e.target.closest("#tx-template-upload-form")) return;
          fi.click();
        });
        drop.addEventListener("dragover", (e) => {
          e.preventDefault();
          drop.style.borderColor = "var(--brand)";
        });
        drop.addEventListener("dragleave", () => (drop.style.borderColor = ""));
        drop.addEventListener("drop", (e) => {
          e.preventDefault();
          drop.style.borderColor = "";
          const f = e.dataTransfer.files[0];
          if (f) handleTemplateFile(f);
        });
        fi.addEventListener("change", () => {
          if (fi.files[0]) handleTemplateFile(fi.files[0]);
        });
      }

      const uploadBtn = el.querySelector("#tx-upload-template-btn");
      const nameInput = el.querySelector("#tx-template-name");
      const langSelect = el.querySelector("#tx-template-language");
      if (uploadBtn && nameInput) {
        uploadBtn.addEventListener("click", async () => {
          const file = fi?.files[0] || _pendingTemplateFile;
          const name = nameInput.value.trim();
          if (!file || !name) return;
          try {
            uploadBtn.disabled = true;
            uploadBtn.textContent = T("templates.uploading");
            const language = (langSelect?.value || "").trim();
            const extra = { name };
            if (language) extra.language = language;
            const res = await apiUpload("/templates", file, extra);
            _pendingTemplateFile = null;
            const newTemplateId = res.template?.id;
            if (newTemplateId) {
              const existing = (c.template_ids || []).slice();
              if (!existing.includes(newTemplateId)) {
                existing.push(newTemplateId);
                await api(`/forms/${c.id}`, {
                  method: "PUT",
                  body: JSON.stringify({ template_ids: existing }),
                });
              }
            }
            showToast(T("app.saved"));
            await Promise.all([fetchTemplates(), fetchForms()]);
            render();
          } catch (err) {
            showToast(err.message, "error");
            uploadBtn.disabled = false;
            uploadBtn.innerHTML = `${ICONS.upload} ${T("templates.upload")}`;
          }
        });
      }

      el.querySelectorAll("[data-download-template]").forEach((btn) =>
        btn.addEventListener("click", (e) => {
          e.stopPropagation();
          const a = document.createElement("a");
          a.href = `${BASE}/templates/${btn.dataset.downloadTemplate}/download`;
          a.download = "";
          document.body.appendChild(a);
          a.click();
          a.remove();
        }),
      );

      el.querySelectorAll("[data-template-language-set]").forEach((sel) =>
        sel.addEventListener("change", async () => {
          const tid = sel.dataset.templateLanguageSet;
          const language = sel.value || "";
          const original = sel.dataset.previousValue ?? "";
          sel.disabled = true;
          try {
            await api(`/templates/${tid}`, {
              method: "PATCH",
              body: JSON.stringify({ language }),
            });
            sel.dataset.previousValue = language;
            await fetchTemplates();
            showToast(T("templates.language_updated"));
          } catch (err) {
            sel.value = original;
            showToast(err.message, "error");
          } finally {
            sel.disabled = false;
          }
        }),
      );
      el.querySelectorAll("[data-template-language-set]").forEach((sel) => {
        sel.dataset.previousValue = sel.value || "";
      });

      el.querySelectorAll("[data-detach-template]").forEach((btn) =>
        btn.addEventListener("click", async (e) => {
          e.stopPropagation();
          if (!confirm(T("templates.confirm_delete"))) return;
          const tid = btn.dataset.detachTemplate;
          try {
            const remaining = (c.template_ids || []).filter((id) => id !== tid);
            await api(`/forms/${c.id}`, {
              method: "PUT",
              body: JSON.stringify({ template_ids: remaining }),
            });
            await api(`/templates/${tid}`, { method: "DELETE" });
            showToast(T("app.saved"));
            await Promise.all([fetchTemplates(), fetchForms()]);
            render();
          } catch (err) {
            showToast(err.message, "error");
          }
        }),
      );

      el.querySelectorAll('[data-action="template-check-match"]').forEach(
        (btn) =>
          btn.addEventListener("click", async () => {
            const tid = btn.dataset.templateId;
            try {
              const [tData, pData] = await Promise.all([
                api(`/templates/${tid}`),
                api(`/templates/${tid}/placeholders`),
              ]);
              state.selectedTemplateDetail = {
                ...tData.template,
                placeholders: pData.placeholders || [],
              };
              state.templateMatchAddKey = null;
              render();
            } catch (err) {
              showToast(err.message, "error");
            }
          }),
      );

      el.querySelector(
        '[data-action="template-match-close"]',
      )?.addEventListener("click", () => {
        state.selectedTemplateDetail = null;
        state.templateMatchAddKey = null;
        render();
      });

      el.querySelectorAll('[data-action="template-match-add"]').forEach((btn) =>
        btn.addEventListener("click", () => {
          state.templateMatchAddKey = btn.dataset.key;
          render();
        }),
      );
      el.querySelector(
        '[data-action="template-match-cancel"]',
      )?.addEventListener("click", () => {
        state.templateMatchAddKey = null;
        render();
      });
      el.querySelectorAll('[data-action="template-match-save"]').forEach(
        (btn) =>
          btn.addEventListener("click", async () => {
            const key = btn.dataset.key;
            const container = el.querySelector(`[data-add-form-key="${key}"]`);
            if (!container) return;
            const label =
              container.querySelector('[name="add_label"]')?.value?.trim() ||
              key;
            const type =
              container.querySelector('[name="add_type"]')?.value || "text";
            const hint =
              container.querySelector('[name="add_hint"]')?.value?.trim() || "";
            const newField = {
              key,
              label,
              type,
              required: false,
              source: "ai",
              hint,
            };
            const existing = c.fields || [];
            const next = [...existing, newField];
            try {
              await api(`/forms/${c.id}`, {
                method: "PUT",
                body: JSON.stringify({ fields: next }),
              });
              state.templateMatchAddKey = null;
              await fetchForms();
              showToast(T("app.saved"));
              render();
            } catch (err) {
              showToast(err.message, "error");
            }
          }),
      );
    }

    function handleTemplateFile(file) {
      _pendingTemplateFile = file;
      const form = el.querySelector("#tx-template-upload-form");
      const nameInput = el.querySelector("#tx-template-name");
      if (form) form.classList.remove("hidden");
      if (nameInput && !nameInput.value)
        nameInput.value = file.name.replace(/\.docx$/i, "");
    }

    // --- Datasets events ---

    function bindDatasetsEvents() {
      const c = collectionById(state.collectionId);
      if (!c) return;

      el.querySelector('[data-action="new-dataset"]')?.addEventListener(
        "click",
        () => {
          state.newDatasetOpen = true;
          state.selectedDataset = null;
          // The new-dataset form is rendered by the datasets tab, so if the user
          // is on a different tab (e.g. overview), switch so the form actually
          // appears. navigate() updates the hash and re-renders.
          if (state.tab !== "datasets") {
            navigate({
              view: "collection",
              collectionId: state.collectionId,
              tab: "datasets",
            });
          } else {
            render();
          }
        },
      );
      el.querySelector('[data-action="datasets-back"]')?.addEventListener(
        "click",
        () => {
          state.selectedDataset = null;
          state.newDatasetOpen = false;
          state.datasetId = null;
          writeHash();
          render();
        },
      );

      el.querySelectorAll("[data-open-dataset]").forEach((row) =>
        row.addEventListener("click", () => {
          const id = row.dataset.openDataset;
          state.datasetId = id;
          navigate({
            view: "collection",
            collectionId: c.id,
            tab: "datasets",
            datasetId: id,
          });
        }),
      );

      el.querySelectorAll("[data-delete-dataset]").forEach((btn) =>
        btn.addEventListener("click", async (e) => {
          e.stopPropagation();
          if (!confirm(T("datasets.confirm_delete"))) return;
          try {
            await api(`/candidates/${btn.dataset.deleteDataset}`, {
              method: "DELETE",
            });
            state.selectedDataset = null;
            state.datasetId = null;
            await fetchDatasets();
            showToast(T("app.saved"));
            render();
          } catch (err) {
            showToast(err.message, "error");
          }
        }),
      );

      // GDPR retention dropdown on the dataset detail header.
      el.querySelector('[data-action="set-retention"]')?.addEventListener(
        "change",
        async (e) => {
          const d = state.selectedDataset;
          if (!d) return;
          const months = parseInt(e.target.value, 10) || 0;
          try {
            const res = await api(`/candidates/${d.id}`, {
              method: "PUT",
              body: JSON.stringify({
                delete_after_months: months > 0 ? months : null,
              }),
            });
            state.selectedDataset = res.candidate;
            await fetchDatasets();
            showToast(T("datasets.retention_updated"));
            render();
          } catch (err) {
            showToast(err.message, "error");
          }
        },
      );

      // Overdue banner — Cancel just hides the banner for the current
      // session. Delete now batch-DELETEs every dataset whose computed
      // expires_at is in the past, after a confirm() guard.
      el.querySelector('[data-action="retention-dismiss"]')?.addEventListener(
        "click",
        () => {
          state.retentionBannerDismissed = true;
          render();
        },
      );
      el.querySelector(
        '[data-action="retention-delete-overdue"]',
      )?.addEventListener("click", async () => {
        const overdue = (state.datasets || []).filter(datasetIsOverdue);
        if (!overdue.length) return;
        if (
          !confirm(
            Tf("datasets.retention_delete_confirm", {
              count: overdue.length,
            }),
          )
        )
          return;
        let okCount = 0;
        for (const d of overdue) {
          try {
            await api(`/candidates/${d.id}`, { method: "DELETE" });
            okCount++;
          } catch (err) {
            showToast(err.message, "error");
          }
        }
        state.retentionBannerDismissed = false;
        await fetchDatasets();
        showToast(Tf("datasets.retention_delete_done", { count: okCount }));
        render();
      });

      // Datasets search
      const ds = el.querySelector("#tx-datasets-search");
      if (ds) {
        ds.addEventListener("input", () => {
          state.datasetsSearch = ds.value;
          state.datasetsPage = 0;
          render();
          const again = el.querySelector("#tx-datasets-search");
          if (again) {
            again.focus();
            again.selectionStart = again.selectionEnd = again.value.length;
          }
        });
      }
      el.querySelector("#tx-datasets-sort")?.addEventListener("click", () => {
        state.datasetsSortNewest = !state.datasetsSortNewest;
        state.datasetsPage = 0;
        render();
      });
      el.querySelectorAll("[data-page]").forEach((btn) =>
        btn.addEventListener("click", () => {
          state.datasetsPage = parseInt(btn.dataset.page);
          render();
        }),
      );
      el.querySelector("[data-page-prev]")?.addEventListener("click", () => {
        if (state.datasetsPage > 0) {
          state.datasetsPage--;
          render();
        }
      });
      el.querySelector("[data-page-next]")?.addEventListener("click", () => {
        state.datasetsPage++;
        render();
      });

      // New dataset
      const ndForm = el.querySelector("#tx-new-dataset-form");
      if (ndForm) {
        ndForm.addEventListener("submit", async (e) => {
          e.preventDefault();
          const name = new FormData(ndForm).get("name")?.toString().trim();
          if (!name) return;
          try {
            const res = await api("/candidates", {
              method: "POST",
              body: JSON.stringify({
                form_id: c.id,
                field_values: {},
                name,
              }),
            });
            state.newDatasetOpen = false;
            await fetchDatasets();
            await selectDataset(res.candidate.id);
            navigate({
              view: "collection",
              collectionId: c.id,
              tab: "datasets",
              datasetId: res.candidate.id,
            });
          } catch (err) {
            showToast(err.message, "error");
          }
        });
      }

      // Dataset detail actions
      bindDatasetDetailEvents(c);
    }

    function bindDatasetDetailEvents(c) {
      const d = state.selectedDataset;
      if (!d) return;

      // Save form data
      const dataForm = el.querySelector("#tx-entry-data-form");
      if (dataForm && !state.reorderingFields) {
        dataForm.addEventListener("submit", async (e) => {
          e.preventDefault();
          const fields = c.fields || [];
          const values = {};
          for (const f of fields) {
            if (f.type === "table") {
              const cols = f.columns || [];
              const rows = [];
              let ri = 0;
              while (
                dataForm.querySelector(
                  `[name="${f.key}__${ri}__${cols[0]?.key}"]`,
                )
              ) {
                const row = {};
                for (const col of cols) {
                  const cell = dataForm.querySelector(
                    `[name="${f.key}__${ri}__${col.key}"]`,
                  );
                  const raw = cell?.value ?? "";
                  if ((col.type || "text") === "list") {
                    row[col.key] = raw
                      .split(/\r?\n/)
                      .map((s) => s.trim())
                      .filter(Boolean);
                  } else {
                    row[col.key] = raw;
                  }
                }
                rows.push(row);
                ri++;
              }
              values[f.key] = rows;
              continue;
            }
            const input = dataForm.querySelector(`[name="${f.key}"]`);
            if (!input) continue;
            if (f.type === "checkbox") values[f.key] = input.checked;
            else if (f.type === "list")
              values[f.key] = input.value
                .split("\n")
                .map((s) => s.trim())
                .filter(Boolean);
            else values[f.key] = input.value;
          }
          try {
            const computed =
              values.firstname || values.lastname
                ? `${values.firstname || ""} ${values.lastname || ""}`.trim()
                : d.name;
            await api(`/candidates/${d.id}`, {
              method: "PUT",
              body: JSON.stringify({ field_values: values, name: computed }),
            });
            showToast(T("app.saved"));
            const upd = await api(`/candidates/${d.id}`);
            state.selectedDataset = upd.candidate;
            render();
          } catch (err) {
            showToast(err.message, "error");
          }
        });
      }

      el.querySelectorAll('[data-action="add-table-row"]').forEach((btn) =>
        btn.addEventListener("click", () => {
          const key = btn.dataset.fieldKey;
          if (!d.field_values) d.field_values = {};
          if (!Array.isArray(d.field_values[key])) d.field_values[key] = [];
          d.field_values[key].push({});
          render();
        }),
      );
      el.querySelectorAll('[data-action="remove-table-row"]').forEach((btn) =>
        btn.addEventListener("click", () => {
          const key = btn.dataset.fieldKey;
          const ri = parseInt(btn.dataset.rowIdx);
          if (!d.field_values?.[key]) return;
          d.field_values[key].splice(ri, 1);
          render();
        }),
      );

      el.querySelector('[data-action="toggle-reorder"]')?.addEventListener(
        "click",
        async () => {
          if (state.reorderingFields) {
            try {
              await api(`/forms/${c.id}`, {
                method: "PUT",
                body: JSON.stringify({ fields: c.fields }),
              });
              showToast(T("variables.reorder_saved"));
              await fetchForms();
            } catch (err) {
              showToast(err.message, "error");
            }
          }
          state.reorderingFields = !state.reorderingFields;
          render();
        },
      );

      el.querySelectorAll(
        '[data-action="df-up"], [data-action="df-down"]',
      ).forEach((btn) =>
        btn.addEventListener("click", () => {
          if (!c.fields) return;
          const idx = parseInt(btn.dataset.idx);
          const dir = btn.dataset.action === "df-up" ? -1 : 1;
          const n = idx + dir;
          if (n < 0 || n >= c.fields.length) return;
          [c.fields[idx], c.fields[n]] = [c.fields[n], c.fields[idx]];
          render();
        }),
      );

      // File upload
      const uploader = el.querySelector("#tx-doc-upload");
      if (uploader) {
        uploader.addEventListener("change", async () => {
          const files = Array.from(uploader.files || []);
          if (!files.length) return;
          const hasCv = !!d.files?.cv;
          try {
            for (let i = 0; i < files.length; i++) {
              const f = files[i];
              if (i === 0 && !hasCv)
                await apiUpload(`/candidates/${d.id}/upload-cv`, f);
              else await apiUpload(`/candidates/${d.id}/upload-doc`, f);
            }
            showToast(T("datasets.doc_uploaded"));
            const upd = await api(`/candidates/${d.id}`);
            state.selectedDataset = upd.candidate;
            render();
          } catch (err) {
            showToast(err.message, "error");
          }
        });
      }

      el.querySelectorAll('[data-action="delete-source-file"]').forEach((btn) =>
        btn.addEventListener("click", async () => {
          if (!confirm(T("datasets.confirm_delete_source_file"))) return;
          try {
            const upd = await api(
              `/candidates/${d.id}/files/${btn.dataset.slot}/${btn.dataset.slotIndex}`,
              { method: "DELETE" },
            );
            state.selectedDataset = upd.candidate;
            showToast(T("datasets.source_file_deleted"));
            render();
          } catch (err) {
            showToast(err.message, "error");
          }
        }),
      );

      // URL source actions
      el.querySelector('[data-action="toggle-url-form"]')?.addEventListener(
        "click",
        () => {
          state.urlFormOpen = !state.urlFormOpen;
          state.urlAddError = null;
          render();
          if (state.urlFormOpen) {
            setTimeout(() => el.querySelector("#tx-url-input")?.focus(), 50);
          }
        },
      );
      el.querySelector('[data-action="cancel-url"]')?.addEventListener(
        "click",
        () => {
          state.urlFormOpen = false;
          state.urlAddError = null;
          render();
        },
      );
      el.querySelector('[data-action="save-url"]')?.addEventListener(
        "click",
        async () => {
          const urlInput = el.querySelector("#tx-url-input");
          const labelInput = el.querySelector("#tx-url-label");
          const url = urlInput?.value?.trim();
          const label = labelInput?.value?.trim() || "";
          if (!url) {
            state.urlAddError = T("datasets.url_add_required");
            render();
            return;
          }
          state.urlAdding = true;
          state.urlAddError = null;
          render();
          try {
            const res = await api(`/candidates/${d.id}/urls`, {
              method: "POST",
              body: JSON.stringify({ url, label }),
            });
            state.selectedDataset = res.candidate;
            state.urlFormOpen = false;
            state.urlAddError = null;
            showToast(T("datasets.url_added"));
            const hasSnippet = res.url?.text_snippet;
            if (!hasSnippet && res.url?.fetch_error) {
              showToast(
                Tf("datasets.url_fetch_warning", {
                  error: res.url.fetch_error,
                }),
                "error",
              );
            }
          } catch (err) {
            state.urlAddError = err.message;
          }
          state.urlAdding = false;
          render();
        },
      );
      el.querySelectorAll('[data-action="refresh-url"]').forEach((btn) =>
        btn.addEventListener("click", async () => {
          const urlIndex = parseInt(btn.dataset.urlIndex);
          try {
            await api(`/candidates/${d.id}/urls/${urlIndex}/refresh`, {
              method: "POST",
            });
            const upd = await api(`/candidates/${d.id}`);
            state.selectedDataset = upd.candidate;
            showToast(T("datasets.url_refreshed"));
            render();
          } catch (err) {
            showToast(err.message, "error");
          }
        }),
      );

      // Extract
      el.querySelector('[data-action="extract"]')?.addEventListener(
        "click",
        async () => {
          state.datasetExtracting = true;
          render();
          await refreshAccessToken();
          try {
            await api(`/candidates/${d.id}/extract`, { method: "POST" });
            const upd = await api(`/candidates/${d.id}`);
            state.selectedDataset = upd.candidate;
            await loadDatasetVariables(d.id);
          } catch (err) {
            showToast(err.message, "error");
          }
          state.datasetExtracting = false;
          render();
        },
      );

      // Parse documents — single combined action that runs the AI form
      // auto-fill (parse-documents) and the variable-level extraction
      // (extract) in parallel. We surface a live elapsed-time + adaptive
      // progress bar so users know that a slow image OCR or AI roundtrip
      // is still in progress rather than hung.
      el.querySelector('[data-action="parse-documents"]')?.addEventListener(
        "click",
        async () => {
          const imageExts = [
            "jpg",
            "jpeg",
            "png",
            "gif",
            "webp",
            "tif",
            "tiff",
            "bmp",
          ];
          const allFiles = [];
          if (d.files?.cv?.filename) allFiles.push(d.files.cv.filename);
          for (const f of d.files?.additional || []) {
            if (f?.filename) allFiles.push(f.filename);
          }
          const urlCount = (d.files?.urls || []).length;
          const fileCount = allFiles.length + urlCount;
          const hasImages = allFiles.some((name) => {
            const dot = name.lastIndexOf(".");
            if (dot < 0) return false;
            return imageExts.includes(name.slice(dot + 1).toLowerCase());
          });
          state.datasetParsing = true;
          state.datasetParseStep = 0;
          state.datasetParseStartedAt = Date.now();
          state.datasetParseElapsedSec = 0;
          state.datasetParseFileCount = fileCount;
          state.datasetParseHasImages = hasImages;
          if (state.datasetParseTimer) clearInterval(state.datasetParseTimer);
          state.datasetParseTimer = setInterval(() => {
            if (!state.datasetParsing || !state.datasetParseStartedAt) return;
            state.datasetParseElapsedSec = Math.round(
              (Date.now() - state.datasetParseStartedAt) / 1000,
            );
            render();
          }, 1000);
          render();
          await new Promise((r) => setTimeout(r, 250));
          state.datasetParseStep = 1;
          render();
          await refreshAccessToken();
          let parseRes = null;
          try {
            state.datasetParseStep = 2;
            render();
            const parsePromise = api(`/candidates/${d.id}/parse-documents`, {
              method: "POST",
            }).catch((err) => ({ __error: err }));
            const extractPromise = api(`/candidates/${d.id}/extract`, {
              method: "POST",
            }).catch((err) => ({ __error: err }));
            const [pRes, eRes] = await Promise.all([
              parsePromise,
              extractPromise,
            ]);
            if (pRes && pRes.__error) {
              throw pRes.__error;
            }
            parseRes = pRes;
            if (eRes && eRes.__error) {
              console.warn("[synaform] extract endpoint failed", eRes.__error);
            }
            state.datasetParseStep = 3;
            render();
            if (parseRes && parseRes.success && parseRes.suggestions) {
              const merged = { ...(d.field_values || {}) };
              for (const [k, v] of Object.entries(parseRes.suggestions)) {
                if (v !== null && v !== undefined && v !== "") merged[k] = v;
              }
              await api(`/candidates/${d.id}`, {
                method: "PUT",
                body: JSON.stringify({ field_values: merged }),
              });
            }
            const upd = await api(`/candidates/${d.id}`);
            state.selectedDataset = upd.candidate;
            await loadDatasetVariables(d.id);
            showToast(T("datasets.parse_done"));
          } catch (err) {
            showToast(err.message, "error");
          }
          if (state.datasetParseTimer) {
            clearInterval(state.datasetParseTimer);
            state.datasetParseTimer = null;
          }
          state.datasetParsing = false;
          state.datasetParseStep = 0;
          state.datasetParseStartedAt = null;
          state.datasetParseElapsedSec = 0;
          state.datasetParseFileCount = 0;
          state.datasetParseHasImages = false;
          render();
        },
      );

      // Override variable
      el.querySelectorAll('[data-action="override-var"]').forEach((btn) =>
        btn.addEventListener("click", () => {
          state.editingVarKey = btn.dataset.varKey;
          render();
        }),
      );
      el.querySelector('[data-action="save-override"]')?.addEventListener(
        "click",
        async () => {
          const input = el.querySelector("#tx-override-input");
          const btn = el.querySelector('[data-action="save-override"]');
          if (!input || !btn) return;
          try {
            const existing = d.variable_overrides || {};
            const overrides = {
              ...existing,
              [btn.dataset.varKey]: input.value,
            };
            await api(`/candidates/${d.id}/variables`, {
              method: "PUT",
              body: JSON.stringify({ overrides }),
            });
            d.variable_overrides = overrides;
            await loadDatasetVariables(d.id);
            state.editingVarKey = null;
            showToast(T("app.saved"));
            render();
          } catch (err) {
            showToast(err.message, "error");
          }
        },
      );
      el.querySelector('[data-action="cancel-override"]')?.addEventListener(
        "click",
        () => {
          state.editingVarKey = null;
          render();
        },
      );

      // Generate
      const genSel = el.querySelector("#tx-generate-template");
      if (genSel) {
        genSel.addEventListener("change", () => {
          state.selectedGenerateTemplate = genSel.value || null;
          render();
        });
      }
      el.querySelector('[data-action="generate"]')?.addEventListener(
        "click",
        async () => {
          if (!state.selectedGenerateTemplate) return;
          state.datasetGenerating = true;
          render();
          await refreshAccessToken();
          try {
            await api(
              `/candidates/${d.id}/generate/${state.selectedGenerateTemplate}`,
              { method: "POST" },
            );
            const upd = await api(`/candidates/${d.id}`);
            state.selectedDataset = upd.candidate;
            showToast(T("datasets.generate_done"));
          } catch (err) {
            showToast(err.message, "error");
          }
          state.datasetGenerating = false;
          render();
        },
      );

      el.querySelectorAll('[data-action="download-doc"]').forEach((btn) =>
        btn.addEventListener("click", () => {
          window.open(
            `${BASE}/candidates/${d.id}/documents/${btn.dataset.docId}/download`,
            "_blank",
          );
        }),
      );
      el.querySelectorAll('[data-action="delete-doc"]').forEach((btn) =>
        btn.addEventListener("click", async () => {
          if (!confirm(T("datasets.confirm_delete_doc"))) return;
          try {
            await api(`/candidates/${d.id}/documents/${btn.dataset.docId}`, {
              method: "DELETE",
            });
            showToast(T("app.saved"));
            await selectDataset(d.id);
          } catch (err) {
            showToast(err.message, "error");
          }
        }),
      );

      // --- Image variable upload / remove ---
      el.querySelectorAll('[data-action="image-upload"]').forEach((inp) =>
        inp.addEventListener("change", async (ev) => {
          const file = ev.target?.files?.[0];
          if (!file) return;
          const key = inp.dataset.key;
          try {
            await refreshAccessToken();
            await apiUpload(
              `/candidates/${d.id}/image/${encodeURIComponent(key)}`,
              file,
            );
            showToast(T("datasets.image_uploaded"));
            await selectDataset(d.id);
          } catch (err) {
            showToast(err.message || String(err), "error");
          }
        }),
      );
      el.querySelectorAll('[data-action="image-remove"]').forEach((btn) =>
        btn.addEventListener("click", async () => {
          const key = btn.dataset.key;
          if (!confirm(T("datasets.image_confirm_remove"))) return;
          try {
            await api(`/candidates/${d.id}/image/${encodeURIComponent(key)}`, {
              method: "DELETE",
            });
            await selectDataset(d.id);
          } catch (err) {
            showToast(err.message || String(err), "error");
          }
        }),
      );

      // --- Live preview (Phase 4b) ---
      bindPreviewEvents(c, d);
    }

    function bindPreviewEvents(c, d) {
      // "Show preview" toggle lives in the dataset header, outside the panel
      el.querySelector('[data-action="preview-show"]')?.addEventListener(
        "click",
        () => {
          state.previewVisible = true;
          render();
        },
      );

      const panel = el.querySelector("#tx-preview-panel");
      if (!panel) return;

      const templates = collectionTemplates(c);
      if (templates.length === 0) return;

      // Pick an initial template if we don't have one cached yet
      const validIds = templates.map((t) => t.id);
      let targetId = state.previewTemplateId;
      if (!targetId || !validIds.includes(targetId)) {
        targetId = validIds[0];
      }

      // Kick off a fetch only when we have no skeleton for the target AND no
      // other fetch is in flight. The in-flight guard inside loadPreviewSkeleton
      // also prevents loops, but checking here avoids the redundant render().
      const needsLoad =
        (!state.previewSkeleton || state.previewTemplateId !== targetId) &&
        !state.previewLoading &&
        !state.previewError;
      if (needsLoad) {
        loadPreviewSkeleton(targetId);
        // fall through: still bind the toolbar buttons so the user can act
        // while the fetch is pending (refresh, hide, pick a different template).
      }

      // Prime the DOM once on bind so the first render isn't blank (only if
      // the skeleton is actually present).
      if (state.previewSkeleton) {
        setTimeout(updatePreviewNow, 0);
      }

      // Template picker — always bind, even mid-fetch, so the user can switch.
      const picker = panel.querySelector("#tx-preview-template-picker");
      picker?.addEventListener("change", () => {
        const id = picker.value;
        if (id && id !== state.previewTemplateId) {
          loadPreviewSkeleton(id, { force: true });
        }
      });

      // Refresh: re-fetch the skeleton (handles updated DOCX upload or prior error).
      panel
        .querySelector('[data-action="preview-refresh"]')
        ?.addEventListener("click", () => {
          loadPreviewSkeleton(state.previewTemplateId || targetId, {
            force: true,
          });
        });

      // Hide
      panel
        .querySelector('[data-action="preview-hide"]')
        ?.addEventListener("click", () => {
          state.previewVisible = false;
          render();
        });

      // Live input listener on the dataset form (delegated). Only bind while
      // the skeleton is present so we don't schedule pointless DOM updates.
      if (state.previewSkeleton) {
        const form = el.querySelector("#tx-entry-data-form");
        if (form) {
          const handler = () => schedulePreviewUpdate();
          form.addEventListener("input", handler);
          form.addEventListener("change", handler);
        }
      }

      // --- True-preview (PDF) path ---
      panel
        .querySelector('[data-action="preview-render-pdf"]')
        ?.addEventListener("click", () => renderTruePreviewPdf(c, d));

      panel
        .querySelector('[data-action="preview-back-html"]')
        ?.addEventListener("click", () => {
          if (state.previewPdfUrl) {
            URL.revokeObjectURL(state.previewPdfUrl);
          }
          state.previewPdfUrl = null;
          state.previewPdfError = null;
          render();
        });
    }

    async function renderTruePreviewPdf(c, d) {
      if (!d || !state.previewTemplateId) return;
      const templateId = state.previewTemplateId;

      state.previewPdfLoading = true;
      state.previewPdfError = null;
      state.previewPdfUnavailable = false;
      render();

      try {
        // 1. Save the current form state so generation uses fresh values.
        const form = el.querySelector("#tx-entry-data-form");
        if (form) {
          // Synthesise a submit (the existing handler writes field_values).
          const submit = new Event("submit", {
            cancelable: true,
            bubbles: true,
          });
          form.dispatchEvent(submit);
          // Give the save + render a tick to complete
          await new Promise((r) => setTimeout(r, 150));
        }

        // 2. Generate the DOCX (persists as a "generated document" on the candidate).
        await refreshAccessToken();
        const gen = await api(`/candidates/${d.id}/generate/${templateId}`, {
          method: "POST",
        });
        const docId = gen?.document?.id;
        if (!docId) throw new Error("Generation did not return a document id");

        // 3. Fetch the PDF. Response is a binary stream; turn into a blob URL.
        const pdfResp = await fetch(
          `${BASE}/candidates/${d.id}/documents/${docId}/pdf`,
          { credentials: "include" },
        );
        if (pdfResp.status === 501) {
          const errJson = await pdfResp.json().catch(() => ({}));
          state.previewPdfUnavailable = true;
          state.previewPdfError = errJson.error || T("preview.pdf_unavailable");
          throw new Error(state.previewPdfError);
        }
        if (!pdfResp.ok) {
          throw new Error(`PDF conversion failed (${pdfResp.status})`);
        }
        const blob = await pdfResp.blob();
        const url = URL.createObjectURL(blob);
        if (state.previewPdfUrl) URL.revokeObjectURL(state.previewPdfUrl);
        state.previewPdfUrl = url;
      } catch (err) {
        if (!state.previewPdfUnavailable) {
          showToast(err.message || String(err), "error");
        }
      }

      state.previewPdfLoading = false;
      render();
    }

    // --- Export events ---

    function bindExportEvents() {
      const c = collectionById(state.collectionId);
      if (!c) return;

      el.querySelector("#tx-export-status")?.addEventListener("change", (e) => {
        state.exportStatus = e.target.value;
        render();
      });
      el.querySelector("#tx-export-search")?.addEventListener("input", (e) => {
        state.exportSearch = e.target.value;
        render();
        const again = el.querySelector("#tx-export-search");
        if (again) {
          again.focus();
          again.selectionStart = again.selectionEnd = again.value.length;
        }
      });
      el.querySelector("#tx-export-from")?.addEventListener("change", (e) => {
        state.exportFrom = e.target.value;
        render();
      });
      el.querySelector("#tx-export-to")?.addEventListener("change", (e) => {
        state.exportTo = e.target.value;
        render();
      });

      el.querySelector('[data-action="export-csv"]')?.addEventListener(
        "click",
        () => exportToCsv(c),
      );
    }

    async function exportToCsv(c) {
      const all = datasetsForCollection(c.id);
      const filtered = filterDatasetsForExport(all);
      if (filtered.length === 0) return;

      const fields = c.fields || [];
      const variableKeys = fields.map((f) => f.key);
      const columns = [
        "id",
        "name",
        "status",
        "created_at",
        "updated_at",
        "source_files",
        "generated_documents",
        ...variableKeys,
      ];

      // Load full datasets to resolve their variable values.
      const rows = [];
      for (const d of filtered) {
        let full = d;
        try {
          const res = await api(`/candidates/${d.id}`);
          full = res.candidate || d;
        } catch (_) {
          // Fall back to the cached summary.
        }
        let resolved = {};
        try {
          const res = await api(`/candidates/${d.id}/variables`);
          resolved = res.variables || {};
        } catch (_) {
          resolved = full.field_values || {};
        }
        const fileCount =
          (full.files?.cv ? 1 : 0) + (full.files?.additional?.length || 0);
        const docCount = full.documents
          ? Object.keys(full.documents).length
          : 0;
        const row = {
          id: full.id,
          name: datasetDisplayName(full),
          status: full.status || "draft",
          created_at: full.created_at || "",
          updated_at: full.updated_at || "",
          source_files: fileCount,
          generated_documents: docCount,
        };
        for (const k of variableKeys) {
          const v = resolved[k] ?? full.field_values?.[k] ?? "";
          row[k] = Array.isArray(v)
            ? v
                .map((item) =>
                  typeof item === "object"
                    ? JSON.stringify(item)
                    : String(item),
                )
                .join(" | ")
            : typeof v === "object" && v !== null
              ? JSON.stringify(v)
              : String(v);
        }
        rows.push(row);
      }

      const csv = buildCsv(columns, rows);
      const blob = new Blob(["\ufeff" + csv], {
        type: "text/csv;charset=utf-8",
      });
      const url = URL.createObjectURL(blob);
      const safeName = c.name.replace(/[^a-z0-9-_]+/gi, "_").toLowerCase();
      const today = new Date().toISOString().slice(0, 10);
      const a = document.createElement("a");
      a.href = url;
      a.download = `synaform-${safeName}-${today}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      showToast(T("export.download_csv"));
    }

    function buildCsv(columns, rows) {
      const esc = (v) => {
        const s = String(v ?? "");
        if (/[",\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
        return s;
      };
      const head = columns.map(esc).join(",");
      const body = rows
        .map((r) => columns.map((c) => esc(r[c])).join(","))
        .join("\r\n");
      return `${head}\r\n${body}`;
    }

    // --- Danger zone events ---

    function bindDangerEvents() {
      const c = collectionById(state.collectionId);
      if (!c) return;
      const input = el.querySelector("#tx-danger-input");
      const deleteBtn = el.querySelector('[data-action="delete-collection"]');
      if (input) {
        input.addEventListener("input", () => {
          state.dangerConfirmText = input.value;
          const matches = state.dangerConfirmText === c.name;
          // Update button + visuals in-place to preserve focus.
          if (deleteBtn) deleteBtn.disabled = !matches;
          input.style.borderColor =
            state.dangerConfirmText && !matches ? "var(--status-error)" : "";
          const msg = el.querySelector("#tx-danger-mismatch");
          if (msg)
            msg.style.display =
              state.dangerConfirmText && !matches ? "" : "none";
        });
      }
      el.querySelector('[data-action="delete-collection"]')?.addEventListener(
        "click",
        async () => {
          if (state.dangerConfirmText !== c.name) return;
          try {
            // Cascade: delete all datasets first (client-side).
            const kids = datasetsForCollection(c.id);
            for (const d of kids) {
              await api(`/candidates/${d.id}`, { method: "DELETE" });
            }
            await api(`/forms/${c.id}`, { method: "DELETE" });
            showToast(T("danger.deleted_toast"));
            state.collectionId = null;
            state.dangerConfirmText = "";
            navigate({ view: "collections" });
          } catch (err) {
            showToast(err.message, "error");
          }
        },
      );
    }

    // =========================================================================
    // Data loaders
    // =========================================================================

    async function fetchForms() {
      try {
        const d = await api("/forms");
        state.forms = d.forms || [];
      } catch (_) {
        state.forms = [];
      }
    }
    async function fetchTemplates() {
      try {
        const d = await api("/templates");
        state.templates = d.templates || [];
      } catch (_) {
        state.templates = [];
      }
    }
    async function fetchDatasets() {
      try {
        const d = await api("/candidates");
        state.datasets = d.candidates || [];
      } catch (_) {
        state.datasets = [];
      }
    }

    async function loadData() {
      const jobs = [];
      if (state.view === "settings") {
        // config already loaded on init
      } else {
        jobs.push(fetchForms(), fetchTemplates(), fetchDatasets());
      }
      await Promise.all(jobs);
      render();
    }

    async function selectDataset(id) {
      try {
        const d = await api(`/candidates/${id}`);
        state.selectedDataset = d.candidate;
        state.datasetId = id;
        state.datasetVariables = null;
        state.editingVarKey = null;
        state.selectedGenerateTemplate = null;
        await loadDatasetVariables(id);
      } catch (err) {
        showToast(err.message, "error");
      }
      render();
    }

    async function loadDatasetVariables(id) {
      state.datasetVariablesLoading = true;
      try {
        const d = await api(`/candidates/${id}/variables`);
        const varsMap = d.variables || {};
        const sourcesDef = d.sources || {};
        const tableFieldsMeta = d.table_fields || {};
        const list = [];
        const tableKeys = new Set(Object.keys(tableFieldsMeta));
        if (!tableKeys.has("stations")) {
          for (const key of Object.keys(varsMap)) {
            if (/^stations\.\w+\.\d+$/.test(key)) {
              tableKeys.add("stations");
              break;
            }
          }
        }
        const overrides = state.selectedDataset?.variable_overrides || {};
        for (const [k, v] of Object.entries(varsMap)) {
          if (tableKeys.has(k) && Array.isArray(v)) {
            let source = "form";
            if (overrides[k] !== undefined && overrides[k] !== null)
              source = "override";
            else if (sourcesDef[k]?.primary === "ai") source = "ai";
            list.push({
              key: k,
              value: v,
              source,
              type: "table",
              columns: tableFieldsMeta[k]?.columns || [],
              label: tableFieldsMeta[k]?.label || k,
            });
            continue;
          }
          if (/^\w+\.\w+\.\d+$/.test(k)) {
            const prefix = k.split(".")[0];
            if (tableKeys.has(prefix)) continue;
          }
          let source = "form";
          if (overrides[k] !== undefined && overrides[k] !== null)
            source = "override";
          else if (sourcesDef[k]?.primary === "ai") source = "ai";
          list.push({
            key: k,
            value: v,
            source,
            type: k.startsWith("checkb.") ? "checkbox" : "text",
          });
        }
        state.datasetVariables = list;
      } catch (_) {
        state.datasetVariables = null;
      }
      state.datasetVariablesLoading = false;
    }

    // =========================================================================
    // Init
    // =========================================================================

    async function init() {
      await loadTranslations();
      startLanguageWatcher();
      applyHash();
      render();
      try {
        const data = await api("/setup-check");
        if (!data.success) {
          state.error = data.error || T("app.not_available");
          state.loading = false;
          render();
          return;
        }
        state.config = data.config || {};
        await Promise.all([fetchForms(), fetchTemplates(), fetchDatasets()]);
        state.loading = false;
        render();
        if (
          state.view === "collection" &&
          state.tab === "datasets" &&
          state.datasetId
        ) {
          await selectDataset(state.datasetId);
        }
      } catch (err) {
        state.error = err.message;
        state.loading = false;
        render();
      }
    }

    init();
  },
};
