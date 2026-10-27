<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

/**
 * User-facing sentences for a Notion picture. The picture itself stays on
 * Notion; these lines only say what was found and that nothing was saved here.
 */
final class NotionImageCopy
{
    public static function found(string $language, string $name, string $page): string
    {
        return match (self::code($language)) {
            'de' => sprintf('Bild „%s“ auf der Seite „%s“ gefunden. Es wird direkt von Notion geladen und nicht in deinen Dateien gespeichert.', $name, $page),
            'es' => sprintf('Encontré la imagen «%s» en la página «%s». Se carga desde Notion y no se guardó en tus archivos.', $name, $page),
            'fr' => sprintf('Image « %s » trouvée sur la page « %s ». Elle est chargée depuis Notion et n’a pas été enregistrée dans vos fichiers.', $name, $page),
            'tr' => sprintf('«%s» görseli «%s» sayfasında bulundu. Notion üzerinden yüklenir ve dosyalarına kaydedilmez.', $name, $page),
            default => sprintf('Found image "%s" on page "%s". It is loaded from Notion and was not saved in your files.', $name, $page),
        };
    }

    public static function none(string $language, int $looked, int $limit): string
    {
        return match (self::code($language)) {
            'de' => sprintf('Ich habe %d von %d Seiten angesehen und kein Bild gefunden, deshalb wurde nichts angehängt.', $looked, $limit),
            'es' => sprintf('Revisé %d de %d páginas y no encontré ninguna imagen, así que no se adjuntó nada.', $looked, $limit),
            'fr' => sprintf('J’ai parcouru %d pages sur %d et je n’ai trouvé aucune image, donc rien n’a été joint.', $looked, $limit),
            'tr' => sprintf('%d sayfanın %d tanesine baktım ve görsel bulamadım, bu yüzden hiçbir şey eklenmedi.', $limit, $looked),
            default => sprintf('Looked through %d of %d pages and found no image, so nothing was attached.', $looked, $limit),
        };
    }

    public static function noLink(string $language): string
    {
        return match (self::code($language)) {
            'de' => 'Notion hat keinen Download-Link geliefert, deshalb wurde nichts angehängt.',
            'es' => 'Notion no devolvió un enlace de descarga, así que no se adjuntó nada.',
            'fr' => 'Notion n’a pas renvoyé de lien de téléchargement, donc rien n’a été joint.',
            'tr' => 'Notion bir indirme bağlantısı vermedi, bu yüzden hiçbir şey eklenmedi.',
            default => 'Notion did not return a download link, so nothing was attached.',
        };
    }

    public static function notPublic(string $language): string
    {
        return match (self::code($language)) {
            'de' => 'Der Bild-Link ist keine öffentliche Adresse, deshalb wurde nichts angehängt.',
            'es' => 'El enlace de la imagen no es una dirección pública, así que no se adjuntó nada.',
            'fr' => 'Le lien de l’image n’est pas une adresse publique, donc rien n’a été joint.',
            'tr' => 'Görsel bağlantısı herkese açık bir adres değil, bu yüzden hiçbir şey eklenmedi.',
            default => 'The image link is not a public address, so nothing was attached.',
        };
    }

    private static function code(string $language): string
    {
        $code = strtolower(substr(trim($language), 0, 2));

        return in_array($code, ['de', 'en', 'es', 'fr', 'tr'], true) ? $code : 'en';
    }
}
