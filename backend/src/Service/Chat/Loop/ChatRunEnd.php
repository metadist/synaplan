<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * Why a chat run ended, and the one true sentence the chat shows for it when
 * the run has no answer of its own to deliver.
 */
enum ChatRunEnd: string
{
    private const SENTENCES = [
        'no_progress' => [
            'en' => 'I stopped because I was not getting closer to an answer.',
            'de' => 'Ich habe aufgehört, weil ich einer Antwort nicht näher kam.',
            'es' => 'Me detuve porque no me acercaba a una respuesta.',
            'fr' => 'Je me suis arrêté, car je ne me rapprochais pas d\'une réponse.',
            'tr' => 'Bir cevaba yaklaşamadığım için durdum.',
        ],
        'finish_fallback' => [
            'en' => 'I could not write the final summary, so this is the last answer I had.',
            'de' => 'Ich konnte die Zusammenfassung nicht schreiben; das ist meine letzte Antwort.',
            'es' => 'No pude escribir el resumen final; esta es la última respuesta que tenía.',
            'fr' => 'Je n\'ai pas pu écrire le résumé final ; voici ma dernière réponse.',
            'tr' => 'Son özeti yazamadım; elimdeki son cevap bu.',
        ],
        'stopped_by_user' => [
            'en' => 'Stopped. Nothing more was done after you stopped it.',
            'de' => 'Gestoppt. Nach dem Stopp wurde nichts mehr getan.',
            'es' => 'Detenido. No se hizo nada más después de detenerlo.',
            'fr' => 'Arrêté. Rien de plus n\'a été fait après l\'arrêt.',
            'tr' => 'Durduruldu. Durdurduktan sonra başka bir şey yapılmadı.',
        ],
        'loop_off' => [
            'en' => 'Stopped: an administrator turned off long chat runs.',
            'de' => 'Gestoppt: Ein Administrator hat lange Chat-Läufe ausgeschaltet.',
            'es' => 'Detenido: un administrador desactivó las ejecuciones largas del chat.',
            'fr' => 'Arrêté : un administrateur a désactivé les exécutions longues du chat.',
            'tr' => 'Durduruldu: bir yönetici uzun sohbet çalıştırmalarını kapattı.',
        ],
        'budget_used' => [
            'en' => 'I stopped because the monthly budget is used up.',
            'de' => 'Ich habe aufgehört, weil das Monatsbudget aufgebraucht ist.',
            'es' => 'Me detuve porque el presupuesto mensual se ha agotado.',
            'fr' => 'Je me suis arrêté, car le budget mensuel est épuisé.',
            'tr' => 'Aylık bütçe tükendiği için durdum.',
        ],
        'account_gone' => [
            'en' => 'Interrupted: this account can no longer use the chat.',
            'de' => 'Unterbrochen: Dieses Konto kann den Chat nicht mehr nutzen.',
            'es' => 'Interrumpido: esta cuenta ya no puede usar el chat.',
            'fr' => 'Interrompu : ce compte ne peut plus utiliser le chat.',
            'tr' => 'Kesildi: bu hesap artık sohbeti kullanamıyor.',
        ],
        'chat_gone' => [
            'en' => 'Interrupted: this chat no longer exists.',
            'de' => 'Unterbrochen: Diesen Chat gibt es nicht mehr.',
            'es' => 'Interrumpido: este chat ya no existe.',
            'fr' => 'Interrompu : ce chat n\'existe plus.',
            'tr' => 'Kesildi: bu sohbet artık yok.',
        ],
        'model_failed' => [
            'en' => 'The AI model did not answer, so I could not finish. Please send your message again.',
            'de' => 'Das KI-Modell hat nicht geantwortet, deshalb konnte ich nicht fertig werden. Bitte sende deine Nachricht noch einmal.',
            'es' => 'El modelo de IA no respondió, así que no pude terminar. Envía tu mensaje de nuevo.',
            'fr' => 'Le modèle d\'IA n\'a pas répondu, je n\'ai donc pas pu terminer. Renvoyez votre message.',
            'tr' => 'Yapay zekâ modeli yanıt vermedi, bu yüzden bitiremedim. Lütfen mesajınızı yeniden gönderin.',
        ],
        'worker_lost' => [
            'en' => 'Interrupted: the work stopped on the server before it was done. Please send your message again.',
            'de' => 'Unterbrochen: Die Arbeit brach auf dem Server ab, bevor sie fertig war. Bitte sende deine Nachricht noch einmal.',
            'es' => 'Interrumpido: el trabajo se detuvo en el servidor antes de terminar. Envía tu mensaje de nuevo.',
            'fr' => 'Interrompu : le travail s\'est arrêté sur le serveur avant la fin. Renvoyez votre message.',
            'tr' => 'Kesildi: iş bitmeden sunucuda durdu. Lütfen mesajınızı yeniden gönderin.',
        ],
    ];

    case Answered = 'answered';
    case Finished = 'finished';
    case NoProgress = 'no_progress';
    case FinishFallback = 'finish_fallback';
    case StoppedByUser = 'stopped_by_user';
    case LoopOff = 'loop_off';
    case BudgetUsed = 'budget_used';
    case AccountGone = 'account_gone';
    case ChatGone = 'chat_gone';
    case WorkerLost = 'worker_lost';
    case ModelFailed = 'model_failed';

    public function status(): ChatRunStatus
    {
        return match ($this) {
            self::Answered, self::Finished, self::NoProgress, self::FinishFallback => ChatRunStatus::Completed,
            self::StoppedByUser, self::LoopOff => ChatRunStatus::Stopped,
            self::BudgetUsed => ChatRunStatus::BudgetUsed,
            self::AccountGone, self::ChatGone, self::WorkerLost => ChatRunStatus::Interrupted,
            self::ModelFailed => ChatRunStatus::Failed,
        };
    }

    /** The sentence posted when the run ends without an answer, in the person's language. */
    public function sentence(string $locale): string
    {
        $lang = strtolower(substr($locale, 0, 2));
        $texts = self::SENTENCES[$this->value] ?? null;
        if (null === $texts) {
            return '';
        }

        return $texts[$lang] ?? $texts['en'];
    }
}
