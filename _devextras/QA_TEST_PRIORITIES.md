# QA Test-Prioritäten: rework/chat-widget-steps

## 🎯 Kritische Tests (MUSS funktionieren)

### 1. Widget-Erstellung & Grundfunktionalität ⚠️ HOCHSTE PRIORITÄT

**Warum kritisch:** Kernfunktionalität des Features

- [ ] **Widget erstellen (Simple Form)**
  - [ ] Widget-Name eingeben und speichern
  - [ ] Website-URL eingeben (z.B. `http://localhost:5173`)
  - [ ] Widget wird erfolgreich erstellt
  - [ ] Widget erscheint in der Liste

- [ ] **Widget erstellen (Advanced Config)**
  - [ ] Alle Tabs durchgehen (Branding, Verhalten, Datei-Upload, Erweitert)
  - [ ] Konfiguration speichern
  - [ ] Änderungen werden gespeichert

- [ ] **Widget aktivieren/deaktivieren**
  - [ ] Widget kann aktiviert werden
  - [ ] Widget kann deaktiviert werden
  - [ ] Status wird korrekt angezeigt

### 2. Widget-Einbettung & Laden ⚠️ KRITISCH

**Warum kritisch:** Widget muss auf externen Seiten funktionieren

- [ ] **Widget-Code kopieren**
  - [ ] Embed-Code wird angezeigt
  - [ ] Code kann kopiert werden
  - [ ] Code enthält korrekte widgetId und apiUrl

- [ ] **Widget auf externer Seite einbetten**
  - [ ] Widget lädt korrekt (`widget.js` wird geladen)
  - [ ] Keine CORS-Fehler
  - [ ] Keine 404-Fehler
  - [ ] Widget-Button erscheint

- [ ] **Widget öffnen/schließen**
  - [ ] Button öffnet Chat-Window
  - [ ] Chat-Window schließt korrekt
  - [ ] Animationen funktionieren

### 3. Chat-Funktionalität ⚠️ KRITISCH

**Warum kritisch:** Hauptfunktion des Widgets

- [ ] **Nachrichten senden/empfangen**
  - [ ] Text-Nachricht senden
  - [ ] Antwort wird empfangen
  - [ ] Streaming-Responses funktionieren (Text erscheint nach und nach)
  - [ ] Nachrichten werden korrekt angezeigt

- [ ] **Chat-Historie**
  - [ ] Historie wird beim Öffnen geladen
  - [ ] Alte Nachrichten werden angezeigt
  - [ ] Neue Nachrichten werden hinzugefügt

- [ ] **Session-Management**
  - [ ] Session wird gespeichert (localStorage)
  - [ ] Session bleibt nach Seiten-Reload erhalten
  - [ ] Neue Session wird erstellt wenn nötig

### 4. Datei-Upload ⚠️ WICHTIG

**Warum kritisch:** Neues Feature, muss funktionieren

- [ ] **Datei hochladen**
  - [ ] Einzelne Datei hochladen
  - [ ] Mehrere Dateien gleichzeitig hochladen
  - [ ] Datei-Limits werden eingehalten
  - [ ] Datei-Größe wird validiert

- [ ] **Dateien in Chat**
  - [ ] Dateien werden in Nachrichten angezeigt
  - [ ] Datei-Download funktioniert
  - [ ] Datei-Icons werden korrekt angezeigt

### 5. Markdown-Rendering ⚠️ WICHTIG

**Warum kritisch:** Neue Markdown-Features müssen funktionieren

- [ ] **Basis-Markdown**
  - [ ] **Bold** Text wird fett dargestellt
  - [ ] *Italic* Text wird kursiv dargestellt
  - [ ] Links funktionieren
  - [ ] Listen werden korrekt dargestellt

- [ ] **Code-Blöcke**
  - [ ] Code-Blöcke werden mit Syntax-Highlighting angezeigt
  - [ ] Verschiedene Sprachen werden erkannt (JavaScript, Python, etc.)
  - [ ] Code kann kopiert werden

- [ ] **Erweiterte Features**
  - [ ] Tabellen werden korrekt dargestellt
  - [ ] Mermaid-Diagramme werden gerendert (falls verwendet)
  - [ ] KaTeX Math-Formeln funktionieren (falls verwendet)

## 🔍 Wichtige Tests (Sollte funktionieren)

