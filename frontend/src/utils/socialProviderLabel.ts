/**
 * Label for a sign-in provider button.
 *
 * Enterprise SSO uses the translated default unless an administrator set
 * OIDC_PROVIDER_LABEL (`custom_label`). Other providers keep the name the
 * API returns (Google, GitHub, Apple).
 */
export interface SocialProviderLabelSource {
  id: string
  name: string
  custom_label?: boolean
}

export function socialProviderLabel(
  provider: SocialProviderLabelSource,
  translate: (key: string) => string
): string {
  if ('keycloak' === provider.id && true !== provider.custom_label) {
    return translate('auth.enterpriseSso')
  }

  return provider.name
}
