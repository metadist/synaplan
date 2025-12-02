// synaplan-ui/src/composables/usePasswordValidation.ts
export interface PasswordValidation {
  isValid: boolean
  errors: string[]
  strength: 'weak' | 'medium' | 'strong'
}

export function usePasswordValidation(password: string): PasswordValidation {
  const errors: string[] = []

  // Min length
  if (password.length < 8) {
    errors.push('At least 8 characters required')
  }

  // Uppercase
  if (!/[A-Z]/.test(password)) {
    errors.push('At least one uppercase letter required')
  }

  // Lowercase
  if (!/[a-z]/.test(password)) {
    errors.push('At least one lowercase letter required')
  }

  // Number
  if (!/\d/.test(password)) {
    errors.push('At least one number required')
  }

  // Calculate strength
  let strength: 'weak' | 'medium' | 'strong' = 'weak'
  if (errors.length === 0) {
    if (password.length >= 12 && /[!@#$%^&*(),.?":{}|<>]/.test(password)) {
      strength = 'strong'
    } else if (password.length >= 10) {
      strength = 'medium'
    }
  }

  return {
    isValid: errors.length === 0,
    errors,
    strength
  }
}

export function validateEmail(email: string): boolean {
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  return emailRegex.test(email)
}
