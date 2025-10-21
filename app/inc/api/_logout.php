<?php

/**
 * Logout Handler
 *
 * Handles OIDC and local logout functionality
 */

/**
 * Handle logout action
 * Clears local session and redirects to OIDC provider logout if configured
 */
function handleLogout()
{
    // Clear local session first
    unset($_SESSION['USERPROFILE']);
    // Clear any widget/anonymous session flags to avoid UI leaks after login
    Frontend::clearWidgetSession();

    // If OIDC is configured, redirect to IDP logout
    if (OidcAuth::isConfigured()) {
        $providerUrl = ApiKeys::getOidcProviderUrl();
        $clientId = ApiKeys::getOidcClientId();

        if ($providerUrl && $clientId) {
            // Build logout URL for Keycloak (most common)
            $logoutUrl = rtrim($providerUrl, '/') . '/protocol/openid-connect/logout?client_id=' . urlencode($clientId);

            header('Location: ' . $logoutUrl);
            exit;
        }
    }

    // Fallback: redirect to login page
    header('Location: index.php');
    exit;
}
