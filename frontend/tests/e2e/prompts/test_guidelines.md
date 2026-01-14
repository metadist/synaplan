# Playwright Test Guidelines (Furkan / Synaplan)

Ziel: einfache, leserliche und stabile Tests.
Keine Over-Engineering-Logik, keine unnötigen Abstraktionen.

---

## 1. Stil

* Simple code > clever code
* Nur so viel Logik wie nötig
* Lesbarkeit vor Kürze
* Keine unnötigen TypeScript-Spielereien
* **Nur wie ein User handeln:** Navigiere über die UI (Nav-Bar/Buttons/Links), klicke nur auf Elemente, die ein User klicken kann. Keine versteckten Inputs direkt ansteuern, außer wenn der User sie ebenfalls so erreichen würde (z. B. sichtbarer Upload-Button öffnet den Datei-Dialog).

---

## 2. Locator-Regeln

* Elemente möglichst **immer frisch** lokalisieren:

  * `await page.locator(...).click();`
* Bevorzugt: `data-testid` (dann Rolle/Name, dann CSS)
* Kontext nutzen:

  ```ts
  await page.locator(selectors.chat.againDropdown).click();
  ```
* Keine überkomplizierten Selektoren

---

## 3. Warten & Synchronisation

* Sichtbarkeit:

  ```ts
  await page.locator(sel).waitFor({ state: 'visible' });
  ```
* Loader:

  ```ts
  const loader = page.locator(selectors.chat.loadIndicator);
  await loader.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
  await loader.waitFor({ state: 'hidden' });
  ```
* Keine eigenen Polling-Schleifen, wenn `expect`/`waitFor` reicht

---

## 4. Assertions

* Text immer normalisieren:

  ```ts
  const text = (await locator.innerText()).trim().toLowerCase();
  await expect(text).toContain('success');
  ```
* Regex nur wenn nötig
* In Loops: `expect.soft(...)` mit sinnvoller Nachricht

---

## 5. Schleifen & Dropdowns

* Normale `for (let i = 0; i < count; i++)`-Schleifen
* Pro Durchlauf:

  1. Toggle im richtigen Container frisch holen
  2. Dropdown öffnen
  3. `nth(i)` holen
  4. Text holen → z. B. `"ollama"` skippen
  5. Falls passend: klicken, Antwort prüfen
* Modellnamen nur speichern, wenn wirklich nötig – sonst direkt am `nth(i)` prüfen

Beispiel-Muster:

```ts
const optionCount = await page.locator('button.dropdown-item').count();

for (let i = 0; i < optionCount; i++) {
  const toggle = page
    .locator(selectors.chat.aiAnswerBubble)
    .last()
    .locator('[data-testid="btn-message-model-toggle"]');

  await toggle.click();

  const option = page.locator('button.dropdown-item').nth(i);
  await option.waitFor({ state: 'visible' });

  const label = (await option.innerText()).toLowerCase().trim();
  if (label.includes('ollama')) {
    await toggle.click();
    continue;
  }

  await option.click();
  // ... loader warten + success prüfen
}
```

---

## 6. Fehlerbehandlung

* Erst Fehler zulassen, dann gezielt fixen
* Keine globale „Defensive Programming“-Schicht
* `console.log` nur gezielt zur Fehlersuche

---

## 7. Test-Struktur

* Login gekapselt:

  ```ts
  await login(page);
  ```
* Klarer Ablauf:

  1. Arrange (Login, Startzustand)
  2. Act (Klicks / Eingaben)
  3. Assert (Text/Zustände)

---

## 8. Regeln für KI-Helfer

* Diese Guideline respektieren
* Keine eigenen Mini-Frameworks bauen
* Code so schreiben, dass ein Anfänger ihn linear lesen kann
* Änderungen in kleinen Schritten vorschlagen
* Wenn unsicher: lieber einfach statt zu komplex
