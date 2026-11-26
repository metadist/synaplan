# Reviewer notes: injection, parsing, files, plugins, sidecars

Commit a2e57b08d. Test users audit-inj-a (4928), audit-inj-b (4929), audit-inj-plug-a (4924).
Saved by the lead from the reviewer's hand-back. No secret values.

## F1 Critical, Confirmed (read; delete traced): Synaform arbitrary file read/delete as root

`plugins/synaform/backend/Controller/SynaformController.php`: writers candidatesCreate :1830 (verbatim
field_values), candidatesUpdate :1904-1938, candidates_variables_update :4387; sinks candidatesImageGet
:5042-5050 (`new BinaryFileResponse($meta['path'])`, Content-Type from `$meta['mime']`),
candidatesImageDelete :5073-5075 (`@unlink`), processImages :9208-9228, :9286 (`$zip->addFile`),
autoExtractProfileImages :5129. Upload stores absolute path :4999-5010. Done right: candidatesFileDownload
:2216 basename(). Gate canAccessPlugin :5432-5443 (own id + `P_synaform.enabled=1`). Local stack env
DEFAULT_USER_PLUGINS includes synaform (`ProvisionDefaultUserPluginsMessageHandler.php:27-50`); repo
default `[]` (`backend/config/services.yaml:272`).
Repro (user 4928):
1. `POST /api/v1/user/4928/plugins/synaform/candidates` `{"name":"audit-inj","field_values":{"pic":{"path":"/etc/hostname","mime":"text/plain"},"h":{"path":"/etc/hostname","mime":"text/html"}}}` -> 201 `entry_a149573a5d3f`.
2. `GET .../candidates/entry_a149573a5d3f/image/pic` -> 200 text/plain, `Cache-Control: max-age=60, public`, body = backend container hostname.
3. `GET .../image/h` -> 200 text/html, no CSP.
4. Sub-reviewer: `/etc/passwd` 888 bytes; `/var/www/backend/.env` -> 200, 16097 bytes = file size (0600 root); body discarded.
5. `id -un` in backend container: root.
CVSS AV:N/AC:L/PR:L/UI:N/S:C/C:H/I:H/A:H = 9.9. CWE-22, CWE-73.
Fix: store file name only; rebuild `<userDir>/synaform/candidates/<id>/images/`+basename, realpath
containment; strip client `path`/`mime` in create/update/variables; Content-Type from mimeToExtension
allow-list, `private`; non-root PHP worker. Test: PUT field_values.x.path=/etc/passwd then GET image/x -> 404.
Detection: nothing logged today; log/alert when resolved image path is outside the user's upload dir.
Related: candidatesFileDelete :2152-2164 uses stored_as without basename (not client-writable today).

## F2 High, Confirmed: Synaform URL sources full-read SSRF

`SynaformController.php` candidatesUrlsAdd :2262-2274 (scheme/host only); fetchUrlText :6480-6494 (raw
HttpClientInterface :143, max_redirects 5); candidates_urls_refresh :2345. No WebFetchPolicy, no
NoPrivateNetworkHttpClient (`backend/config/services.yaml:913-915`). Up to 60 000 chars returned as text_snippet.
Repro: `POST /api/v1/user/4924/plugins/synaform/candidates/{id}/urls {"url":"http://qdrant:6333/collections"}`
-> 200 text_snippet `{"result":{"collections":[{"name":"user_documents"},{"name":"user_memories"},{"name":"user_message_digests"}]},...}`.
CVSS AV:N/AC:L/PR:L/UI:N/S:C/C:H/I:N/A:N = 7.7. CWE-918.

## F3 High: SVG named .heic reaches ImageMagick SVG/MVG/MSL coders (local image inclusion confirmed)

