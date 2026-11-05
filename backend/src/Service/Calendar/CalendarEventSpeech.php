<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Service\Prompt\UserUtteranceLanguage;

/**
 * User-facing calendar lines: ask when a clock time is missing, confirm what
 * was written, and keep both in the language of the latest user message.
 */
final class CalendarEventSpeech
{
    public static function askForTime(string $language): string
    {
        return match (UserUtteranceLanguage::normalize($language)) {
            'de' => 'Um welche Uhrzeit soll der Termin liegen? Eine konkrete Uhrzeit fehlt noch, zum Beispiel „morgen um 15 Uhr“.',
            'es' => '¿A qué hora debe ser el evento? Todavía falta una hora concreta, por ejemplo «mañana a las 15:00».',
            'fr' => 'À quelle heure doit avoir lieu le rendez-vous ? Il manque encore une heure précise, par exemple « demain à 15h ».',
            'tr' => 'Randevu saat kaçta olsun? Henüz net bir saat yok, örneğin “yarın 15:00”.',
            default => 'What time should the event be? I still need a clock time, for example “tomorrow at 3pm”.',
        };
    }

    public static function confirmed(
        string $language,
        \DateTimeImmutable $start,
        int $durationMinutes,
        string $channel,
        ?string $webLink,
        string $title = '',
    ): string {
        $lang = UserUtteranceLanguage::normalize($language);
        $when = self::whenLabel($lang, $start);
        $time = $start->format('H:i');
        $duration = self::durationLabel($lang, $durationMinutes);
        $named = '' !== trim($title) && 'Meeting' !== trim($title) ? trim($title) : '';
        $sink = '';
        if ('' !== $channel) {
            $sink = str_contains(strtolower($channel), 'outlook') ? 'outlook' : 'calendar';
        }

        $text = match ($lang) {
            'de' => match ($sink) {
                'outlook' => sprintf('Ich habe einen Kalendereintrag%s für %s ab %s Uhr für %s in Outlook hinzugefügt.', self::quotedTitle($lang, $named), $when, $time, $duration),
                'calendar' => sprintf('Ich habe einen Kalendereintrag%s für %s ab %s Uhr für %s in deinen Kalender hinzugefügt.', self::quotedTitle($lang, $named), $when, $time, $duration),
                default => sprintf('Ich habe einen Kalendereintrag%s für %s ab %s Uhr für %s hinzugefügt.', self::quotedTitle($lang, $named), $when, $time, $duration),
            },
            'es' => match ($sink) {
                'outlook' => sprintf('He añadido un evento de calendario%s para %s a las %s durante %s en Outlook.', self::quotedTitle($lang, $named), $when, $time, $duration),
                'calendar' => sprintf('He añadido un evento de calendario%s para %s a las %s durante %s en tu calendario.', self::quotedTitle($lang, $named), $when, $time, $duration),
                default => sprintf('He añadido un evento de calendario%s para %s a las %s durante %s.', self::quotedTitle($lang, $named), $when, $time, $duration),
            },
            'fr' => match ($sink) {
                'outlook' => sprintf('J’ai ajouté un événement de calendrier%s pour %s à %s pendant %s dans Outlook.', self::quotedTitle($lang, $named), $when, $time, $duration),
                'calendar' => sprintf('J’ai ajouté un événement de calendrier%s pour %s à %s pendant %s dans votre calendrier.', self::quotedTitle($lang, $named), $when, $time, $duration),
                default => sprintf('J’ai ajouté un événement de calendrier%s pour %s à %s pendant %s.', self::quotedTitle($lang, $named), $when, $time, $duration),
            },
            'tr' => match ($sink) {
                'outlook' => sprintf('%s saat %s için %s süren%s bir takvim kaydını Outlook’a ekledim.', $when, $time, $duration, self::quotedTitle($lang, $named)),
                'calendar' => sprintf('%s saat %s için %s süren%s bir takvim kaydını takviminize ekledim.', $when, $time, $duration, self::quotedTitle($lang, $named)),
                default => sprintf('%s saat %s için %s süren%s bir takvim kaydı ekledim.', $when, $time, $duration, self::quotedTitle($lang, $named)),
            },
            default => match ($sink) {
                'outlook' => sprintf('I added a calendar event%s for %s at %s for %s to Outlook.', self::quotedTitle($lang, $named), $when, $time, $duration),
                'calendar' => sprintf('I added a calendar event%s for %s at %s for %s to your calendar.', self::quotedTitle($lang, $named), $when, $time, $duration),
                default => sprintf('I added a calendar event%s for %s at %s for %s.', self::quotedTitle($lang, $named), $when, $time, $duration),
            },
        };

        if (null !== $webLink && '' !== $webLink) {
            $text .= ' '.$webLink;
        }

        return $text;
    }

    public static function unknownTimezone(string $language, string $title): string
    {
        return match (UserUtteranceLanguage::normalize($language)) {
            'de' => sprintf('Ich habe „%s“ nicht erstellt: Deine Zeitzone ist unbekannt, daher kann ich die gewünschte Zeit nicht einordnen. Stelle deine Zeitzone im Profil ein oder nenne eine in deiner Nachricht (z. B. „morgen um 10, Europe/Berlin“).', $title),
            'es' => sprintf('No he creado «%s»: tu zona horaria es desconocida, así que no puedo situar la hora solicitada. Configura tu zona horaria en tu perfil o indícala en tu mensaje (p. ej., «mañana a las 10, Europe/Berlin»).', $title),
            'fr' => sprintf('Je n’ai pas créé « %s » : votre fuseau horaire est inconnu, je ne peux donc pas situer l’heure demandée. Définissez votre fuseau horaire dans votre profil ou indiquez-en un dans votre message (par ex. « demain à 10h, Europe/Berlin »).', $title),
            'tr' => sprintf('„%s“ oluşturulmadı: saat diliminiz bilinmiyor, bu yüzden istenen saati yerleştiremiyorum. Saat diliminizi profilinizde ayarlayın ya da mesajınızda belirtin (örn. “yarın 10’da, Europe/Berlin”).', $title),
            default => sprintf('I did not create "%s": your timezone is unknown, so I cannot place the requested time. Set your timezone in your profile, or name one in your message (e.g. "tomorrow at 10, Europe/Berlin").', $title),
        };
    }

