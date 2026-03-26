# PR #627 Review – Fix/video gen poll

## Kritische Probleme

### 1. Schwerer I/O auf GET-Endpoint (`checkVideoJob`)

**Datei:** `MediaGenerationService::checkVideoJob()`

Das gesamte Video wird während eines GET-Poll-Requests von Google heruntergeladen und auf Disk gespeichert. Der Download hat ein Timeout von 120 Sekunden (`downloadVideoRaw`). Das wird sehr wahrscheinlich zu HTTP-Timeouts beim Client führen, da der GET-Endpoint blockiert, bis das Video komplett heruntergeladen und gespeichert ist.

**Empfehlung:** Download asynchron verarbeiten (z.B. über Symfony Messenger) oder den Download in den `startVideo`-Flow verlagern, sodass der Poll-Endpoint nur den Status prüft und nie selbst I/O macht.

### 2. Video-URI geht bei Download-Fehler verloren

**Datei:** `MediaGenerationService::checkVideoJob()`, Zeilen im `catch (\Throwable)`-Block

Wenn der Download fehlschlägt, wird `status` auf `'processing'` zurückgesetzt, aber die `videoUri` wird **nicht** in `jobData` gespeichert. Beim nächsten Poll wird erneut `pollVideoOperationOnce` aufgerufen – die Google-Operation könnte aber bereits abgelaufen oder bereinigt sein, wodurch das Video endgültig verloren geht.

```php
// Aktuell: videoUri wird nirgends gespeichert
$jobData['status'] = 'processing';
$this->storeVideoJobState($jobId, $jobData);
throw new \RuntimeException('Video download/save failed: '.$e->getMessage(), 0, $e);
```

**Empfehlung:** `videoUri` im `jobData` speichern, sobald die Operation abgeschlossen ist, damit der Download bei Fehler wiederholt werden kann, ohne Google erneut zu pollen.

### 3. `instanceof GoogleProvider` in AiFacade bricht Provider-Abstraktion

**Datei:** `AiFacade.php` – 4 Methoden mit `instanceof GoogleProvider`-Check

Die Facade prüft in `startVideoGeneration`, `pollVideoOperation`, `downloadVideoContent` und `downloadVideoRaw` explizit auf `GoogleProvider`. Das widerspricht dem Provider-Pattern komplett und macht es unmöglich, Async-Video für andere Provider (z.B. Runway, Pika) hinzuzufügen, ohne die Facade zu ändern.

**Empfehlung:** Ein `AsyncVideoProviderInterface` mit den Methoden `startVideoOperation`, `pollVideoOperationOnce`, `downloadVideoRaw` einführen. Die Facade prüft dann nur `instanceof AsyncVideoProviderInterface`.

---

## Wichtige Probleme

### 4. Race Condition bei gleichzeitigen Poll-Requests

**Datei:** `MediaGenerationService::checkVideoJob()`

Wenn zwei Clients gleichzeitig pollen und beide `status === 'processing'` sehen, rufen beide `pollVideoOperationOnce` auf. Wenn die Operation fertig ist, versuchen beide den Download parallel – doppelte Downloads, doppelte Dateien, doppelte `recordUsage`.

**Empfehlung:** Optimistic Locking mit Cache-Version oder ein `finalizing`-Guard, der den zweiten Request sofort mit `'processing'` beantwortet (teilweise schon vorhanden, aber nicht vor dem Poll-Call).

### 5. `canUseInternalTestProvider` liest `$_SERVER`/`$_ENV` direkt

**Datei:** `ProviderRegistry.php`

```php
private function canUseInternalTestProvider(string $providerName): bool
{
    $appEnv = strtolower((string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod'));
    return 'test' === $providerName && 'prod' !== $appEnv;
}
```

Statt Superglobals sollte `%kernel.environment%` als Constructor-Parameter injiziert werden. Das ist der Symfony-Standard und testbar.

### 6. Cache TTL von 10 Minuten könnte zu kurz sein

**Datei:** `MediaGenerationService`, `VIDEO_JOB_TTL_SECONDS = 600`

Video-Generierung kann bis zu 5 Minuten dauern + Download-Zeit. Wenn ein Nutzer den Job startet und erst nach ein paar Minuten zu pollen beginnt, könnte der Cache bereits abgelaufen sein. Besonders kritisch wenn der Download fehlschlägt und der Status auf `'processing'` zurückgesetzt wird.

**Empfehlung:** TTL auf mindestens 900–1200 Sekunden (15–20 Minuten) erhöhen, oder den TTL bei jedem State-Update erneuern (wird aktuell schon gemacht via `storeVideoJobState`, aber die initiale TTL ist knapp).

### 7. `checkVideoJob` ist zu lang (~80 Zeilen)

**Datei:** `MediaGenerationService::checkVideoJob()`

Die Methode handhabt Status-Prüfung, Polling, Download, Speichern und Usage-Recording in einem Block. Gemäß Projekt-Konventionen sollten Methoden in Services aufgeteilt werden, wenn sie zu komplex werden.

**Empfehlung:** Aufteilen in `handleProcessingJob()`, `handleCompletedDownload()` etc.

---

## Kleinere Probleme

### 8. Fehlende `declare(strict_types=1)` in neuen Test-Dateien

Die meisten existierenden Tests im Projekt verwenden `declare(strict_types=1)`. Die neuen Test-Dateien (`GoogleProviderAsyncVideoTest.php`, `AiFacadeAsyncVideoTest.php`) haben es nicht, `ProviderRegistryTest.php` hat es. Sollte konsistent sein.

### 9. Frontend: Media-Parts könnten dupliziert werden

**Datei:** `ChatView.vue`

```typescript
const preservedMediaParts = message.parts.filter(
  (p) => p.type === 'image' || p.type === 'video' || p.type === 'audio'
)
message.parts = [...newParts, ...preservedMediaParts]
```

Wenn `newParts` bereits Media-Parts enthält (z.B. bei erneutem Parsing), werden diese mit den `preservedMediaParts` dupliziert. Ein Deduplizierungscheck (z.B. über URL) wäre sicherer.

### 10. `startVideoOperation` und andere Google-spezifische Methoden sind `public`

**Datei:** `GoogleProvider.php`

`startVideoOperation`, `pollVideoOperationOnce`, `downloadVideoContent`, `downloadVideoRaw` sind alle `public`. Diese sollten idealerweise über ein Interface definiert sein, statt als öffentliche Methoden direkt auf der konkreten Provider-Klasse.

---

## Positives

- Gute Test-Abdeckung: 4 neue Test-Dateien mit sinnvollen Edge Cases (Safety-Fehler, Download-Failure, Cached-Result)
- Saubere Aufteilung der Google-Provider-Methoden (start/poll/download)
- Job-ID-Validierung mit Regex (`/^[a-f0-9]{32}$/`)
- User-ID-Check verhindert Zugriff auf fremde Jobs
- OpenAPI-Annotations sind vollständig
- Progress-Callback im `MediaGenerationHandler` gibt dem User Feedback während der Generierung