`backend/src/Service/File/HeicConverter.php:187-202` (fallback un-prefixed readImage);
`FileStorageService.php:150-152`, `:251-257`; stock `/etc/ImageMagick-6/policy.xml`.
Repro as demo: upload `audit-inj-svg.heic` (SVG with `<image xlink:href="/var/www/frontend/favicon-32.png">`)
-> stored as jpg; `GET /api/v1/files/362/download` shows favicon composited. Non-existent href ->
"Could not process this HEIC photo" (existence oracle). CLI only: `text:` SIGABRT, `msl:` segfault,
mvg/label processed, http/ephemeral refused.
CVSS AV:N/AC:L/PR:L/UI:N/S:U/C:L/I:N/A:H = 7.1. CWE-434, CWE-20.
Fix: check ISO-BMFF ftyp brand; drop fallback; ship policy.xml allowing only JPEG/PNG/GIF/WEBP/HEIC.
Related (not exercised): `WidgetPublicController.php:596` (public upload), `VisionAttachmentEncoder.php:256`,
`AI/Image/PngJpegInlineImages.php:94-95`, `DocumentGeneratorService.php:211`.

## F4 Medium, Confirmed: castingdata api_url SSRF filter bypass

`plugins/castingdata/backend/Controller/CastingDataController.php:244-272` validateApiUrl, :279-286
canAccessPlugin (user id only); `Service/CastingApiClient.php:99-108, 139-160`.
`http://[::ffff:172.18.0.11]:6333/collections#` accepted (plain private IP and hostname rejected);
test-connection -> "Connection successful". Save-time-only check; fragment drops fixed suffix.
CVSS 5.0. CWE-918.

## F5 Medium (admin), Confirmed (no-op executed): model-import SQL validator bypass

`backend/src/Service/Admin/ModelSqlValidator.php:87,94,104,126-133,135`;
`ModelImportService.php:96,115`; `AdminModelsController.php:357-411`.
`POST /api/v1/admin/models/import/apply {"sql":"UPDATE plugin_data SET data_key = data_key WHERE 1 = 0 AND 'UPDATE BMODELS BSERVICE BTAG BPROVID' <> ''"}` -> 200 applied 1 (0 rows).
BUSER variant -> 400 (token allow-list); unprefixed tables open: plugin_data, messenger_messages,
doctrine_migration_versions; STRAIGHT_JOIN / comma-join read primitive (validator only).
CVSS AV:N/AC:L/PR:H/UI:N/S:U/C:H/I:H/A:L = 6.7. CWE-89, CWE-184.
Fix: structured rows via ModelRepository; else strip literals, anchor to BMODELS, reject second table.

## F6 Medium, Confirmed at sidecar: file create outside sandbox via symlink in /out

`sidecars/synaplan-compute/internal/api/runs.go:503-520` promoteWorkOutputs (os.Stat follows links);
`internal/perm/perm.go:75-99`; `docker-compose.yml:1450` user 0:0, :1473 docker.sock.
Run writes /work/poc.txt and symlinks /out/poc.txt -> /tmp/audit-inj-sidecar-poc.txt: file created in
sidecar fs (48 bytes, 65534:0). Cannot overwrite existing files. Chat path traced
(`CodeRunRunner.php:402` -> ComputeClient::submitRun). CVSS 5.0. CWE-59.
Fix: Lstat + skip; openat O_CREAT|O_EXCL|O_NOFOLLOW; non-root with docker GID.

## F7 Medium, Suspected: AI [IMAGE:...] marker sets Message.filePath unvalidated

Source `MessageController.php:236-247, 259`, `Service/Message/AgainHandler.php:256-262, 275, 319`.
Sink A `StaticUploadController.php:228-244` (first Message with matching filePath decides owner), :172.
Sink B `FileAnalysis/AnalysisFileResolver.php:73-79, 119-122`, `ImageAnalysis.php:102`.
Needs a real model to echo the marker (TestProvider intercepts). CVSS 5.3. CWE-639, CWE-22.

## F8 Medium, Suspected: generated media served anonymously under guessable names

`StaticUploadController.php:98-116`; `Service/Media/OutboundChannelMedia.php:24-35`;
`MediaUploadStore.php:64,134`, `DataUrlFixer.php:107`; tts_+uniqid in PiperProvider.php:187,
OpenAISpeech.php:153, GoogleSpeech.php:363, MistralProvider.php:425, XaiProvider.php:766. Done right:
`ai_i2vsrc_` 6 random bytes (`MediaUploadStore.php:214`). Not run. CVSS 5.9. CWE-330, CWE-639.
Related: `OutboundChannelMedia::publishCopy` :62-98.

## F9 Low, Confirmed by trace: CSV/XLSX formula injection via widget session id

