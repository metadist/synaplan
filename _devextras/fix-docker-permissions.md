# Docker Permission Issues - Lösungsanleitung

## Problem

Docker-Container laufen standardmäßig als `root` und erstellen Dateien mit root-Berechtigungen. Wenn du dann auf dem Host-System arbeitest (z.B. für **Playwright e2e-Tests**), bekommst du `EACCES: permission denied` Fehler.

**Besonders relevant für Playwright**, da e2e-Tests `node_modules` auf dem Host benötigen (Browser werden lokal installiert).

## Schnelle Lösung

```bash
# Berechtigungen korrigieren
sudo ./fix-permissions.sh

# Oder manuell:
sudo chown -R $USER:$USER frontend/node_modules
sudo chown -R $USER:$USER backend/vendor
```

## Präventive Lösung

### ✅ RICHTIG: Immer Docker-Container verwenden

**Für normale Entwicklung:**
```bash
# Dependencies installieren (läuft im Container)
make -C frontend deps

# Build (läuft im Container)
make -C frontend build

# Tests (läuft im Container)
make -C frontend test
```

**Für Playwright e2e-Tests (benötigt node_modules auf Host):**
```bash
# 1. Dependencies auf Host installieren
cd frontend
make -C frontend deps-host  # Installiert auf Host

# 2. Falls Berechtigungsprobleme auftreten:
cd ..
sudo ./fix-permissions.sh

# 3. Playwright Browser installieren
cd frontend
npx playwright install --with-deps

# 4. Tests ausführen
npm run test:e2e
```

**Wichtig:** Wenn Docker-Container laufen und `node_modules` bereits existiert (von Container erstellt), musst du die Berechtigungen korrigieren:
```bash
sudo ./fix-permissions.sh
```

### ❌ FALSCH: Direkt auf Host installieren

```bash
# NICHT machen:
cd frontend
npm install  # ❌ Erstellt root-owned files wenn Container läuft
```

## Warum passiert das?

1. Docker-Container laufen als `root` (Standard)
2. Wenn Container `node_modules` erstellen, gehören sie `root:root`
3. Dein Benutzer (`furkan`) kann dann nicht schreiben

## Warum hatte ich das Problem vorher nicht?

Es gibt mehrere mögliche Gründe:

1. **Container waren gestoppt:** Wenn du vorher `npm install` auf dem Host gemacht hast, während die Container gestoppt waren, gab es keine Konflikte.

2. **`node_modules` wurde gelöscht:** Wenn du vorher `rm -rf node_modules` gemacht hast, bevor Container sie erstellt haben, gab es keine root-owned Dateien.

3. **Du hast `make -C frontend deps-host` verwendet:** Das macht `npm ci` statt `npm install`, was möglicherweise anders mit Berechtigungen umgeht.

4. **Docker-Konfiguration hat sich geändert:** Das anonyme Volume (`- /app/node_modules`) wurde hinzugefügt, um Host/Container-Konflikte zu vermeiden. Aber wenn Container laufen und `node_modules` im Container existiert, kann es trotzdem zu Problemen kommen, wenn du auf dem Host installierst.

5. **Du hast vorher nicht versucht, auf Host zu installieren, während Container liefen:** Das ist der häufigste Grund - du hast einfach nicht gleichzeitig Container laufen gehabt und auf dem Host installiert.

**Das Problem tritt auf, wenn:**
- Docker-Container laufen (erstellen `node_modules` als root)
- Du versuchst gleichzeitig auf dem Host `npm install` zu machen
- Das anonyme Volume funktioniert nicht perfekt oder es gibt Race Conditions

## Dauerhafte Lösung

### Option 1: Script nach Docker-Operationen ausführen

Füge zu deiner `~/.bashrc` oder `~/.zshrc` hinzu:

```bash
# Auto-fix permissions after docker compose commands
docker() {
    command docker "$@"
    if [[ "$1" == "compose" ]] && [[ "$2" =~ ^(up|restart|exec)$ ]]; then
        if [ -f "./fix-permissions.sh" ]; then
            echo "🔧 Auto-fixing permissions..."
            sudo ./fix-permissions.sh 2>/dev/null || true
        fi
    fi
}
```

### Option 2: Docker mit deinem Benutzer laufen lassen

Du könntest Docker-Container mit deinem Benutzer laufen lassen, aber das ist komplexer und wird nicht empfohlen, da es andere Probleme verursachen kann.

### Option 3: node_modules immer im Container lassen

Das Projekt verwendet bereits anonyme Volumes (`- /app/node_modules`) um zu verhindern, dass Host-node_modules gemountet werden. Das funktioniert gut, solange du nicht direkt auf dem Host installierst.

## Zusammenfassung

**Golden Rules:** 
- ✅ **Normale Entwicklung:** Verwende `make -C frontend deps` (läuft im Container)
- ✅ **Playwright e2e-Tests:** 
  1. `make -C frontend deps-host` (installiert auf Host)
  2. `sudo ./fix-permissions.sh` (falls Container node_modules erstellt hat)
  3. `npx playwright install --with-deps` (Browser installieren)
  4. `npm run test:e2e` (Tests ausführen)
- ✅ **Nach Docker-Operationen:** Führe `sudo ./fix-permissions.sh` aus, wenn du auf dem Host arbeitest
- ❌ **NICHT:** `npm install` direkt auf Host ausführen, wenn Container node_modules bereits erstellt haben (ohne vorherige Berechtigungskorrektur)