### 6. UI/UX-Features

- [ ] **Theme-Switching**
  - [ ] Dark Mode aktivieren/deaktivieren
  - [ ] Theme wird gespeichert
  - [ ] Farben werden korrekt angewendet

- [ ] **Mobile-Responsive**
  - [ ] Widget auf Mobile-Gerät testen
  - [ ] Touch-Interaktionen funktionieren
  - [ ] Layout passt sich an

- [ ] **Widget-Button**
  - [ ] Button-Icon wird korrekt angezeigt
  - [ ] Custom Icon funktioniert
  - [ ] Unread-Count Badge wird angezeigt

### 7. Widget-Konfiguration

- [ ] **Branding**
  - [ ] Position ändern (bottom-right, bottom-left, etc.)
  - [ ] Primary Color ändern
  - [ ] Icon Color ändern
  - [ ] Button-Icon auswählen

- [ ] **Verhalten**
  - [ ] Auto-Open aktivieren/deaktivieren
  - [ ] Welcome Message konfigurieren
  - [ ] Message Limit setzen

- [ ] **Datei-Upload-Konfiguration**
  - [ ] Datei-Upload aktivieren/deaktivieren
  - [ ] File Upload Limit setzen
  - [ ] Limits werden validiert

### 8. Test-Mode im Dashboard

- [ ] **Widget-Test im Dashboard**
  - [ ] Test-Chat öffnet sich
  - [ ] Test-Mode funktioniert ohne localStorage
  - [ ] Nachrichten können gesendet werden
  - [ ] Responses werden empfangen

## 📋 Nice-to-Have Tests (Kann später getestet werden)

### 9. Memories-Integration

- [ ] Memories werden in Chat verwendet
- [ ] Memory-Referenzen funktionieren

### 10. Export-Funktion

- [ ] Chat-Export funktioniert
- [ ] Exportierte Datei enthält alle Nachrichten

### 11. Edge Cases

- [ ] Sehr lange Nachrichten
- [ ] Viele Nachrichten (Performance)
- [ ] Netzwerk-Fehler (Offline-Modus)
- [ ] Verschiedene Browser (Chrome, Firefox, Safari)

## 🚨 Bekannte Probleme prüfen

- [ ] CORS-Fehler (sollte nicht auftreten)
- [ ] 404-Fehler für widget.js (sollte nicht auftreten)
- [ ] Datei-Upload-Limits werden eingehalten
- [ ] Session-Persistenz funktioniert

## 📊 Test-Report Vorlage

Nach dem Testen, dokumentiere:

```
## Test-Datum: [Datum]
## Tester: [Name]
## Branch: rework/chat-widget-steps

### ✅ Funktioniert
- [Liste der funktionierenden Features]

### ❌ Fehler gefunden
- [Liste der Fehler mit Beschreibung]

### ⚠️ Verbesserungsvorschläge
- [Liste der Verbesserungen]
```

## 🎯 Empfohlene Test-Reihenfolge

1. **Zuerst:** Widget-Erstellung & Grundfunktionalität (1-2 Stunden)
2. **Dann:** Widget-Einbettung & Laden (30 Minuten)
3. **Dann:** Chat-Funktionalität (1 Stunde)
4. **Dann:** Datei-Upload (30 Minuten)
5. **Dann:** Markdown-Rendering (30 Minuten)
6. **Zuletzt:** UI/UX & Edge Cases (1-2 Stunden)

**Gesamtzeit:** ~5-7 Stunden für vollständiges Testing

## 🔧 Test-Setup

### Vor dem Testen:

```bash
# 1. Branch wechseln
git checkout rework/chat-widget-steps

# 2. Dependencies installieren
docker compose exec frontend-widgets npm install

# 3. Container neu starten
docker compose restart frontend-widgets

# 4. Widget bauen (falls nötig)
make -C frontend build-widget

# 5. Prüfen ob widget.js existiert
ls -la frontend/dist-widget/widget.js
```

### Test-Umgebung:

- **Frontend:** http://localhost:5173
- **Backend:** http://localhost:8000
- **Widget:** http://localhost:8000/widget.js
- **Test-HTML:** Erstelle eine einfache `test.html` mit Widget-Embed-Code

---

**Erstellt am:** 2026-01-28  
**Für Branch:** `rework/chat-widget-steps`
