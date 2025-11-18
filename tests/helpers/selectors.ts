/**
 * Zentrale Selektor-Konstanten
 * TODO: Passe Selektoren an deine App an
 * Bevorzugt: [data-testid] Attribute verwenden
 */
export const selectors = {
  login: {
    email: '#email', // oder '[data-testid="login-email"]' - Best Practice: data-testid hinzufügen
    password: '#password', // oder '[data-testid="login-password"]'
    submit: 'button[type="submit"]', // oder 'button:has-text("Sign In")' oder '[data-testid="login-submit"]'
  },
  nav: {
    newChatButton: 'button:has-text("New Chat")',
  },
  chat: {
    marker: 'textarea[placeholder="Type your message..."]',
    widget: '[data-testid="dashboard-widget"]', // TODO: Kern-Widget-Selektor
  },
  userMenu: {
    button: '[data-testid="user-menu"]', // oder '.user-menu'
    logout: '[data-testid="logout"]', // oder 'text=Logout'
  },
  toast: {
    success: '[data-testid="toast-success"]', // oder '.toast-success'
  },
} as const;
