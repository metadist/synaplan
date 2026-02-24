export const selectors = {
  login: {
    email: '#email',
    password: '#password',
    submit: 'button[type="submit"]',
    signUpLink: '[data-testid="link-signup"]',
  },
  register: {
    fullName: '[data-testid="input-full-name"]',
    email: '[data-testid="input-email"]',
    password: '[data-testid="input-password"]',
    confirmPassword: '[data-testid="input-confirm-password"]',
    submit: '[data-testid="btn-register"]',
    successSection: '[data-testid="section-registration-success"]',
    /** Shown when backend/register returns error (use with race(success, error) for fail-fast) */
    errorAlert: '[data-testid="alert-register-error"]',
    backToLoginBtn: '[data-testid="btn-goto-login"]',
  },
  verifyEmail: {
    successState: '[data-testid="state-success"]',
    goToLoginLink: '[data-testid="link-success-login"]',
  },
  header: {
    modeToggle: '[data-testid="btn-mode-toggle"]',
  },
  nav: {
    sidebar: '[data-testid="comp-sidebar-v2"]',
    navDropdown: '[data-testid="dropdown-sidebar-v2-nav"]',
    /** Expand sidebar when collapsed (so chat dropdown is visible) */
    sidebarExpand: '[data-testid="btn-sidebar-expand"]',
    /** V2 sidebar: single plus button to start new chat (no toggle/dropdown) */
    sidebarV2NewChat: '[data-testid="btn-sidebar-v2-new-chat"]',
    /** V2 sidebar: chat nav icon opens chat list modal (path "/" → testid "btn-sidebar-v2--") */
    sidebarV2ChatNav: '[data-testid="btn-sidebar-v2--"]',
    /** V2 chat list modal */
    modalChatManager: '[data-testid="modal-chat-manager"]',
    /** V2 chat list: container visible when at least one chat exists; use to wait before targeting rows */
    chatManagerListRows: '[data-testid="list-chat-manager-rows"]',
    /** V2 chat list: one row per chat; scope menu to this */
    chatV2Row: '[data-testid="row-chat-v2"]',
    /** V2 chat row: 3-dots menu button */
    chatV2RowMenu: '[data-testid="btn-chat-v2-row-menu"]',
    /** V2 chat context menu: Share button */
    chatV2Share: '[data-testid="btn-chat-v2-share"]',
  },
  models: {
    page: '[data-testid="page-config-ai-models"]',
    capabilityItem: '[data-testid="item-capability"]',
    capabilityDropdown: '[data-testid="btn-model-dropdown"]',
    capabilityOption: '[data-testid="btn-model-option"]',
  },
  rag: {
    page: '[data-testid="page-rag-search"]',
    queryInput: '[data-testid="input-query"]',
    searchButton: '[data-testid="btn-search"]',
    searchSummary: '[data-testid="text-search-summary"]',
    resultsSection: '[data-testid="section-results"]',
    resultItem: '[data-testid="item-result"]',
  },
  chat: {
    newChatButton: '[data-testid="btn-chat-new-dropdown"]',
    chatBtnToggle: '[data-testid="btn-chat-toggle"]',
    textInput: '[data-testid="input-chat-message"]',
    sendBtn: '[data-testid="btn-chat-send"]',
    attachBtn: '[data-testid="btn-chat-attach"]',
    fileInput: '[data-testid="input-chat-file"]',
    messageContainer: '[data-testid="message-container"]',
    aiAnswerBubble: '[data-testid="assistant-message-bubble"]',
    /** Terminal: present when streaming finished */
    chatDone: '[data-testid="message-done"]',
    /** Terminal: present when message ended in error */
    chatError: '[data-testid="message-topic-error"]',
    messageUser: '[data-testid="message-user"]',
    messageAssistant: '[data-testid="message-assistant"]',
    /** Present inside assistant bubble when streaming finished (prefer over loader hidden) */
    messageDone: '[data-testid="message-done"]',
    loadIndicator: '[data-testid="loading-typing-indicator"]',
    /** Wrapper that contains only the generated answer body (no timestamp, no footer). Use this for asserting reply text. */
    assistantAnswerBody: '[data-testid="section-message-text"]',
    messageText: '[data-testid="message-text"]',
    /** Present when message topic is ERROR (backend error path); use to assert no error in bubble */
    messageTopicError: '[data-testid="message-topic-error"]',
    againDropdown: '[data-testid="btn-message-model-toggle"]',
    againDropdownItem: 'button.dropdown-item',
  },
  share: {
    shareButton: '[data-testid="btn-chat-share"]',
    shareModal: '[data-testid="modal-chat-share"]',
    modalRoot: '[data-testid="modal-chat-share-root"]',
    shareCreate: '[data-testid="btn-chat-share-make-public"]',
    shareLinkInput: '[data-testid="share-link-input"]',
    shareDone: '[data-testid="share-done"]',
    shareError: '[data-testid="share-error"]',
    makePublicBtn: '[data-testid="btn-chat-share-make-public"]',
    copyBtn: '[data-testid="btn-chat-share-copy"]',
    closeBtn: '[data-testid="btn-chat-share-close"]',
    revokeBtn: '[data-testid="btn-chat-share-revoke"]',
    /** Sidebar: menu toggle per chat entry (open first, then click share) */
    sidebarEntryMenu: '[data-testid="btn-chat-entry-menu"]',
    /** Sidebar: share action inside dropdown */
    sidebarShareBtn: '[data-testid="btn-chat-entry-share"]',
    /** Chat dropdown (nav left): section visible when chat toggle is open */
    chatDropdownSection: '[data-testid="section-chat-dropdown"]',
    /** Chat dropdown: one row per chat; scope menu/share to this row */
    chatDropdownRow: '[data-testid="row-chat-item"]',
    /** Scoped to chatDropdownRow: menu (three dots), share, rename, delete buttons */
    chatMenuButton: '[data-testid="btn-chat-menu"]',
    chatShareButton: '[data-testid="btn-chat-share"]',
    chatRenameButton: '[data-testid="btn-chat-rename"]',
    chatDeleteButton: '[data-testid="btn-chat-delete"]',
    /** Chat dropdown: item button (title) – scope by chatDropdownRow */
    chatDropdownFirstItem: '[data-testid="btn-chat-item"]',
    /** Chat browser: share button on chat card (no dropdown) */
    browserShareBtn: '[data-testid="btn-chat-share"]',
    /** Share link URL (read-only text in modal) */
    shareLink: '[data-testid="share-link-input"]',
  },
  sharedChat: {
    sharedChatRoot: '[data-testid="shared-chat-root"]',
    sharedMessageList: '[data-testid="shared-message-list"]',
    /** Shared page root (read-only view) */
    page: '[data-testid="page-shared-chat"]',
    loading: '[data-testid="state-loading"]',
    error: '[data-testid="state-error"]',
    content: '[data-testid="section-chat-content"]',
    messagesSection: '[data-testid="shared-message-list"]',
    messageItem: '[data-testid="item-message"]',
    /** Optional: add data-testid="badge-read-only" in app for explicit read-only indicator */
    badgeReadOnly: '[data-testid="badge-read-only"]',
  },
  files: {
    page: '[data-testid="page-files-upload"]',
    filePicker: '[data-testid="section-file-picker"]',
    selectButton: '[data-testid="btn-select-files"]',
    fileInput: '[data-testid="input-files"]',
    uploadButton: '[data-testid="btn-upload"]',
    table: '[data-testid="section-table"]',
    fileRow: '[data-testid="item-file"]',
    emptyState: '[data-testid="state-empty"]',
  },
  fileSelection: {
    modal: '[data-testid="modal-file-selection"]',
    uploadButton: '[data-testid="btn-file-selection-upload"]',
    attachButton: '[data-testid="btn-file-selection-attach"]',
  },
  userMenu: {
    button: '[data-testid="btn-sidebar-v2-user"]',
    logoutBtn: '[data-testid="btn-sidebar-v2-logout"]',
  },
  oidc: {
    keycloakButton: '[data-testid="btn-social-keycloak"]',
    keycloakUsername: '#username',
    keycloakPassword: '#password',
    keycloakSubmit: '#kc-login',
    redirectSection: '[data-testid="section-oidc-redirect"]',
    sessionExpiredSection: '[data-testid="section-oidc-session-expired"]',
  },
  loggedOut: {
    page: '[data-testid="page-logged-out"]',
    loginAgainBtn: '[data-testid="btn-login-again"]',
  },
  widgets: {
    page: '[data-testid="page-widgets"]',
    createButton: '[data-testid="btn-create-widget"]',
    simpleForm: {
      modal: '[data-testid="modal-simple-widget-form"]',
      nameInput: '[data-testid="input-widget-name"]',
      websiteInput: '[data-testid="input-website-url"]',
      taskPromptInput: '[data-testid="input-task-prompt"]',
      createButton: '[data-testid="btn-create"]',
      cancelButton: '[data-testid="btn-cancel"]',
    },
    successModal: {
      modal: '[data-testid="modal-widget-success"]',
      embedCode: '[data-testid="section-embed-code"]',
      testButton: '[data-testid="btn-test-widget"]',
      closeButton: '[data-testid="btn-close"]',
    },
    widgetCard: {
      item: '[data-testid="item-widget"]',
      embedButton: '[data-testid="btn-widget-embed"]',
      testButton: '[data-testid="btn-widget-test"]',
      advancedButton: '[data-testid="btn-widget-advanced"]',
    },
    testChatOverlay: '[data-testid="test-chat-overlay"]',
    advancedConfig: {
      modal: '[data-testid="modal-advanced-config"]',
      tabButton: '[data-testid="btn-tab"]',
      tabButtonBranding: '[data-testid="btn-tab-branding"]',
      tabButtonBehavior: '[data-testid="btn-tab-behavior"]',
      tabButtonSecurity: '[data-testid="btn-tab-security"]',
      tabButtonAssistant: '[data-testid="btn-tab-assistant"]',
      behaviorTab: '[data-testid="section-behavior"]',
      securityTab: '[data-testid="section-security"]',
      assistantTab: '[data-testid="section-assistant"]',
      allowFileUploadLabel: '[data-testid="label-allow-file-upload"]',
      allowFileUploadCheckbox: '[data-testid="input-allow-file-upload"]',
      autoMessageInput: '[data-testid="input-auto-message"]',
      messageLimitInput: '[data-testid="input-message-limit"]',
      maxFileSizeInput: '[data-testid="input-max-file-size"]',
      promptContentInput: '[data-testid="input-prompt-content"]',
      selectionRulesInput: '[data-testid="input-selection-rules"]',
      domainInput: '[data-testid="input-domain"]',
      addDomainButton: '[data-testid="btn-add-domain"]',
      saveButton: '[data-testid="btn-save"]',
      closeButton: '[data-testid="btn-close"]',
    },
  },
  widget: {
    // Shadow DOM Host: The widget creates a shadow root inside this element
    // Use .locator() to access elements inside Shadow DOM
    host: '[data-testid="widget-host"]',
    // Button is attached directly to body (not in Shadow DOM) for easier styling/positioning
    button: '#synaplan-widget-button',
    // Elements inside Shadow DOM - must be accessed via host.locator()
    chatWindow: '[data-testid="section-chat-window"]',
    messagesContainer: '[data-testid="section-messages"]',
    input: '[data-testid="input-message"]',
    sendButton: '[data-testid="btn-send"]',
    attachButton: '[data-testid="btn-attach"]',
    fileInput: '[data-testid="input-file"]',
    // Outer message containers (user + assistant) – use these for counting
    messageContainers: '[data-testid="message-user"], [data-testid="message-assistant"]',
    messageContainerByRole: (role: string) => `[data-testid="message-${role}"]`,
    // Message text selectors
    messageAiText: '[data-testid="message-ai-text"]',
    messageAutoText: '[data-testid="message-auto-text"]',
    messageUserText: '[data-testid="message-user-text"]',
    /** E2E: present in assistant bubble when streaming finished */
    messageDone: '[data-testid="message-done"]',
    // Error and warning selectors
    warningMessageLimit: '[data-testid="warning-message-limit"]',
    errorMessageLimitReached: '[data-testid="error-message-limit-reached"]',
    errorFileUpload: '[data-testid="error-file-upload"]',
    errorFileUploadLimit: '[data-testid="error-file-upload-limit"]',
    errorFileSize: '[data-testid="error-file-size"]',
    removeFileButton: (index: number) => `[data-testid="btn-remove-file-${index}"]`,
  },
  toast: {},
} as const
