<?php

declare(strict_types=1);

namespace CloudFixture;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function json(int $status, array $data): self
    {
        return new self($status, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", ['Content-Type' => 'application/json']);
    }

    public static function html(int $status, string $title, string $text): self
    {
        $page = '<!doctype html><meta charset="utf-8"><title>'.htmlspecialchars($title).'</title>'
            .'<body style="font-family: sans-serif; max-width: 40rem; margin: 3rem auto">'
            .'<h1>'.htmlspecialchars($title).'</h1><p>'.htmlspecialchars($text).'</p></body>';

        return new self($status, $page, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $location): self
    {
        return new self(302, '', ['Location' => $location]);
    }

    public static function file(string $bytes, string $name): self
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return new self(200, $bytes, [
            'Content-Type' => is_string($mime) ? $mime : 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addcslashes($name, '"\\').'"',
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name.': '.$value);
        }
        echo $this->body;
    }
}
