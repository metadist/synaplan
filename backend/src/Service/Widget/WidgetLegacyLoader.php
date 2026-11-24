<?php

declare(strict_types=1);

namespace App\Service\Widget;

use App\Entity\Widget;

/**
 * The script `/widget.php` answers with: it starts the current widget for an
 * embed code from before `widget.js`, as the WordPress plugin still uses.
 *
 * A page that knows its visitor sets
 * `window.SynaplanWidgetVisitor = { externalUserId, externalUserIdSignature }`
 * before this script runs; the id only counts with the signature the page's
 * server made (see WidgetVisitorIdentity).
 */
final readonly class WidgetLegacyLoader
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;

    public function script(string $apiBaseUrl, Widget $widget): string
    {
        $config = $widget->getConfig();
        $init = json_encode([
            'widgetId' => $widget->getWidgetId(),
            'position' => $config['position'] ?? 'bottom-right',
            'primaryColor' => $config['primaryColor'] ?? '#007bff',
            'iconColor' => $config['iconColor'] ?? '#ffffff',
            'defaultTheme' => $config['defaultTheme'] ?? 'light',
            'autoOpen' => !empty($config['autoOpen']),
            'autoMessage' => $config['autoMessage'] ?? 'Hello! How can I help you today?',
            'messageLimit' => (int) ($config['messageLimit'] ?? 50),
            'maxFileSize' => (int) ($config['maxFileSize'] ?? 10),
            'allowFileUpload' => !empty($config['allowFileUpload']),
            'fileUploadLimit' => (int) ($config['fileUploadLimit'] ?? 3),
            'apiUrl' => $apiBaseUrl,
            'lazy' => false,
        ], self::JSON_FLAGS);
        $widgetJs = json_encode($apiBaseUrl.'/widget.js', self::JSON_FLAGS);

        $module = <<<JS
import SynaplanWidget from {$widgetJs};
const visitor = window.SynaplanWidgetVisitor || {};
SynaplanWidget.init(Object.assign({$init}, {
    externalUserId: visitor.externalUserId,
    externalUserIdSignature: visitor.externalUserIdSignature
})).catch(err => {
    console.error('Synaplan Widget: Failed to initialize', err);
});
JS;
        // The module source travels as a JSON string, so no widget setting can end it early.
        $moduleSource = json_encode($module, self::JSON_FLAGS);

        return <<<JS
(function() {
    'use strict';

    // Legacy widget.php compatibility layer
    console.log('Synaplan Widget: Loading via legacy widget.php endpoint (uid parameter is deprecated)');

    // Load the modern widget as ES module
    var script = document.createElement('script');
    script.type = 'module';
    script.textContent = {$moduleSource};

    // Add script to page
    document.head.appendChild(script);
})();
JS;
    }
}
