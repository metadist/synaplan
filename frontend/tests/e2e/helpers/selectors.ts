export const selectors = {
  login: {
    email: '#email',
    password: '#password',
    submit: 'button[type="submit"]',
  },
  nav: {
    newChatButton: '[data-testid="btn-chat-new"]',
  },
  chat: {
    textInput: '[data-testid="input-chat-message"]',
    sendBtn: '[data-testid="btn-chat-send"]',
    messageContainer: '[data-testid="message-container"]',
    aiAnswerBubble: '[data-testid="assistant-message-bubble"]',
    loadIndicator: '[data-testid="loading-typing-indicator"]',
    messageText: '[data-testid="message-text"]',
    againDropdown: '[data-testid="btn-message-model-toggle"]',
    againDropdownItem: 'button.dropdown-item',
  },
  userMenu: {
    button: '[data-testid="btn-user-menu-toggle"]',
    logoutBtn: '[data-testid="btn-user-logout"]',
  },
  toast: {},
} as const
