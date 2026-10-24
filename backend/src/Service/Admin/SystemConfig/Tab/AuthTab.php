<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig\Tab;

use App\Service\Admin\SystemConfig\ConfigSection;
use App\Service\Admin\SystemConfig\DatabaseRow;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigTab;
use App\Service\Auth\RegistrationConfig;
use App\Service\Auth\SignupPauseSwitch;
use App\Service\Chat\GuestChatConfig;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The Authentication tab: who can use the instance, reCAPTCHA v3, sign-in
 * with Google, GitHub and Apple, OIDC (enterprise SSO) and its access
 * restrictions.
 */
#[AsTaggedItem(priority: 90)]
final readonly class AuthTab implements SystemConfigTab
{
    public function id(): string
    {
        return 'auth';
    }

    public function label(): string
    {
        return 'Authentication';
    }

    public function sections(): array
    {
        return [
            'access' => $this->access(),
            'recaptcha' => $this->recaptcha(),
            'google' => $this->google(),
            'github' => $this->github(),
            'apple' => $this->apple(),
            'oidc' => $this->oidc(),
            'oidc_access' => $this->oidcAccess(),
        ];
    }

    /**
     * Written by the first-run setup wizard and editable here, no restart. An
     * explicit REGISTRATION_ENABLED / GUEST_CHAT_ENABLED environment variable
     * still wins over the stored row; the values report that as
     * `envOverride`, so this page never shows a toggle that silently does
     * nothing.
     */
    private function access(): ConfigSection
    {
        return new ConfigSection('Who can use this instance', [
            'REGISTRATION_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Allow visitors to create their own account with email and password. Turn this off for an invite-only instance (an administrator then creates every account) or for SSO-only deployments. The sign-up page and the API both refuse when off.',
                default: 'true',
                row: new DatabaseRow(RegistrationConfig::CONFIG_GROUP, RegistrationConfig::KEY_ENABLED),
            ),
            'GUEST_CHAT_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Let visitors try the chat without signing in. Turn this off so everyone has to sign in first — unauthenticated visitors are sent to the login page and every guest endpoint is refused.',
                default: 'true',
                row: new DatabaseRow(GuestChatConfig::CONFIG_GROUP, GuestChatConfig::KEY_ENABLED),
            ),
            'SIGNUP_PAUSED' => new FieldDefinition(
                type: 'boolean',
                description: 'Pause sign-up. Emergency switch for a flood of fake accounts: while on, no new account can be created on any path (email, Google, GitHub, Apple) and the person is told sign-up is paused. Existing accounts sign in as usual. Takes effect immediately, no restart.',
                default: 'false',
                row: new DatabaseRow(SignupPauseSwitch::CONFIG_GROUP, SignupPauseSwitch::KEY_PAUSED),
            ),
        ]);
    }

    private function recaptcha(): ConfigSection
    {
        return new ConfigSection('Human check (Turnstile, reCAPTCHA v3)', [
            'TURNSTILE_SITE_KEY' => new FieldDefinition(
                type: 'text',
                description: 'Cloudflare Turnstile site key. With both Turnstile keys set, Turnstile runs in front of sign-up, sign-in and every Google/GitHub/Apple start, and reCAPTCHA is ignored. Fails closed.',
            ),
            'TURNSTILE_SECRET_KEY' => new FieldDefinition(
                type: 'password',
                description: 'Cloudflare Turnstile secret key',
                sensitive: true,
            ),
            'RECAPTCHA_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Enable reCAPTCHA',
                default: 'false',
            ),
            'RECAPTCHA_SITE_KEY' => new FieldDefinition(
                type: 'text',
                description: 'reCAPTCHA site key',
            ),
            'RECAPTCHA_SECRET_KEY' => new FieldDefinition(
                type: 'password',
                description: 'reCAPTCHA secret key',
                sensitive: true,
            ),
            'RECAPTCHA_MIN_SCORE' => new FieldDefinition(
                type: 'number',
                description: 'Minimum score (0.0-1.0)',
                default: '0.5',
            ),
        ]);
    }

    private function google(): ConfigSection
    {
        return new ConfigSection('Google OAuth 2.0', [
            'GOOGLE_CLIENT_ID' => new FieldDefinition(
                type: 'text',
                description: 'Google OAuth client ID',
            ),
            'GOOGLE_CLIENT_SECRET' => new FieldDefinition(
                type: 'password',
                description: 'Google OAuth client secret',
                sensitive: true,
            ),
            'GOOGLE_CLOUD_PROJECT_ID' => new FieldDefinition(
                type: 'text',
                description: 'Google Cloud project ID',
            ),
        ]);
    }

    private function github(): ConfigSection
    {
        return new ConfigSection('GitHub OAuth 2.0', [
            'GITHUB_CLIENT_ID' => new FieldDefinition(
                type: 'text',
                description: 'GitHub OAuth client ID',
            ),
            'GITHUB_CLIENT_SECRET' => new FieldDefinition(
                type: 'password',
                description: 'GitHub OAuth client secret',
                sensitive: true,
            ),
        ]);
    }

    private function apple(): ConfigSection
    {
        return new ConfigSection('Sign in with Apple', [
            'APPLE_CLIENT_ID' => new FieldDefinition(
                type: 'text',
                description: 'Apple Services ID (web OAuth client ID / id_token audience)',
            ),
            'APPLE_TEAM_ID' => new FieldDefinition(
                type: 'text',
                description: 'Apple Developer Team ID (10 chars)',
            ),
            'APPLE_KEY_ID' => new FieldDefinition(
                type: 'text',
                description: 'Key ID of the Sign-in-with-Apple private key (.p8)',
            ),
            'APPLE_PRIVATE_KEY' => new FieldDefinition(
                type: 'password',
                description: 'Contents of the AuthKey_XXXX.p8 (PKCS#8 PEM)',
                sensitive: true,
            ),
            'APPLE_APP_BUNDLE_ID' => new FieldDefinition(
                type: 'text',
                description: 'Native iOS bundle id (identity-token audience)',
                default: 'com.synaplan.app',
            ),
        ]);
    }

    private function oidc(): ConfigSection
    {
        return new ConfigSection('OIDC (Enterprise SSO)', [
            'OIDC_DISCOVERY_URL' => new FieldDefinition(
                type: 'url',
                description: 'OIDC discovery URL',
            ),
            'OIDC_CLIENT_ID' => new FieldDefinition(
                type: 'text',
                description: 'OIDC client ID',
            ),
            'OIDC_CLIENT_SECRET' => new FieldDefinition(
                type: 'password',
                description: 'OIDC client secret',
                sensitive: true,
            ),
            'OIDC_SCOPES' => new FieldDefinition(
                type: 'text',
                description: 'Space-separated permissions requested at sign-in. offline_access is rewritten to offline when the provider advertises offline and not offline_access (Kinde). A refused permission stops sign-in and names the permission. Restart required.',
                default: 'openid email profile offline_access',
            ),
            'OIDC_BEARER_AUDIENCE' => new FieldDefinition(
                type: 'text',
                description: 'Audience the access token must carry. Leave empty to use the client ID. When set, it is also requested at sign-in, which Kinde and Auth0 need (Kinde: the audience of the API registered for the application). Restart required.',
            ),
            'OIDC_PROVIDER_LABEL' => new FieldDefinition(
                type: 'text',
                description: 'Text on the sign-in button. Leave empty to show Enterprise SSO. Restart required.',
            ),
        ]);
    }

    private function oidcAccess(): ConfigSection
    {
        return new ConfigSection('OIDC access restrictions', [
            'OIDC_ORG_CODE' => new FieldDefinition(
                type: 'text',
                description: 'Only people from this organization may sign in. Sent to the provider at sign-in (Kinde org_code) and checked in the token. Leave empty for no organization check. Restart required.',
            ),
            'OIDC_ORG_CLAIM' => new FieldDefinition(
                type: 'text',
                description: 'Token claim that holds the organization (dot notation). org_code for Kinde, organization for Keycloak. Restart required.',
                default: 'org_code',
            ),
            'OIDC_REQUIRED_ROLE' => new FieldDefinition(
                type: 'text',
                description: 'Comma-separated roles; a person needs at least one of them to sign in (case-insensitive). Roles are read from the role claims below. Leave empty for no role check. Restart required.',
            ),
            'OIDC_ROLE_CLAIMS' => new FieldDefinition(
                type: 'text',
                description: 'Comma-separated token claims that hold roles (dot notation, {client_id} placeholder). Also decides who becomes administrator. Add roles for Kinde. Restart required.',
                default: 'realm_access.roles,resource_access.{client_id}.roles,groups',
            ),
            'OIDC_REQUIRED_PERMISSIONS' => new FieldDefinition(
                type: 'text',
                description: 'Comma-separated permissions; a person needs all of them to sign in. Leave empty for no permission check. Restart required.',
            ),
            'OIDC_PERMISSIONS_CLAIM' => new FieldDefinition(
                type: 'text',
                description: 'Token claim that holds the permissions (dot notation). Restart required.',
                default: 'permissions',
            ),
            'OIDC_ALLOW_USER_PROVISIONING' => new FieldDefinition(
                type: 'boolean',
                description: 'Create a Synaplan account on the first SSO sign-in. Turn off to admit only people who have signed in through SSO before; accounts created another way are not taken over. Restart required.',
                default: 'true',
            ),
        ]);
    }
}
