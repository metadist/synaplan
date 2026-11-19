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
        $sink = self::sink($channel);

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

    /**
     * Several entries written in one step: one sentence, then a line per
     * entry with its day and its times, so the person can check each one.
     *
     * @param list<array{title: string, start: \DateTimeImmutable, end: \DateTimeImmutable}> $entries
     */
    public static function confirmedSeveral(string $language, array $entries, string $channel): string
    {
        $lang = UserUtteranceLanguage::normalize($language);
        $count = count($entries);
        $sink = self::sink($channel);

        $header = match ($lang) {
            'de' => match ($sink) {
                'outlook' => sprintf('Ich habe %d Kalendereinträge in Outlook hinzugefügt:', $count),
                'calendar' => sprintf('Ich habe %d Kalendereinträge in deinen Kalender hinzugefügt:', $count),
                default => sprintf('Ich habe %d Kalendereinträge erstellt:', $count),
            },
            'es' => match ($sink) {
                'outlook' => sprintf('He añadido %d eventos de calendario en Outlook:', $count),
                'calendar' => sprintf('He añadido %d eventos de calendario en tu calendario:', $count),
                default => sprintf('He creado %d eventos de calendario:', $count),
            },
            'fr' => match ($sink) {
                'outlook' => sprintf('J’ai ajouté %d événements de calendrier dans Outlook :', $count),
                'calendar' => sprintf('J’ai ajouté %d événements de calendrier dans votre calendrier :', $count),
                default => sprintf('J’ai créé %d événements de calendrier :', $count),
            },
            'tr' => match ($sink) {
                'outlook' => sprintf('Outlook’a %d takvim kaydı ekledim:', $count),
                'calendar' => sprintf('Takviminize %d takvim kaydı ekledim:', $count),
                default => sprintf('%d takvim kaydı oluşturdum:', $count),
            },
            // Without a calendar the entries exist as a file only: "created", not "added".
            default => match ($sink) {
                'outlook' => sprintf('I added %d calendar events to Outlook:', $count),
                'calendar' => sprintf('I added %d calendar events to your calendar:', $count),
                default => sprintf('I created %d calendar events:', $count),
            },
        };

        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = '- '.self::entryLine($lang, $entry['title'], $entry['start'], $entry['end']);
        }

        return $header."\n\n".implode("\n", $lines);
    }

    /**
     * The step read something, and no appointment with a date and a time was
     * in it. Asks for the one thing that is missing instead of guessing.
     */
    public static function nothingToEnter(string $language): string
    {
        return match (UserUtteranceLanguage::normalize($language)) {
            'de' => 'Ich habe in dem, was ich gelesen habe, keinen Termin mit Datum und Uhrzeit gefunden. Um welchen Termin geht es, und wann ist er?',
            'es' => 'No he encontrado ninguna cita con fecha y hora en lo que he leído. ¿De qué cita se trata y cuándo es?',
            'fr' => 'Je n’ai trouvé aucun rendez-vous avec une date et une heure dans ce que j’ai lu. De quel rendez-vous s’agit-il, et quand a-t-il lieu ?',
            'tr' => 'Okuduklarımda tarihi ve saati olan bir randevu bulamadım. Hangi randevu ve ne zaman?',
            default => 'I found no appointment with a date and a time in what I read. Which appointment is it, and when?',
        };
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

    public static function notDelivered(string $language, bool $several = false): string
    {
        if ($several) {
            return match (UserUtteranceLanguage::normalize($language)) {
                'de' => 'Sie wurden nicht in einen Kalender eingetragen — die .ics-Datei ist angehängt. Verbinde einen Kalender unter Apps → Microsoft 365.',
                'es' => 'No se añadieron a un calendario: el archivo .ics está adjunto. Conecta un calendario en Apps → Microsoft 365.',
                'fr' => 'Ils n’ont pas été ajoutés à un calendrier — le fichier .ics est joint. Connectez un calendrier sous Apps → Microsoft 365.',
                'tr' => 'Bir takvime eklenmediler — .ics dosyası ekte. Apps → Microsoft 365 altında bir takvim bağlayın.',
                default => 'They were not added to a calendar — the .ics is attached. Connect a calendar under Apps → Microsoft 365.',
            };
        }

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
     * A calendar step keeps a start the person named. A start they did not
     * name is taken off the step when it can read its entries instead: from a
     * step it depends on, or from a file of this conversation. The plan
     * becomes the question about the time only when there is nothing to read.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function adjustPlan(array $payload, string $messageText, ?CalendarTurnEvidence $evidence = null): array
    {
        $language = UserUtteranceLanguage::detect($messageText, is_string($payload['language'] ?? null) ? $payload['language'] : 'en');
        $payload['language'] = $language;

        $tasks = $payload['tasks'] ?? null;
        if (!is_array($tasks)) {
            return $payload;
        }

        $calendarIds = self::calendarIds($tasks);
        if ([] === $calendarIds) {
            return $payload;
        }

        $evidence ??= new CalendarTurnEvidence($messageText);
        foreach ($tasks as $i => $task) {
            if (!self::isCalendarEvent($task)) {
                continue;
            }
            $start = self::plannedStart($task);
            if ('' !== $start && $evidence->backsStart($start)) {
                continue;
            }
            if (self::dependsOnAStep($task) || $evidence->hasFileText()) {
                $tasks[$i] = self::withoutPlannedTimes($task);
                continue;
            }
            if ('' !== $start || !UserNamedClockTime::in($messageText)) {
                return self::askForTimePlan($payload, $language);
            }
        }

        $replyText = implode("\n\n", array_map(static fn (string $id): string => '$'.$id.'.text', self::confirmingIds($tasks)));
        foreach ($tasks as $i => $task) {
            if (!is_array($task) || ($task['capability'] ?? '') !== 'compose_reply') {
                continue;
            }
            $inputs = is_array($task['inputs'] ?? null) ? $task['inputs'] : [];
            $inputs['text'] = $replyText;
            $tasks[$i]['inputs'] = $inputs;
        }
        $payload['tasks'] = $tasks;

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function askForTimePlan(array $payload, string $language): array
    {
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

    /**
     * @param list<mixed> $tasks
     *
     * @return list<string>
     */
    private static function calendarIds(array $tasks): array
    {
        $ids = [];
        foreach ($tasks as $task) {
            if (self::isCalendarEvent($task) && is_string($task['id'] ?? null)) {
                $ids[] = $task['id'];
            }
        }

        return $ids;
    }

    /**
     * The calendar steps whose sentence the reply shows. A step with a start
     * confirms its own event. Steps that read confirm everything they read,
     * so of several that read from the same steps only the first speaks: the
     * runner enters a text once, and the others return nothing.
     *
     * @param list<mixed> $tasks
     *
     * @return list<string>
     */
    private static function confirmingIds(array $tasks): array
    {
        $ids = [];
        $readFrom = [];
        foreach ($tasks as $task) {
            if (!self::isCalendarEvent($task) || !is_string($task['id'] ?? null)) {
                continue;
            }
            if ('' === self::plannedStart($task)) {
                $dependsOn = is_array($task['depends_on'] ?? null) ? array_filter($task['depends_on'], 'is_string') : [];
                sort($dependsOn);
                $key = implode(',', $dependsOn);
                if (isset($readFrom[$key])) {
                    continue;
                }
                $readFrom[$key] = true;
            }
            $ids[] = $task['id'];
        }

        return $ids;
    }

    /**
     * @phpstan-assert-if-true array<string, mixed> $task
     */
    private static function isCalendarEvent(mixed $task): bool
    {
        return is_array($task) && 'calendar_event' === ($task['capability'] ?? '');
    }

    /**
     * The planner writes the event fields under `params` or under `inputs`.
     *
     * @param array<string, mixed> $task
     */
    private static function plannedStart(array $task): string
    {
        foreach (['params', 'inputs'] as $bag) {
            $start = is_array($task[$bag] ?? null) ? ($task[$bag]['start'] ?? null) : null;
            if (is_string($start) && '' !== trim($start)) {
                return trim($start);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $task
     */
    private static function dependsOnAStep(array $task): bool
    {
        return is_array($task['depends_on'] ?? null) && [] !== $task['depends_on'];
    }

    /**
     * @param array<string, mixed> $task
     *
     * @return array<string, mixed>
     */
    private static function withoutPlannedTimes(array $task): array
    {
        foreach (['params', 'inputs'] as $bag) {
            if (is_array($task[$bag] ?? null)) {
                unset($task[$bag]['start'], $task[$bag]['end']);
            }
        }

        return $task;
    }

    /**
     * Date, times, then the title: the same order in every language. Always
     * the date, never "tomorrow": the person checks the list against the
     * booking it was read from.
     */
    private static function entryLine(string $language, string $title, \DateTimeImmutable $start, \DateTimeImmutable $end): string
    {
        $until = $end->format('Y-m-d') === $start->format('Y-m-d')
            ? $end->format('H:i')
            : self::dateLabel($language, $end).' '.$end->format('H:i');
        $times = $start->format('H:i').'–'.$until.('de' === $language ? ' Uhr' : '');
        $separator = 'fr' === $language ? ' : ' : ': ';

        return self::dateLabel($language, $start).', '.$times.$separator.trim($title);
    }

    private static function dateLabel(string $language, \DateTimeImmutable $date): string
    {
        return 'de' === $language ? $date->format('d.m.Y') : $date->format('Y-m-d');
    }

    private static function sink(string $channel): string
    {
        if ('' === $channel) {
            return '';
        }

        return str_contains(strtolower($channel), 'outlook') ? 'outlook' : 'calendar';
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

        return self::dateLabel($language, $start);
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
