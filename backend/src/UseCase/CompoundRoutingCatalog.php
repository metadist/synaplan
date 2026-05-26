<?php

declare(strict_types=1);

namespace App\UseCase;

/**
 * Multi-step compound routing scenarios indexed in Qdrant (`synapse_use_cases`).
 *
 * Each entry carries `routing_steps` in the Qdrant payload so SynapseRouter can
 * attach a step plan without regex heuristics. Rich example phrasing in
 * descriptions improves embedding recall for compound user requests.
 *
 * @phpstan-type RoutingStep array{
 *     id: string,
 *     label_key: string,
 *     capability: string,
 *     web_search?: bool,
 *     input_from?: string,
 * }
 * @phpstan-type CompoundEntry array{
 *     id: string,
 *     primary_use_case_id: string,
 *     shortDescription: string,
 *     keywords: string,
 *     example_queries: string,
 *     routing_steps: list<RoutingStep>,
 * }
 */
final class CompoundRoutingCatalog
{
    /**
     * @return list<CompoundEntry>
     */
    public static function all(): array
    {
        return [
            self::researchThenImage(),
            self::writeThenReadAloud(),
            self::imageThenEmail(),
            self::emailThenAnalyze(),
            self::researchThenVideo(),
        ];
    }

    public static function find(string $id): ?array
    {
        foreach (self::all() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<RoutingStep>
     */
    public static function routingStepsFor(string $id): array
    {
        $entry = self::find($id);

        return null !== $entry ? $entry['routing_steps'] : [];
    }

    /**
     * @return CompoundEntry
     */
    private static function researchThenImage(): array
    {
        return [
            'id' => 'compound_research_image',
            'primary_use_case_id' => 'text_chat',
            'shortDescription' => 'Compound workflow: first research or answer a factual question using the web (prices, news, current data), then generate an image or picture illustrating the result.',
            'keywords' => 'research and image, search and picture, price and photo, answer and generate image, web search and draw, lookup and illustrate, recherchiere und bild, preis und foto, antwort und bild generieren, suche und zeichne',
            'example_queries' => implode("\n", [
                'What does a döner cost in Germany and generate an image of a döner',
                'Look up the current Bitcoin price and create a picture of a coin chart',
                'Research the weather in Berlin today and draw a sunny city scene',
                'Was kostet ein Döner in Deutschland und generiere ein Bild von einem Döner',
                'Recherchiere den aktuellen Benzinpreis und erstelle ein Foto an der Tankstelle',
                'Beantworte die Frage zum Preis und male ein Bild dazu',
            ]),
            'routing_steps' => [
                [
                    'id' => 'answer',
                    'label_key' => 'config.routing.steps.chat',
                    'capability' => 'CHAT',
                    'web_search' => true,
                ],
                [
                    'id' => 'generate',
                    'label_key' => 'config.routing.steps.mediaGenerate',
                    'capability' => 'TEXT2PIC',
                ],
            ],
        ];
    }

    /**
     * @return CompoundEntry
     */
    private static function writeThenReadAloud(): array
    {
        return [
            'id' => 'compound_write_read_aloud',
            'primary_use_case_id' => 'text_chat',
            'shortDescription' => 'Compound workflow: first write creative text (poem, story, letter, lyrics) in chat, then read it aloud with text-to-speech or voice output.',
            'keywords' => 'write and read aloud, poem and tts, story and speak, compose and voice, gedicht und vorlesen, schreiben und vorlesen, text und sprachausgabe, schreibe und lies vor, write poem read aloud',
            'example_queries' => implode("\n", [
                'Write a poem about döner and read it aloud',
                'Compose a short story and speak it with TTS',
                'Schreibe ein Gedicht zum Döner und lese es vor',
                'Schreib ein kurzes Gedicht und lies es mir vor',
                'Write a haiku and convert it to speech',
                'Erstelle einen Text und gib ihn als Audio aus',
            ]),
            'routing_steps' => [
                [
                    'id' => 'write',
                    'label_key' => 'config.routing.steps.chat',
                    'capability' => 'CHAT',
                ],
                [
                    'id' => 'speak',
                    'label_key' => 'config.routing.steps.readAloud',
                    'capability' => 'TEXT2SOUND',
                    'input_from' => 'steps.write.output.text',
                ],
            ],
        ];
    }

    /**
     * @return CompoundEntry
     */
    private static function imageThenEmail(): array
    {
        return [
            'id' => 'compound_image_email',
            'primary_use_case_id' => 'media_generation',
            'shortDescription' => 'Compound workflow: generate an image or picture first, then send it by email or attach it to an outgoing mail.',
            'keywords' => 'image and email, picture and send mail, generate and email, bild und mail, foto und schicken, erstelle bild und sende per email',
            'example_queries' => implode("\n", [
                'Generate a logo and send it by email',
                'Create a picture and mail it to my team',
                'Erstelle ein Bild und schick es per E-Mail',
                'Male ein Foto und sende es als Mail-Anhang',
            ]),
            'routing_steps' => [
                [
                    'id' => 'generate',
                    'label_key' => 'config.routing.steps.mediaGenerate',
                    'capability' => 'TEXT2PIC',
                ],
                [
                    'id' => 'send',
                    'label_key' => 'config.routing.steps.sendEmail',
                    'capability' => 'CHAT',
                    'input_from' => 'steps.generate.output.text',
                ],
            ],
        ];
    }

    /**
     * @return CompoundEntry
     */
    private static function emailThenAnalyze(): array
    {
        return [
            'id' => 'compound_email_analyze',
            'primary_use_case_id' => 'comm_receive_email',
            'shortDescription' => 'Compound workflow: fetch or read incoming email messages (yesterday, inbox, recent mail) and then analyze or summarize their content.',
            'keywords' => 'fetch email and analyze, read mail and summarize, inbox and extract, email from yesterday, gemailt gestern analysieren, mail holen und zusammenfassen',
            'example_queries' => implode("\n", [
                'Fetch the email I got yesterday and summarize it',
                'Read my latest inbox mail and analyze the content',
                'Hol die Mail von gestern und fasse sie zusammen',
                'Lies meine eingegangene E-Mail und analysiere sie',
            ]),
            'routing_steps' => [
                [
                    'id' => 'fetch',
                    'label_key' => 'config.routing.steps.fetchEmail',
                    'capability' => 'CHAT',
                ],
                [
                    'id' => 'analyse',
                    'label_key' => 'config.routing.steps.fileExtract',
                    'capability' => 'ANALYZE',
                    'input_from' => 'steps.fetch.output.text',
                ],
            ],
        ];
    }

    /**
     * @return CompoundEntry
     */
    private static function researchThenVideo(): array
    {
        return [
            'id' => 'compound_research_video',
            'primary_use_case_id' => 'text_chat',
            'shortDescription' => 'Compound workflow: first research or answer using the web, then generate a short video or clip based on the findings.',
            'keywords' => 'research and video, search and clip, answer and animate, recherchiere und video, suche und film erstellen',
            'example_queries' => implode("\n", [
                'Look up stock market news and create a short video about it',
                'Research the election results and generate a video clip',
                'Recherchiere die Börsenlage und erstelle ein kurzes Video',
            ]),
            'routing_steps' => [
                [
                    'id' => 'answer',
                    'label_key' => 'config.routing.steps.chat',
                    'capability' => 'CHAT',
                    'web_search' => true,
                ],
                [
                    'id' => 'generate',
                    'label_key' => 'config.routing.steps.videoGenerate',
                    'capability' => 'TEXT2VID',
                ],
            ],
        ];
    }
}
