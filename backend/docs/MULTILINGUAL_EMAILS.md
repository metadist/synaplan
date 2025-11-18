# Multilingual Email System

## Übersicht

Das Email-System nutzt Symfony Translation Tags für mehrsprachige Emails. Alle Texte werden zentral in YAML-Dateien verwaltet.

## Struktur

```
backend/
├── templates/emails/
│   ├── verification.html.twig         # Multilingual template
│   ├── password-reset.html.twig       # Multilingual template
│   └── welcome.html.twig               # Multilingual template
├── translations/
│   ├── emails.en.yaml                  # English translations
│   ├── emails.de.yaml                  # German translations
│   └── emails.fr.yaml                  # French translations (optional)
└── src/Service/
    └── EmailService.php                # Email service with locale support
```

## Verwendung

### 1. Email Service injizieren

```php
use App\Service\EmailService;

public function __construct(
    private EmailService $emailService
) {}
```

### 2. Emails versenden

#### Verification Email

```php
$this->emailService->sendVerificationEmail(
    to: 'user@example.com',
    verificationUrl: 'https://app.synaplan.com/verify/abc123',
    locale: 'de'  // 'en', 'de', 'fr', etc.
);
```

#### Password Reset Email

```php
$this->emailService->sendPasswordResetEmail(
    to: 'user@example.com',
    resetUrl: 'https://app.synaplan.com/reset/xyz789',
    locale: 'en'
);
```

#### Welcome Email

```php
$this->emailService->sendWelcomeEmail(
    to: 'user@example.com',
    name: 'John Doe',
    locale: 'de',
    appUrl: 'https://app.synaplan.com'
);
```

#### Eigene Email mit Template

```php
$this->emailService->sendTemplatedEmail(
    to: 'user@example.com',
    subject: 'Custom Subject',
    template: 'emails/my-template.html.twig',
    context: ['custom_var' => 'value'],
    locale: 'de'
);
```

## Neue Sprache hinzufügen

### 1. Translation-Datei erstellen

Erstelle `backend/translations/emails.fr.yaml`:

```yaml
email:
  password_reset:
    title: 'Réinitialiser votre mot de passe'
    intro: 'Vous avez demandé la réinitialisation...'
    # ... weitere Übersetzungen
```

### 2. Nutzen

```php
$this->emailService->sendVerificationEmail(
    to: 'user@example.com',
    verificationUrl: 'https://...',
    locale: 'fr'
);
```

## Eigene Email-Templates erstellen

### 1. Template erstellen

`backend/templates/emails/custom.html.twig`:

```twig
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        /* Styles hier */
    </style>
</head>
<body>
    <div class="container">
        <h2>{{ 'email.custom.title'|trans }}</h2>
        <p>{{ 'email.custom.message'|trans({'%name%': name}) }}</p>
        <!-- Weitere Inhalte -->
    </div>
</body>
</html>
```

### 2. Übersetzungen hinzufügen

In `translations/emails.en.yaml` und `emails.de.yaml`:

```yaml
email:
  custom:
    title: 'Custom Title'
    message: 'Hello %name%, custom message here'
```

### 3. Versenden

```php
$this->emailService->sendTemplatedEmail(
    to: 'user@example.com',
    subject: $this->translator->trans('email.custom.title', [], 'emails', 'de'),
    template: 'emails/custom.html.twig',
    context: ['name' => 'John'],
    locale: 'de'
);
```

## User-Sprache ermitteln

### Aus User-Entity

```php
$locale = $user->getLocale() ?? 'en';

$this->emailService->sendVerificationEmail(
    to: $user->getEmail(),
    verificationUrl: $url,
    locale: $locale
);
```

### Aus Request

```php
$locale = $request->getLocale();
```

### Standard-Fallback

```php
$locale = $user->getLocale() ?? $request->getLocale() ?? 'en';
```

## Translation Keys Struktur

Alle Email-Translations folgen diesem Schema:

```
email.
  ├── password_reset.
  │   ├── title
  │   ├── intro
  │   ├── instruction
  │   ├── button
  │   └── security_notice.
  │       ├── title
  │       └── text
  ├── verification.
  │   ├── title
  │   ├── intro
  │   └── ...
  ├── welcome.
  │   ├── title
  │   ├── greeting
  │   └── ...
  └── footer.
      ├── support
      ├── help
      ├── company
      └── rights
```

## Best Practices

### 1. Immer Locale übergeben

```php
// ✅ Gut
$emailService->sendVerificationEmail($email, $url, $user->getLocale());

// ❌ Schlecht - nutzt immer 'en'
$emailService->sendVerificationEmail($email, $url);
```

### 2. Parameter in Translations nutzen

```yaml
# ✅ Gut - flexibel
greeting: 'Hello %name%, welcome!'

# ❌ Schlecht - statisch
greeting: 'Hello, welcome!'
```

### 3. Gemeinsame Footer-Texte wiederverwenden

```twig
<p>{{ 'email.footer.company'|trans }}. {{ 'email.footer.rights'|trans }}</p>
```

### 4. Logging aktivieren

Der EmailService loggt automatisch:
- ✅ Erfolgreiche Versendungen
- ❌ Fehler mit Details

## Testing

### Email in Tests

```php
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class EmailServiceTest extends KernelTestCase
{
    public function testSendVerificationEmailInGerman(): void
    {
        $emailService = static::getContainer()->get(EmailService::class);
        
        $emailService->sendVerificationEmail(
            to: 'test@example.com',
            verificationUrl: 'https://test.com/verify/123',
            locale: 'de'
        );
        
        // Assertions hier
    }
}
```

### Translations testen

```php
$translator = static::getContainer()->get(TranslatorInterface::class);

$germanTitle = $translator->trans('email.verification.title', [], 'emails', 'de');
$this->assertEquals('E-Mail-Adresse bestätigen', $germanTitle);
```

## Unterstützte Sprachen

- ✅ **en** - English (default)
- ✅ **de** - Deutsch
- ⏳ **fr** - Français (kann hinzugefügt werden)
- ⏳ **es** - Español (kann hinzugefügt werden)

## Troubleshooting

### Translations nicht gefunden

```bash
# Cache leeren
php bin/console cache:clear

# Translations debuggen
php bin/console debug:translation de emails
```

### Email wird in falscher Sprache gesendet

1. Überprüfe, ob die locale korrekt übergeben wird
2. Prüfe, ob die Translation-Datei existiert
3. Prüfe die Email-Logs in `var/log/dev.log`

### Neue Translation-Keys hinzufügen

1. In `translations/emails.en.yaml` hinzufügen
2. In `translations/emails.de.yaml` hinzufügen
3. Im Template nutzen: `{{ 'email.new.key'|trans }}`
4. Cache leeren: `php bin/console cache:clear`

