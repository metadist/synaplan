<?php

declare(strict_types=1);

namespace App\Service\Opendesk;

use App\Module\Sidecar\OpendeskSttModule;

/**
 * The operator snippet for Jitsi (Nordeck) and Element. People copy this;
 * they do not edit a Helm chart as the first screen.
 */
final readonly class OpendeskConnectSnippet
{
    public function __construct(
        private string $publicUrl,
        private string $internalUrl,
        private string $language,
        private string $mode,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->describe(
            OpendeskSttModule::modeOf($this->mode),
            OpendeskSttModule::languageOf($this->language),
            $this->websocketBase(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(string $mode, string $language, string $websocketBase): array
    {
        $template = rtrim($websocketBase, '/').'/transcribe?sessionId={{MEETING_ID}}&sendBack=true&lang='.$language;

        return [
            'product' => 'Meeting notes',
            'mode' => $mode,
            'language' => $language,
            'consent' => 'A named person starts notes. Everyone sees captions. Stop ends them. Audio is not kept.',
            'undo' => 'Stop ends this meeting. Disconnect removes the key. Saved notes stay. Audio was not kept.',
            'find' => 'Notes land in the Nextcloud folder, and in the Element room when one is set. There is no Meeting notes screen in Synaplan yet.',
            'jitsi' => [
                'url_template' => $template,
                'jicofo' => "transcription {\n  url-template = \"{$template}\"\n  http-headers {\n    \"Authorization\" = \"Bearer <TRANSCRIBER_AUTH_TOKEN>\"\n  }\n}",
                'config_js' => 'config.transcription = { enabled: true }',
            ],
            'element' => [
                'user' => '@synaplan-notes:<server>',
                'voice_messages' => 'Invite that account to a room. A voice message gets a thread reply with the text.',
                'locked_room' => 'This room is locked. Invite Meeting notes as a member, or turn captions off.',
                'element_call' => 'Live captions in Element Call are not in this version. The bot would have to join as a visible participant.',
            ],
            'scopes' => ['audio:transcribe'],
            'env' => [
                'TRANSCRIBER_MODE' => $mode,
                'TRANSCRIBER_LANGUAGE' => $language,
                'SYNAPLAN_URL' => '<this Synaplan>',
                'SYNAPLAN_API_KEY' => '<key with audio:transcribe>',
                'NOTES_NEXTCLOUD_FOLDER' => '/Meetings',
            ],
        ];
    }

    private function websocketBase(): string
    {
        $public = trim($this->publicUrl);
        $base = '' !== $public ? $public : trim($this->internalUrl);
        $base = rtrim($base, '/');
        if (str_starts_with($base, 'https://')) {
            return 'wss://'.substr($base, 8);
        }
        if (str_starts_with($base, 'http://')) {
            return 'ws://'.substr($base, 7);
        }

        return $base;
    }
}
