# Reviewer notes: authz-content (chats, messages, files, shares; IDOR / multi-tenancy)

Commit a2e57b08d. Local stack, `test` provider. Saved by the lead from the reviewer's hand-back.
Actors: demo = demo@synaplan.com (id 2); B = audit-authzA-1@example.com (id 4916); anon = no credentials.
B's fixtures: chat 4700 (private), chat 4705, file 358 (secretB.txt), widget 585
(`wdg_baa5bd77d5dc1823df3240ebf0b93fd1`), generated image 361. Paths relative to backend/src/.

How /api/v1/messages/stream (PUBLIC_ACCESS, security.yaml:225) authenticates: `StreamCallerResolver`
takes (a) firewall user, (b) `X-Widget-Id` + `X-Widget-Session` -> acting user = WIDGET OWNER
(widgetVisitor() L85, session created on demand L99), (c) `guestSession` -> shared guest processing user.

## F1 Critical, Confirmed: anonymous read/write of any chat of a widget owner (body chatId)

- `Service/Widget/Turn/WidgetTurnGate.php:78` takes chatId from the anonymous body.
- `Service/Widget/Turn/WidgetConversation.php:113` `ownedChat(session chat) ?? ownedChat($turn->chatId)`;
  :142-147 checks only chat.userId === widget owner; :70 `attachChat` binds the session to it.
- `Controller/WidgetPublicController.php` history() 773-930 returns up to 50 messages of the session chat.
- Done right: guest binding `StreamTurnPreparer.php:142-151`; widget file attach `WidgetConversation.php:207-228`.

Repro:
```
POST /api/v1/widget/wdg_baa5.../message  {"sessionId":"<new>","text":"hi","chatId":4700}  -> 200 SSE
GET  /api/v1/widget/wdg_baa5.../history?sessionId=<same>
 -> 200 {"success":true,"chatId":4700,"messages":[{"id":4049,"direction":"IN",
        "text":"PRIVATE-NOTE audit-authzA: my vault code is ZEBRA-4471","messageType":"WEB",...},...]}
```
CVSS AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:L/A:N = 8.2. CWE-639, CWE-862.
Fix: `$chat = $this->ownedChat($turn, $turn->session->getChatId());` and drop chatId from WidgetTurn /
WidgetTurnGate:78 (or accept only widget-sourced chat already bound to this session). history and
downloadWidgetFile also require a widget-sourced chat. Test: WidgetConversationTest "fresh session +
body chatId of an owner web chat creates a new chat"; history never returns messageType WEB.
Detection: logs as ordinary widget turn. Warn when a widget turn names a chatId != session chat;
retro-hunt widget sessions whose chat source is not `widget`.

## F2 Critical, Confirmed: anonymous read of any owner file/chat via /api/v1/messages/stream

- `Service/Chat/Stream/StreamCallerResolver.php:85-99`; `StreamTurnPreparer.php:130` (loadChat owner
  compare only; session binding 142-151 is guest-only); `:254` attachFiles compares file owner to
  caller->user (= widget owner); `WidgetPublicController.php:981` ($isOwnerFile) and :1002 (isFileInChat).
