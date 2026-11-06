/** True when a stranger may still create an account. A missing payload stays open. */
export function isSelfSignupOpen(auth?: {
  registrationEnabled?: boolean
  signupPaused?: boolean
}): boolean {
  return (auth?.registrationEnabled ?? true) && !(auth?.signupPaused ?? false)
}