    public static function notDelivered(string $language): string
    {
        return match (UserUtteranceLanguage::normalize($language)) {
            'de' => 'Der Termin wurde nicht in einen Kalender eingetragen — die .ics-Datei ist angehängt. Verbinde einen Kalender unter Apps → Microsoft 365.',
            'es' => 'El evento no se añadió a un calendario: el archivo .ics está adjunto. Conecta un calendario en Apps → Microsoft 365.',
            'fr' => 'L’événement n’a pas été ajouté à un calendrier — le fichier .ics est joint. Connectez un calendrier sous Apps → Microsoft 365.',
            'tr' => 'Etkinlik bir takvime eklenmedi — .ics dosyası ekte. Apps → Microsoft 365 altında bir takvim bağlayın.',
            default => 'The event was not added to a calendar — the .ics is attached. Connect a calendar under Apps → Microsoft 365.',
        };
    }

    /**
     * Stop the planner inventing 09:00, and keep the visible reply as the
     * runner's sentence (`$nX.text`) in the user's language.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function adjustPlan(array $payload, string $messageText): array
    {
        $language = UserUtteranceLanguage::detect($messageText, is_string($payload['language'] ?? null) ? $payload['language'] : 'en');
        $payload['language'] = $language;

        $tasks = $payload['tasks'] ?? null;
        if (!is_array($tasks)) {
            return $payload;
        }

        if (!UserNamedClockTime::in($messageText) && self::hasCalendarEvent($tasks)) {
            $payload['tasks'] = [[
                'id' => 'n1',
                'capability' => 'compose_reply',
                'depends_on' => [],
                'inputs' => ['text' => self::askForTime($language)],
                'params' => [],
            ]];
            $payload['reply_node'] = 'n1';

            return $payload;
        }

        $calendarId = self::firstCalendarId($tasks);
        if (null === $calendarId) {
            return $payload;
        }

        foreach ($tasks as $i => $task) {
            if (!is_array($task) || ($task['capability'] ?? '') !== 'compose_reply') {
                continue;
            }
            $inputs = is_array($task['inputs'] ?? null) ? $task['inputs'] : [];
            $inputs['text'] = '$'.$calendarId.'.text';
            $tasks[$i]['inputs'] = $inputs;
        }
        $payload['tasks'] = $tasks;

        return $payload;
    }

    /**
     * @param list<mixed> $tasks
     */
    private static function hasCalendarEvent(array $tasks): bool
    {
        return null !== self::firstCalendarId($tasks);
    }

    /**
     * @param list<mixed> $tasks
     */
    private static function firstCalendarId(array $tasks): ?string
    {
        foreach ($tasks as $task) {
            if (is_array($task) && ($task['capability'] ?? '') === 'calendar_event' && is_string($task['id'] ?? null)) {
                return $task['id'];
            }
        }

        return null;
    }

    private static function quotedTitle(string $language, string $title): string
    {
        if ('' === $title) {
            return '';
        }

        return match ($language) {
            'de' => sprintf(' „%s“', $title),
            'es', 'fr' => sprintf(' « %s »', $title),
            'tr' => sprintf(' “%s”', $title),
            default => sprintf(' "%s"', $title),
        };
    }

    private static function whenLabel(string $language, \DateTimeImmutable $start): string
    {
        $tz = $start->getTimezone();
        $today = new \DateTimeImmutable('now', $tz);
        $day = $start->format('Y-m-d');
        if ($day === $today->format('Y-m-d')) {
            return match ($language) {
                'de' => 'heute',
                'es' => 'hoy',
                'fr' => 'aujourd’hui',
                'tr' => 'bugün',
                default => 'today',
            };
        }
        if ($day === $today->modify('+1 day')->format('Y-m-d')) {
            return match ($language) {
                'de' => 'morgen',
                'es' => 'mañana',
                'fr' => 'demain',
                'tr' => 'yarın',
                default => 'tomorrow',
            };
        }

        return 'de' === $language ? $start->format('d.m.Y') : $start->format('Y-m-d');
    }

    private static function durationLabel(string $language, int $minutes): string
    {
        $minutes = max(1, $minutes);
        if (60 === $minutes) {
            return match ($language) {
                'de' => 'eine Stunde',
                'es' => 'una hora',
                'fr' => 'une heure',
                'tr' => 'bir saat',
                default => 'one hour',
            };
        }
        if (120 === $minutes) {
            return match ($language) {
                'de' => 'zwei Stunden',
                'es' => 'dos horas',
                'fr' => 'deux heures',
                'tr' => 'iki saat',
                default => 'two hours',
            };
        }

        return match ($language) {
            'de' => $minutes.' Minuten',
            'es' => $minutes.' minutos',
            'fr' => $minutes.' minutes',
            'tr' => $minutes.' dakika',
            default => $minutes.' minutes',
        };
    }
}
