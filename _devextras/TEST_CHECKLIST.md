# Test-Checkliste: rework/chat-widget-steps Branch

## Übersicht

Dieses Dokument beschreibt die wichtigsten Änderungen und Features auf dem Branch `rework/chat-widget-steps` im Vergleich zu `main`, die getestet werden müssen.

## Hauptfeatures zum Testen

### 1. Chat Widget - Neue Features

#### Datei-Upload
- ✅ Mehrere Dateien gleichzeitig hochladen
- ✅ FilePicker-Komponente für Dateiauswahl
- ✅ Datei-Limits konfigurierbar
- ✅ Dateien in Chat-Nachrichten anzeigen
- ✅ Datei-Download funktionieren

#### Markdown-Rendering
- ✅ Verbessertes Markdown-Rendering mit Syntax-Highlighting
- ✅ KaTeX Integration für Math-Formeln (`$E = mc^2$`)
- ✅ Mermaid-Diagramme (`\`\`\`mermaid`)
- ✅ Code-Blöcke mit Syntax-Highlighting
- ✅ Tabellen, Listen, Links

#### Session-Management
- ✅ Verbesserte Session-Verwaltung
- ✅ Chat-Historie pro Session
- ✅ Session-Persistenz über localStorage
- ✅ Test-Mode ohne localStorage

#### Weitere Widget-Features
- ✅ Chat-Export Funktion
- ✅ Dark/Light Mode Toggle
- ✅ Unread-Count Badge
- ✅ Mobile-Responsive Design
- ✅ Verbesserte Animationen und Transitions

### 2. Widget-Erstellung & Konfiguration

#### SimpleWidgetForm
- ✅ Vereinfachtes Setup-Formular für schnelle Widget-Erstellung
- ✅ Widget-Name eingeben
- ✅ Website-URL konfigurieren
- ✅ Einfache Konfiguration

#### AdvancedWidgetConfig
- ✅ Erweiterte Konfiguration mit Tabs:
  - **Branding Tab**: Position, Theme, Farben, Button-Icons
  - **Verhalten Tab**: Auto-Open, Welcome Message, Message Limits
  - **Datei-Upload Tab**: Aktivierung, Limits, Konfiguration
  - **Erweitert Tab**: Weitere erweiterte Optionen
- ✅ Live-Preview der Konfiguration
- ✅ Farbauswahl mit Color-Picker

#### WidgetCreationWizard
- ✅ Verbesserter Wizard für Widget-Erstellung
- ✅ Schritt-für-Schritt Anleitung

#### SetupChatModal
- ✅ Modal für Widget-Setup
- ✅ Test-Chat im Dashboard

#### WidgetSuccessModal
- ✅ Erfolgs-Modal nach Widget-Erstellung
- ✅ Embed-Code anzeigen
- ✅ Direkt zum Widget navigieren

### 3. Memories (User Memories)

#### MemoryFormDialog
- ✅ Überarbeitetes Dialog für Memory-Erstellung/Bearbeitung
- ✅ Verbesserte UI/UX

#### MemoryListView
- ✅ Verbesserte Liste der Memories
- ✅ Suche und Filter

#### MemoriesView
- ✅ Überarbeitete Ansicht für Memories
- ✅ Integration in Chat-Nachrichten

### 4. Backend-Änderungen

#### Neue Services
- ✅ `WidgetSetupService`: Neuer Service für Widget-Setup-Logik
- ✅ Verbesserte `WidgetService` Funktionalität
- ✅ Verbesserte `WidgetSessionService` Funktionalität
- ✅ Verbesserte `UserMemoryService` Funktionalität

#### Controller-Erweiterungen
- ✅ `WidgetController`: Erweiterte API-Endpunkte
- ✅ `WidgetPublicController`: Öffentliche Widget-API
- ✅ `UserMemoryController`: Verbesserte Memories-API
- ✅ `PromptController`: Erweiterte Prompt-Funktionalität
- ✅ `MessageController`: Neue Endpunkte

#### Entity-Änderungen
- ✅ `WidgetSession`: Verbesserte Session-Verwaltung
- ✅ Neue Felder und Beziehungen

### 5. Markdown-System

#### Neue Composables
- ✅ `useMarkdown.ts`: Neues Composable für Markdown-Rendering
- ✅ `useMarkdownKatex.ts`: KaTeX-Integration für Math-Formeln
- ✅ `useMarkdownMermaid.ts`: Mermaid-Diagramm-Support

#### Styling
- ✅ `markdown.css`: Zentrale Markdown-Styles
- ✅ Verbesserte Code-Block-Darstellung
- ✅ Syntax-Highlighting Styles

## Detaillierte Test-Checkliste

### Widget-Funktionalität

#### Widget-Erstellung
- [ ] Widget über SimpleWidgetForm erstellen
- [ ] Widget über AdvancedWidgetConfig erstellen
- [ ] Widget-Name setzen und speichern
- [ ] Website-URL konfigurieren
- [ ] Widget aktivieren/deaktivieren
- [ ] Widget löschen

