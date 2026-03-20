<?php

namespace App\Service\Message\Handler;

use App\AI\Exception\ProviderException;

class MediaErrorMessageBuilder
{
    /**
     * Build a user-friendly, translated error message from the exception.
     */
    public function buildErrorMessage(\Exception $e, string $mediaType, string $lang): string
    {
        if ($e instanceof ProviderException) {
            $ctx = $e->getContext() ?? [];
            $blockReason = $ctx['block_reason'] ?? null;

            if ($blockReason) {
                return $this->buildContentBlockedMessage(
                    $e->getProviderName(),
                    $blockReason,
                    $ctx['text_response'] ?? null,
                    $mediaType,
                    $lang,
                );
            }
        }

        return $this->getGenericMediaError($mediaType, $lang);
    }

    private function buildContentBlockedMessage(string $providerName, string $reason, ?string $textResponse, string $mediaType, string $lang): string
    {
        $reasonExplanations = $this->getBlockReasonExplanations($lang);
        $explanation = $reasonExplanations[$reason] ?? $reasonExplanations['OTHER'];

        $displayName = ucfirst($providerName);

        if ('de' === $lang) {
            $mediaLabel = match ($mediaType) {
                'audio' => 'des Audios',
                'video' => 'des Videos',
                default => 'des Bildes',
            };
            $msg = "{$displayName} hat die Erstellung {$mediaLabel} mit dem Code **{$reason}** abgelehnt.\n\n{$explanation}";
        } else {
            $mediaLabel = match ($mediaType) {
                'audio' => 'audio',
                'video' => 'video',
                default => 'image',
            };
            $msg = "{$displayName} refused to generate the {$mediaLabel} with code **{$reason}**.\n\n{$explanation}";
        }

        if ($textResponse) {
            $preview = mb_substr($textResponse, 0, 300);
            $msg .= "\n\n> ".str_replace("\n", "\n> ", $preview);
        }

        return $msg;
    }

    /**
     * @return array<string, string>
     */
    private function getBlockReasonExplanations(string $lang): array
    {
        if ('de' === $lang) {
            return [
                'SAFETY' => 'Das bedeutet, dass der Inhalt gegen die Sicherheitsrichtlinien des Anbieters verstößt. '
                    .'Häufige Gründe: Darstellung realer Personen, Gewalt, anstößige Inhalte, '
                    .'oder Manipulation von Fotos echter Menschen. '
                    .'Tipp: Formuliere die Anfrage um oder verwende ein anderes Modell (z.B. GPT Image).',
                'RECITATION' => 'Das bedeutet, dass die Antwort urheberrechtlich geschütztes Material enthalten könnte. '
                    .'Der Anbieter blockiert Inhalte, die zu stark an bestehende Werke erinnern. '
                    .'Tipp: Formuliere die Anfrage origineller oder beschreibe den gewünschten Stil allgemeiner.',
                'PROHIBITED_CONTENT' => 'Der Inhalt wurde als verboten eingestuft. '
                    .'Der Anbieter blockiert bestimmte Kategorien grundsätzlich und ohne Ausnahme. '
                    .'Bitte überarbeite deine Anfrage grundlegend.',
                'BLOCKLIST' => 'Die Anfrage enthält Begriffe, die auf der Sperrliste des Anbieters stehen. '
                    .'Tipp: Verwende andere Begriffe oder formuliere die Anfrage um.',
                'SPII' => 'Die Anfrage scheint sensible persönliche Daten zu enthalten '
                    .'(z.B. Ausweisnummern, Finanzdaten). Der Anbieter blockiert solche Anfragen automatisch.',
                'IMAGE_SAFETY' => 'Eines der hochgeladenen Bilder wurde vom Anbieter als problematisch eingestuft. '
                    .'Tipp: Verwende ein anderes Bild oder ein anderes Modell.',
                'OTHER' => 'Die Anfrage wurde aus einem unbekannten Grund blockiert. '
                    .'Tipp: Formuliere die Anfrage um oder verwende ein anderes Modell.',
            ];
        }

        return [
            'SAFETY' => 'This means the content violates the provider\'s safety policies. '
                .'Common reasons: depicting real people, violence, offensive content, '
                .'or manipulating photos of real individuals. '
                .'Tip: Rephrase your request or try a different model (e.g. GPT Image).',
            'RECITATION' => 'This means the response may contain copyrighted material. '
                .'The provider blocks content that closely resembles existing works. '
                .'Tip: Make your request more original or describe the desired style more generally.',
            'PROHIBITED_CONTENT' => 'The content was classified as prohibited. '
                .'The provider blocks certain categories unconditionally. '
                .'Please fundamentally rework your request.',
            'BLOCKLIST' => 'Your request contains terms on the provider\'s blocklist. '
                .'Tip: Use different terms or rephrase your request.',
            'SPII' => 'Your request appears to contain sensitive personal information '
                .'(e.g. ID numbers, financial data). The provider blocks such requests automatically.',
            'IMAGE_SAFETY' => 'One of the uploaded images was flagged as problematic by the provider. '
                .'Tip: Try a different image or use a different model.',
            'OTHER' => 'The request was blocked for an unknown reason. '
                .'Tip: Rephrase your request or try a different model.',
        ];
    }

    private function getGenericMediaError(string $mediaType, string $lang): string
    {
        if ('de' === $lang) {
            return match ($mediaType) {
                'audio' => 'Das Audio konnte leider nicht erstellt werden. Bitte versuche es erneut oder wähle ein anderes Modell. Tipp: Verwende für Audio eine klare Anweisung wie "Lies diesen Text vor: ...".',
                'video' => 'Das Video konnte leider nicht erstellt werden. Bitte versuche es erneut oder wähle ein anderes Modell.',
                default => 'Das Bild konnte leider nicht erstellt werden. Bitte versuche es erneut oder wähle ein anderes Modell.',
            };
        }

        return match ($mediaType) {
            'audio' => 'Sorry, the audio could not be generated right now. Please try again or use a different model. Tip: For audio, try a clear prompt like "Read this text aloud: ...".',
            'video' => 'Sorry, the video could not be generated right now. Please try again or use a different model.',
            default => 'Sorry, the image could not be generated right now. Please try again or use a different model.',
        };
    }
}