Repro (B's private file 358):
```
GET  /api/v1/widget/<wdg>/files/358/download?sessionId=<sid>  -> 403 "File not associated with this chat"
POST /api/v1/messages/stream  X-Widget-Id:<wdg> X-Widget-Session:<sid>
     {"chatId":4700,"message":"x","fileIds":"358","trackId":<ts>}  -> 200 SSE "Processing 1 file(s)..."
GET  /api/v1/widget/<wdg>/files/358/download?sessionId=<sid>  -> 200 "CONFIDENTIAL audit-authzA payroll: alice 100000"
```
CVSS 8.2. CWE-639, CWE-863.
Fix: in loadChat, if caller->widgetSession and its chatId !== turn chatId -> error; in attachFiles use
`callerMayAttach` (user: owner match; widget: file.userSessionId === widgetSession.id; guest: file
already in the session chat). Longer term: explicit principal instead of substituting the owner.
Tests: functional tests on /messages/stream with widget headers (owner web chatId -> error event, no
row; owner fileIds -> not attached; legit session chat works).

## F3 High, Confirmed: GET /api/v1/messages/{id}/status has no owner check

`Controller/MessageController.php:937-952`; `Service/Message/MessageEnqueueService.php:81-104`
find($messageId) then returns status, topic, language and the OUT message text.
Repro as demo, message 4049 belongs to B:
```
GET /api/v1/messages/4049/status -> 200 {"status":"complete","topic":"general","language":"en",
   "response":"... canned reply to your message 'private-note audit-authza: my vault code is zebra-4471' ..."}
GET /api/v1/messages/4049 -> 404 (control)
```
CVSS AV:N/AC:L/PR:L/UI:N/S:U/C:H/I:N/A:N = 6.5. CWE-639.
Fix: getMessageStatus(int $messageId, int $userId) returns null unless owner. Test: A's id as B -> 404.
Detection: alert on one account requesting many distinct ids on this route.

## F4 Medium, Confirmed: guests read other guests' generated files

`StreamCallerResolver` guest(): all guests act as one processing user; `StreamTurnPreparer.php:254`;
`Controller/GuestChatController.php:550-557` (download), :607-631 (export).
```
GET  /api/v1/guest/files/<guestB>/363/download -> 403
POST /api/v1/messages/stream {"guestSession":"<guestB>","chatId":4708,"message":"hello","fileIds":"363"} -> 200
GET  /api/v1/guest/files/<guestB>/363/download -> 200 image/png 3957 bytes
```
Controls that hold: guest B naming guest A's chat refused; guest naming a registered user's file not attached.
CVSS 5.3. CWE-639. Fix: guests ignore fileIds or accept only files already in the session chat.

## F5 Low, Confirmed: public share page leaks an edited-away first question

`Controller/SharedChatPageController.php:73-111`, generateDescription() 182-204 (first IN message, no
version filter). JSON endpoint (`ChatController::getShared` 964-995) hides superseded edits.
Chat 4705: `GET /shared/<token>` -> `<meta name="description" content="my password is HUNTER2-authzA, summarise">`
(also og:description, noscript). CVSS 4.3. CWE-200. Fix: build from the same filtered list as the API.

## F6 Low, Suspected: anonymous serving by filename pattern under /api/v1/files/uploads/

`Controller/StaticUploadController.php` skips checks when the basename matches
`Service/Media/OutboundChannelMedia.php:24-35` (`tts_*`, `ai_i2vsrc_*`, `^\d+_[a-z]+_\d+\.[a-z0-9]+$`).
Observed: anon GET of another account's `.../tts_test_6ad5d87547aba.mp3` -> 200. Low-entropy names
(`Service/Message/Handler/Media/MediaUploadStore.php:64,134`); uploads keep client basename
(`Service/File/FileStorageService.php:309-327`). Path guessing not attempted. CVSS 3.7. CWE-330/284.
Fix: signed expiring URL (as `MediaAccessTokenService`) or dedicated dir with 128-bit names.

## Ruled out (dynamic cross-tenant tests as demo vs B)

Chat read/write/share/archive/tags/export/copy (`ChatController::denyConversation` 1122-1141,
`ChatLibraryController.php:209`); ConversationFileController detach (82-97); message export
(`MessageExportController.php:76`), memories, ask-user (`ChatAskUserController.php:51`), again;
MessageVersionController::owned(); stream as signed-in user (foreign chatId/fileIds/continueMessageId/
quote/promptId refused); save-cancelled/stop-stream (`CancelledTurns::assertOwnsTrack`); stream
re-attach (`ChatRunService::authorize`, hash_equals); files by id (`FileController::isFileAccessibleByUser`
1807-1830, findByUserAndIds, `FileSendService`:31); media token (HMAC, purpose-bound, 30 min,
user-scoped); WOPI; /files/uploads and /up for private files (anon 401, other 403);
GHSA-2x6x-8r52-pm58 fix (`Service/File/UploadRequestPath.php`, traversal variants 404);
GHSA-qqg9-qv48-j8m8 fix (`UploadedFileSandboxSubscriber.php` CSP sandbox + nosniff); projects
(findOwned); IAM shares; public share token 96 bits (`Entity/Chat.php:185-190`,
`ChatRepository.php:130`), un-share -> 404; shared page escaping (:449); search; feedback
(denyMessage 813); bundle; guest chat binding.

## Hardening

Explicit principal (user | widget session | guest session) instead of substituting a real user;
`findOwnedBy($id,$userId)` helpers + architecture test banning bare `->find($id)` on Chat/Message/File
in controllers and Service/Chat/Stream/; two-tenant functional matrix; rotate share token on un-share
(reused today) and reduce getShared DTO (internal ids, filePath with user id, cost, model keys);
uniform 403/404; non-sequential public ids; rate-limit widget/guest session creation per IP.

## Coverage gaps

Agent pin resolution, PromptController file linking, RAG, widget owner API, WidgetLegacyController,
/v1 key routes, inbound channel media, ConversationCopyService, bundle import internals; IAM group
shares positive path; widget allowedDomains (default empty in tests); F6 guessing; F5 OG image.

## Residue

User 4916, chats 4700/4705, files 358/361, widget 585, widget sessions, guest sessions (chats
4707/4708, file 363). Side effect on seeded data: one message ("what does the file say") + canned
reply in the demo user's previously empty chat 594. Cookie jars in scratchpad/authzA hold test-account
session tokens.
