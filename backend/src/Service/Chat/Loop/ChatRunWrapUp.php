<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * The wrap-up of a run that has to stop before it is done (03 §1.3): one
 * last call without tools writes the result from what the run has. The
 * answer starts with a fixed line that says why, written here and not by
 * the model, so the reason is always true and always there.
 */
final class ChatRunWrapUp
{
    /** @var list<ChatRunEnd> */
    public const REASONS = [ChatRunEnd::CostLimit, ChatRunEnd::PayerEmpty, ChatRunEnd::Circling];

    private const OPENINGS = [
        'cost_limit' => [
            'en' => 'I reached the cost limit you set for one answer, so here is what I have so far.',
            'de' => 'Ich habe das Kostenlimit erreicht, das du für eine Antwort festgelegt hast. Hier ist, was ich bisher habe.',
            'es' => 'He llegado al límite de coste que fijaste para una respuesta. Esto es lo que tengo hasta ahora.',
            'fr' => 'J\'ai atteint la limite de coût que vous avez fixée pour une réponse. Voici ce que j\'ai jusqu\'ici.',
            'tr' => 'Bir cevap için belirlediğin maliyet sınırına ulaştım. Şu ana kadar elimde olanlar şunlar.',
        ],
        'payer_empty' => [
            'en' => 'The monthly budget is almost used up, so here is what I have so far.',
            'de' => 'Das Monatsbudget ist fast aufgebraucht. Hier ist, was ich bisher habe.',
            'es' => 'El presupuesto mensual está casi agotado. Esto es lo que tengo hasta ahora.',
            'fr' => 'Le budget mensuel est presque épuisé. Voici ce que j\'ai jusqu\'ici.',
            'tr' => 'Aylık bütçe neredeyse tükendi. Şu ana kadar elimde olanlar şunlar.',
        ],
        'circling' => [
            'en' => 'I was going in circles, so here is what I have so far.',
            'de' => 'Ich habe mich im Kreis gedreht. Hier ist, was ich bisher habe.',
            'es' => 'Estaba dando vueltas en círculo. Esto es lo que tengo hasta ahora.',
            'fr' => 'Je tournais en rond. Voici ce que j\'ai jusqu\'ici.',
            'tr' => 'Aynı yerde dönüp duruyordum. Şu ana kadar elimde olanlar şunlar.',
        ],
    ];

    private const WHY = [
        'cost_limit' => 'the cost limit for this answer is reached',
        'payer_empty' => 'the monthly budget is almost used up',
        'circling' => 'the work was going in circles',
    ];

    /** The stored reason, or null for anything that is not a wrap-up reason. */
    public static function reason(mixed $value): ?ChatRunEnd
    {
        $end = \is_string($value) ? ChatRunEnd::tryFrom($value) : null;

        return \in_array($end, self::REASONS, true) ? $end : null;
    }

    /** Asks for the wrap-up call: the run makes no other model call after it. */
    public static function begin(ChatRunState $state, ChatRunEnd $reason, ChatRunEvents $events): void
    {
        $state->mode = ChatRunState::MODE_WRAP_UP;
        $state->wrapUp = $reason;
        $state->pending = [];
        $events->append($state->runId, ChatRunEvents::WRAP_UP, ['reason' => $reason->value]);
    }

    /** The instruction of the wrap-up call. It is sent once and not kept in the transcript. */
    public static function instruction(ChatRunEnd $reason): string
    {
        return 'Stop working now: '.(self::WHY[$reason->value] ?? 'the run has to end').'. '
            .'Write the answer for the person from what this conversation already holds. You cannot use tools any more. '
            .'Do not explain why you stopped; that is said for you. '
            .'End with a short list of what is still open or unchecked.';
    }

    /** The fixed first line of the answer, in the person's language. */
    public static function opening(ChatRunEnd $reason, string $locale): string
    {
        $texts = self::OPENINGS[$reason->value] ?? null;
        if (null === $texts) {
            return '';
        }

        return $texts[strtolower(substr($locale, 0, 2))] ?? $texts['en'];
    }

    /** The answer: the opening line, then what the model wrote or the last thing it said. */
    public static function answer(ChatRunState $state, ChatRunEnd $reason, ?string $text): ?string
    {
        $body = null !== $text && '' !== trim($text) ? trim($text) : $state->lastAssistantText();
        if (null === $body || '' === trim($body)) {
            return null;
        }

        return self::opening($reason, $state->locale)."\n\n".trim($body);
    }
}