#### Widget-Konfiguration
- [ ] **Branding Tab:**
  - [ ] Position ändern (bottom-right, bottom-left, top-right, top-left)
  - [ ] Theme ändern (light/dark)
  - [ ] Primary Color ändern (Color-Picker)
  - [ ] Icon Color ändern
  - [ ] Button-Icon auswählen (chat, headset, help, robot, message, support, custom)
  - [ ] Custom Icon URL eingeben

- [ ] **Verhalten Tab:**
  - [ ] Auto-Open aktivieren/deaktivieren
  - [ ] Welcome Message konfigurieren
  - [ ] Message Limit setzen
  - [ ] Max File Size konfigurieren

- [ ] **Datei-Upload Tab:**
  - [ ] Datei-Upload aktivieren/deaktivieren
  - [ ] File Upload Limit setzen (0 = unlimited)
  - [ ] Limits werden korrekt validiert

- [ ] **Erweitert Tab:**
  - [ ] Alle erweiterten Optionen testen

#### Widget-Testing im Dashboard
- [ ] Test-Chat im Dashboard öffnen
- [ ] Widget-Vorschau funktioniert
- [ ] Test-Mode funktioniert ohne localStorage
- [ ] Chat-Nachrichten senden/empfangen
- [ ] Streaming-Responses funktionieren
- [ ] Chat-Historie wird geladen
- [ ] Session-Persistenz funktioniert

#### Widget-Einbettung
- [ ] Widget auf externer Seite einbetten
- [ ] Embed-Code kopieren und verwenden
- [ ] Widget lädt korrekt auf externer Seite
- [ ] API-URL wird korrekt erkannt
- [ ] CORS funktioniert korrekt

### Chat-Funktionalität

#### Grundlegende Chat-Features
- [ ] Nachrichten senden
- [ ] Nachrichten empfangen
- [ ] Streaming-Responses funktionieren
- [ ] Chat-Historie wird geladen
- [ ] Nachrichten werden korrekt sortiert
- [ ] Nachrichten-Timestamp wird angezeigt

#### Datei-Upload im Chat
- [ ] Einzelne Datei hochladen
- [ ] Mehrere Dateien gleichzeitig hochladen
- [ ] Datei-Limits werden eingehalten
- [ ] FilePicker öffnet sich korrekt
- [ ] Dateien werden in Nachrichten angezeigt
- [ ] Datei-Download funktioniert
- [ ] Datei-Größe wird validiert
- [ ] Fehlerbehandlung bei zu großen Dateien

#### Markdown-Rendering
- [ ] **Text-Formatierung:**
  - [ ] Bold (`**text**`)
  - [ ] Italic (`*text*`)
  - [ ] Links (`[text](url)`)
  - [ ] Strikethrough (`~~text~~`)
  - [ ] Highlight (`==text==`)

- [ ] **Code:**
  - [ ] Inline Code (`` `code` ``)
  - [ ] Code-Blöcke mit Syntax-Highlighting
  - [ ] Verschiedene Programmiersprachen werden erkannt
  - [ ] Code kopieren funktioniert

- [ ] **Listen:**
  - [ ] Bullet Lists (`- item`)
  - [ ] Numbered Lists (`1. item`)
  - [ ] Task Lists (`- [ ] task`)

- [ ] **Tabellen:**
  - [ ] Tabellen werden korrekt dargestellt
  - [ ] Tabellen-Styling funktioniert

- [ ] **Mermaid-Diagramme:**
  - [ ] Mermaid-Code-Blöcke werden erkannt
  - [ ] Diagramme werden gerendert
  - [ ] Verschiedene Diagramm-Typen (flowchart, sequence, etc.)

- [ ] **KaTeX Math:**
  - [ ] Inline Math (`$E = mc^2$`)
  - [ ] Block Math (`$$E = mc^2$$`)
  - [ ] Komplexe Formeln werden korrekt dargestellt

- [ ] **Weitere Features:**
  - [ ] Blockquotes (`> quote`)
  - [ ] Horizontal Rules (`---`)
  - [ ] Headings (`# Heading`)
  - [ ] Footnotes (`[^1]`)
  - [ ] Definition Lists

### UI/UX-Features

#### Theme & Styling
- [ ] Dark Mode aktivieren/deaktivieren
- [ ] Light Mode aktivieren/deaktivieren
- [ ] Theme-Switching funktioniert
- [ ] Farben werden korrekt angewendet
- [ ] Custom Colors funktionieren

#### Mobile-Responsive
- [ ] Widget auf Mobile-Geräten testen
- [ ] Responsive Design funktioniert
- [ ] Touch-Interaktionen funktionieren
- [ ] Mobile-optimierte UI-Elemente