`WidgetTurnGate.php:46,63`; `WidgetSessionResolver.php:37`; `WidgetSessionService.php:71`;
`WidgetExportService.php:159`, `:515`. CWE-1236. Fix: validate `^[A-Za-z0-9_-]{8,64}$`, sanitizeCellValue.

## F10 Low, Suspected: ffmpeg accepts playlist content under a media extension

`FileProcessor.php:1433,1734`, `WhisperService.php:377`, `MetaProvider.php:569`,
`TelegramVoiceConverter.php:37`, `ThumbnailService.php:210`. CWE-73. Fix: -format_whitelist / -protocol_whitelist.

## Ruled out

Plugin asset traversal (`PluginAssetResponder.php:37-41`, `PluginAssetController.php:66-70`; live 404);
static uploads (`UploadRequestPath.php:30-43`, `FileHelper.php:311-328`); /up (`FileServeController.php:56-67,128`);
Caddy /uploads (`_docker/backend/Caddyfile:206-216`, empty dir); compute workspace download
(`ComputeClient.php:146`, `safepath/safepath.go:55-110`, `ComputeWorkspaceController.php:258-277`);
GHSA-qqg9-qv48-j8m8 fix (`UploadedFileSandboxSubscriber.php:26-53`, live); upload allow-list
(`FileStorageService.php:19-31, 322-339`); downloads as attachment; widget icon type sniffing
(`WidgetController.php:592-609`); bundle import is JSON (`BundleRequestParser.php:26-29`,
`BundleController.php:384`); no extractTo; no XXE flags (`WopiDiscoveryParser.php:23`,
`RssFeedParser.php:27`, `WebDavClient.php:149-151`); no unserialize on app data; no createTemplate;
SQL allow-lists and bound params (`FileRepository.php:105-115`, `WidgetSessionRepository.php:313-320`,
`UseLogRepository.php:152-156`, `SearchIndexRepository.php:230-247`, `RagScopeFilter.php:21-41`,
`MariaDBVectorStorage.php:99-146`, `PluginManager.php:190-221`); Process argv arrays / escaped shell
strings; mail via Symfony Mime; Content-Disposition sanitised; plugin {userId} checks (live 401);
synascriber secret (`TranscriberController.php:190-201` hash_equals, 503 when empty); no HTTP plugin
install; plugin secrets masked; compute/c2pa/voice/transcriber/egress/watchdog auth.

## Hardening

Route-level guard for /api/v1/user/{userId}/plugins/*; one shared outbound client with private-network
policy; ImageMagick policy.xml; non-root PHP worker and compute sidecar; `/api/v1/news/image` passes SVG
inline without CSP (`NewsController.php:126-130`); PluginAssetResponder serves text/html inline without
CSP; Messenger PHP serializer over unauthenticated Redis (chain with SSRF); LIKE wildcards unescaped
(`FileRepository.php:551`, `UsageStatsService.php:809`, `AdminController.php:154`,
`OidcUserService.php:231-234`); default COMPUTE_TOKEN (`docker-compose.yml:393,1461`); voice container
gets whole backend env file (:1534); transcriber `===` (`server.js:22`); synaform client MIME/SVG inline
(:2054, :2103, :2227), no decompressed-size cap, templatesDownload :1192; Whisper model path without
basename (`WhisperService.php:450-453`).

## Coverage gaps

F7/F8 need a real provider; F6 not via chat; F3 crash/MSL and siblings not sent; F1 delete, F4 chat
context, F5 read primitive traced/offline only. Not examined: ephemeral reaping, C2PA end to end, WOPI
put/lock, Tika/Collabora inputs, UserPluginProvisioningSubscriber, API-key scopes on plugin routes,
whether a user can set P_synaform.enabled via a generic config endpoint, migrations. Synaform frontend
spot-checked; synascriber not live; egress/watchdog/transcriber source only.

## State left

Users 4924/4928/4929 (4924 keeps a castingdata config row with dummy key); demo upload 362 created and
deleted; 48-byte marker in synaplan-compute /tmp; compute binary started a second time inside its
container (startup orphan sweep ran, then exit on port conflict); one no-op admin model-import UPDATE;
scratch helpers with test-user passwords under scratchpad inj/, inj-plug/, inj-sidecar/.
