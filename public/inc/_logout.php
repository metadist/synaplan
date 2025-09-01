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
function handleLogout() {
    // Clear local session first
    unset($_SESSION['USERPROFILE']);
    // Clear any widget/anonymous session flags to avoid UI leaks after login
    unset($_SESSION['is_widget']);
    unset($_SESSION['widget_owner_id']);
    unset($_SESSION['widget_id']);
    unset($_SESSION['anonymous_session_id']);
    unset($_SESSION['anonymous_session_created']);
    unset($_SESSION['WIDGET_PROMPT']);
    unset($_SESSION['WIDGET_AUTO_MESSAGE']);
    
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