#### Widget-Button
- [ ] Button wird angezeigt
- [ ] Button-Icon wird korrekt angezeigt
- [ ] Custom Icon funktioniert
- [ ] Button-Animationen funktionieren
- [ ] Unread-Count Badge wird angezeigt
- [ ] Badge wird aktualisiert

#### Chat-Window
- [ ] Chat-Window öffnet/schließt korrekt
- [ ] Animationen funktionieren
- [ ] Header wird korrekt angezeigt
- [ ] Export-Button funktioniert
- [ ] Theme-Toggle funktioniert
- [ ] Close-Button funktioniert

#### Weitere UI-Features
- [ ] Loading-States werden angezeigt
- [ ] Error-States werden angezeigt
- [ ] Empty-States werden angezeigt
- [ ] Scroll-Verhalten funktioniert
- [ ] Auto-Scroll zu neuen Nachrichten

### Memories-Funktionalität

#### Memory-Erstellung
- [ ] Memory erstellen
- [ ] Memory bearbeiten
- [ ] Memory löschen
- [ ] Memory-Form funktioniert korrekt
- [ ] Validierung funktioniert

#### Memory-Verwaltung
- [ ] Memory-Liste wird angezeigt
- [ ] Suche funktioniert
- [ ] Filter funktionieren
- [ ] Memories werden korrekt sortiert

#### Memory-Integration
- [ ] Memories werden in Chat verwendet
- [ ] Memory-Referenzen funktionieren
- [ ] Memory-Kontext wird korrekt übergeben

### Backend/API-Tests

#### Widget-API
- [ ] `GET /api/v1/widgets` - Liste aller Widgets
- [ ] `POST /api/v1/widgets` - Widget erstellen
- [ ] `GET /api/v1/widgets/{id}` - Widget-Details
- [ ] `PUT /api/v1/widgets/{id}` - Widget aktualisieren
- [ ] `DELETE /api/v1/widgets/{id}` - Widget löschen
- [ ] `GET /api/v1/widget/{widgetId}/config` - Widget-Config (öffentlich)
- [ ] `POST /api/v1/widget/{widgetId}/message` - Nachricht senden (öffentlich)

#### Session-API
- [ ] Session wird erstellt
- [ ] Session wird gespeichert
- [ ] Session wird geladen
- [ ] Session-Historie funktioniert

#### File-Upload-API
- [ ] Datei hochladen
- [ ] Datei-Download
- [ ] Datei-Validierung
- [ ] Datei-Limits werden eingehalten

#### Prompt-API
- [ ] Prompts werden geladen
- [ ] Prompts werden verwendet
- [ ] Prompt-Konfiguration funktioniert

#### Memories-API
- [ ] Memories werden geladen
- [ ] Memories werden erstellt
- [ ] Memories werden aktualisiert
- [ ] Memories werden gelöscht

## Bekannte Probleme / Hinweise

- ⚠️ **npm Dependencies**: Nach Container-Neustart müssen npm-Dependencies möglicherweise erneut installiert werden (`docker compose exec frontend npm install`)
- ⚠️ **Database Schema**: Schema wurde aktualisiert - Fixtures wurden neu geladen
- ⚠️ **Widget Build**: Widget muss gebaut werden (`make -C frontend build-widget`)

## Setup-Befehle (bereits ausgeführt)

```bash
# Branch wechseln
git checkout rework/chat-widget-steps

# Dependencies installieren
docker compose exec frontend npm install

# Widget bauen
make -C frontend build-widget

# Database Schema aktualisieren
docker compose exec backend php bin/console doctrine:schema:update --force

# Fixtures laden
docker compose exec backend php bin/console doctrine:fixtures:load --no-interaction
```

## Statistik der Änderungen

- **71 Dateien geändert**
- **11.743 Zeilen hinzugefügt**
- **1.182 Zeilen entfernt**
- **Netto: +10.561 Zeilen**

### Wichtigste neue Dateien:
- `frontend/src/components/widgets/SimpleWidgetForm.vue`
- `frontend/src/components/widgets/AdvancedWidgetConfig.vue`
- `frontend/src/components/widgets/FilePicker.vue`
- `frontend/src/components/widgets/SetupChatModal.vue`
- `frontend/src/components/widgets/WidgetSuccessModal.vue`
- `frontend/src/composables/useMarkdown.ts`
- `frontend/src/composables/useMarkdownKatex.ts`
- `frontend/src/composables/useMarkdownMermaid.ts`
- `frontend/src/assets/markdown.css`
- `backend/src/Service/WidgetSetupService.php`

## Nächste Schritte

1. ✅ Setup abgeschlossen
2. ⏳ Systematisches Testen aller Features
3. ⏳ Bug-Reports dokumentieren
4. ⏳ Performance-Tests
5. ⏳ Cross-Browser-Tests
6. ⏳ Mobile-Tests

---

**Erstellt am:** 2026-01-28  
**Branch:** `rework/chat-widget-steps`  
**Basis:** `main`
