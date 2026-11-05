<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Service\Disclosure\ChannelReplyNotice;

/**
 * The HTML fragment and the plain-text body of an AI mail reply,
 * including the metadata line and the AI-notice footer.
 */
final readonly class AiResponseEmailBody
{
    public function __construct(private ?ChannelReplyNotice $notices = null)
    {
    }

    /**
     * @return array{html: string, text: string, inline: bool}
     */
    public function compose(
        string $bodyText,
        ?string $provider,
        ?string $model,
        ?float $processingTime,
        bool $embedImage,
        string $imageAlt,
        ?string $locale,
        string $senderName,
        ?int $ownerId,
        ?string $mailbox,
        bool $disclose = true,
    ): array {
        $formatter = new MarkdownEmailFormatter();
        $html = $formatter->toFragment($bodyText);
        $text = $bodyText;

        if ($embedImage) {
            $alt = htmlspecialchars($imageAlt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<br><br><img src="cid:generated-image" alt="'.$alt.'" style="max-width: 100%; border-radius: 8px;">';
        }

        if ($provider || $model || null !== $processingTime) {
            $htmlParts = [];
            $textParts = [];
            if ($provider) {
                $htmlParts[] = 'Service: '.htmlspecialchars($provider);
                $textParts[] = 'Service: '.$provider;
            }
            if ($model) {
                $htmlParts[] = 'Model: '.htmlspecialchars($model);
                $textParts[] = 'Model: '.$model;
            }
            if (null !== $processingTime) {
                $timing = sprintf('Processing time: %.2f seconds', $processingTime);
                $htmlParts[] = $timing;
                $textParts[] = $timing;
            }
            if ([] !== $htmlParts) {
                $html .= '<br><br><div style="font-size: 11px; color: #888888; margin-top: 20px; padding-top: 15px; border-top: 1px solid #e0e0e0;">';
                $html .= implode(' &middot; ', $htmlParts);
                $html .= '<br><a href="https://www.synaplan.com/" style="color: #888888;">www.synaplan.com</a>';
                $html .= '</div>';
                $text .= "\n\n---\n".implode(' | ', $textParts)."\nhttps://www.synaplan.com/";
            }
        }

        if ($disclose && null !== $this->notices) {
            $sentence = $this->notices->mailSentence($ownerId ?? 0, $locale ?? 'en', $senderName, $mailbox);
            $safe = htmlspecialchars($sentence, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<p style="font-size: 12px; color: #444444; margin-top: 16px;">'.$safe.'</p>';
            $text .= "\n\n".$sentence;
        }

        return ['html' => $html, 'text' => $text, 'inline' => $embedImage];
    }
}
