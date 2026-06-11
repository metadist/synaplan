/** Global notification toasts (useNotification / showError) */
const notificationError = '[data-testid="comp-notification-item"][data-notification-type="error"]'

export const selectors = {
  notification: {
    /** Error toast – use for fail-fast when racing with success state */
    error: notificationError,
  },
  login: {
    email: '#email',
    password: '#password',
    submit: 'button[type="submit"]',
    signUpLink: '[data-testid="link-signup"]',
    errorAlert: '[data-testid="alert-login-error"]',
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
  /**
   * The in-app header used to host mode/theme/language toggles, but those
   * moved to the Settings page and the header is now mobile-only with just
   * a sidebar burger. The toggles below still render on the unauthenticated
   * Register/VerifyEmail pages.
   */
  authPageToggles: {
    themeToggle: '[data-testid="btn-theme-toggle"]',
    languageToggle: '[data-testid="btn-language-toggle"]',
  },
  settings: {
    page: '[data-testid="page-settings"]',
    appModeSection: '[data-testid="section-app-mode"]',
    btnModeEasy: '[data-testid="btn-mode-easy"]',
    btnModeAdvanced: '[data-testid="btn-mode-advanced"]',
    languageSection: '[data-testid="section-language-settings"]',
    languageGrid: '[data-testid="grid-language-options"]',
    btnLanguage: (lang: string) => `[data-testid="btn-language-${lang}"]`,
    themeSection: '[data-testid="section-theme-settings"]',
    btnThemeLight: '[data-testid="btn-theme-light"]',
    btnThemeDark: '[data-testid="btn-theme-dark"]',
    btnThemeSystem: '[data-testid="btn-theme-system"]',
  },
  nav: {
    sidebar: '[data-testid="comp-sidebar-v2"]',
    navDropdown: '[data-testid="dropdown-sidebar-v2-nav"]',
    /** Expand sidebar when collapsed (so chat dropdown is visible) */
    sidebarExpand: '[data-testid="btn-sidebar-expand"]',
    /** V2 sidebar: single plus button to start new chat (no toggle/dropdown) */
    sidebarV2NewChat: '[data-testid="btn-sidebar-v2-new-chat"]',
    /**
     * V2 sidebar nav testids use STABLE KEYS (`btn-sidebar-v2-nav-<key>`,
     * `link-sidebar-v2-<key>`) — decoupled from route paths so URL migrations
     * never rename selectors (navigation IA cleanup, phase 0.5).
     */
    /** V2 sidebar: History nav item opens the chat list modal */
    sidebarV2ChatNav: '[data-testid="btn-sidebar-v2-nav-chat"]',
    /** V2 sidebar: files nav icon */
    sidebarV2Files: '[data-testid="btn-sidebar-v2-nav-files"]',
    /** V2 sidebar: Channels rail item (locked in easy mode, flyout in advanced) */
    sidebarV2Channels: '[data-testid="btn-sidebar-v2-nav-channels"]',
    /** V2 sidebar: AI Setup rail item (locked in easy mode, flyout in advanced) */
    sidebarV2AiSetup: '[data-testid="btn-sidebar-v2-nav-ai-setup"]',
    /** V2 sidebar: admin nav icon (admin only) */
    sidebarV2Admin: '[data-testid="btn-sidebar-v2-nav-admin"]',
    /** V2 rail: always-visible label node inside each nav button (§4.1 #3) */
    railLabel: '.v2-rail-label',
    /** V2 flyout: child links (stable keys) */
    flyoutLinkInbound: '[data-testid="link-sidebar-v2-inbound"]',
    flyoutLinkChatWidget: '[data-testid="link-sidebar-v2-chat-widget"]',
    flyoutLinkMailHandler: '[data-testid="link-sidebar-v2-mail-handler"]',
    flyoutLinkApiDocs: '[data-testid="link-sidebar-v2-api-docs"]',
    flyoutLinkAiModels: '[data-testid="link-sidebar-v2-ai-models"]',
    flyoutLinkTaskPrompts: '[data-testid="link-sidebar-v2-task-prompts"]',
    flyoutLinkAdminDashboard: '[data-testid="link-sidebar-v2-admin-dashboard"]',
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
    /** Empty-state for a new/empty chat (shown while messages.length === 0 && !isLoadingMessages). Use to assert that a fresh chat is fully committed before counting bubbles. */
    stateEmpty: '[data-testid="state-empty"]',
    /** Terminal: present when streaming finished */
    chatDone: '[data-testid="message-done"]',
    /** Terminal: present when message ended in error */
    chatError: '[data-testid="message-topic-error"]',
    messageUser: '[data-testid="message-user"]',
    messageAssistant: '[data-testid="message-assistant"]',
    /** Present inside assistant bubble when streaming finished (prefer over loader hidden) */
    messageDone: '[data-testid="message-done"]',
    loadIndicator: '[data-testid="loading-typing-indicator"]',
    /** Fallback typing-dots shown before the first SSE status event arrives (issue #902) */
    loadIndicatorInitial: '[data-testid="loading-initial-indicator"]',
    /** Wrapper that contains only the generated answer body (no timestamp, no footer). Use this for asserting reply text. */
    assistantAnswerBody: '[data-testid="section-message-text"]',
    messageText: '[data-testid="message-text"]',
    /** Present when message topic is ERROR (backend error path); use to assert no error in bubble */
    messageTopicError: '[data-testid="message-topic-error"]',
    // The "Again with… ▾" control is a single button that opens the model
    // dropdown; picking a model re-runs the prompt. (Previously a split button +
    // separate toggle — now unified, so both aliases point to the same element.)
    againBtn: '[data-testid="btn-message-again"]',
    againDropdown: '[data-testid="btn-message-again"]',
    againDropdownPanel: '[data-testid="dropdown-again-models"]',
    againDropdownItem: 'button.dropdown-item',
  },
  share: {
    shareButton: '[data-testid="btn-chat-share"]',
    shareModal: '[data-testid="modal-chat-share"]',
    modalRoot: '[data-testid="modal-chat-share-root"]',
    shareCreate: '[data-testid="btn-chat-share-make-public"]',
    shareLinkInput: '[data-testid="share-link-input"]',
    shareDone: '[data-testid="share-done"]',
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
    uploadForm: '[data-testid="section-upload-form"]',
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
    dropdown: '[data-testid="dropdown-sidebar-v2-user"]',
    profileBtn: '[data-testid="btn-sidebar-v2-profile"]',
    /** Avatar menu entry for the /settings page — labeled "Preferences" since phase 2 */
    preferencesBtn: '[data-testid="btn-sidebar-v2-preferences"]',
    statisticsBtn: '[data-testid="btn-sidebar-v2-statistics"]',
    subscriptionBtn: '[data-testid="btn-sidebar-v2-subscription"]',
    upgradeBtn: '[data-testid="btn-sidebar-v2-upgrade"]',
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
    detailPage: {
      settingsButton: '[data-testid="btn-widget-settings"]',
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
  subscription: {
    page: '[data-testid="page-subscription"]',
    sectionCurrentPlan: '[data-testid="section-current-plan"]',
    badgeCurrentLevel: '[data-testid="badge-current-level"]',
    badgeStatus: '[data-testid="badge-subscription-status"]',
    btnOpenPortal: '[data-testid="btn-open-portal"]',
    cardPlan: '[data-testid="card-plan"]',
    btnSelectPro: '[data-testid="btn-select-pro"]',
    btnSelectTeam: '[data-testid="btn-select-team"]',
    btnSelectBusiness: '[data-testid="btn-select-business"]',
    /** Visible inside section-current-plan when subscription is scheduled to cancel at period end */
    textCancelDate: '[data-testid="text-cancel-date"]',
    /** Visible inside section-current-plan during normal active periods (mutually exclusive with textCancelDate) */
    textNextBilling: '[data-testid="text-next-billing"]',
    /** Visible above section-current-plan when paymentFailed=true OR status='past_due' (issue #856) */
    sectionPaymentFailed: '[data-testid="section-payment-failed"]',
    /** Inside sectionPaymentFailed — opens the Stripe customer portal so the user can update their card */
    btnFixPayment: '[data-testid="btn-fix-payment"]',
  },
  subscriptionSuccess: {
    page: '[data-testid="page-subscription-success"]',
    stateSyncing: '[data-testid="state-syncing"]',
    stateSyncSuccess: '[data-testid="state-sync-success"]',
    stateSyncError: '[data-testid="state-sync-error"]',
    textNewLevel: '[data-testid="text-new-level"]',
  },
  taskPrompts: {
    page: '[data-testid="page-config-task-prompts"]',
    overview: '[data-testid="section-task-prompts-overview"]',
    list: '[data-testid="section-task-prompts-list"]',
    cards: '[data-testid="section-task-prompt-cards"]',
    cardAny: '[data-testid^="card-prompt-"]',
    /** @deprecated retained as a hidden compat element for legacy automation; prefer `cardAny`. */
    promptSelect: '[data-testid="input-prompt-select"]',
    promptSearch: '[data-testid="input-prompt-search"]',
    filterAll: '[data-testid="filter-all"]',
    filterSystem: '[data-testid="filter-system"]',
    filterCustom: '[data-testid="filter-custom"]',
    statTotal: '[data-testid="stat-total"]',
    statSystem: '[data-testid="stat-system"]',
    statCustom: '[data-testid="stat-custom"]',
    promptHeader: '[data-testid="section-prompt-header"]',
    tabRouting: '[data-testid="tab-routing"]',
    tabPrompt: '[data-testid="tab-prompt"]',
    tabKnowledge: '[data-testid="tab-knowledge"]',
    tabDanger: '[data-testid="tab-danger"]',
    promptDetails: '[data-testid="section-prompt-details"]',
    promptConfig: '[data-testid="section-prompt-config"]',
    aiModel: '[data-testid="input-ai-model"]',
    rules: '[data-testid="input-rules"]',
    description: '[data-testid="input-description"]',
    content: '[data-testid="input-content"]',
    btnCreate: '[data-testid="btn-create-prompt"]',
    btnDelete: '[data-testid="btn-delete"]',
    cardForTopic: (topic: string) => `[data-testid="card-prompt-${topic}"]`,
  },
  pages: {
    chat: '[data-testid="page-chat"]',
    profile: '[data-testid="page-profile"]',
    statistics: '[data-testid="page-statistics"]',
    admin: '[data-testid="view-admin"]',
    tools: '[data-testid="page-tools"]',
  },
  dialog: {
    confirmBtn: '[data-testid="btn-dialog-confirm"]',
    cancelBtn: '[data-testid="btn-dialog-cancel"]',
  },
  guest: {
    banner: '[data-testid="guest-banner"]',
    bannerSignup: '[data-testid="guest-banner-signup"]',
    bannerDismiss: '[data-testid="guest-banner-dismiss"]',
    signupModal: '[data-testid="guest-signup-modal"]',
    modalRegister: '[data-testid="guest-modal-register"]',
    modalLogin: '[data-testid="guest-modal-login"]',
  },
  mailHandler: {
    list: '[data-testid="comp-mail-handler-list"]',
    config: '[data-testid="comp-mail-handler-config"]',
    createBtn: '[data-testid="btn-create-handler"]',
    handlerCard: (id: string) => `[data-testid="card-handler-${id}"]`,
    anyHandlerCard: '[data-testid^="card-handler-"]',
    handlerName: '[data-testid="text-handler-name"]',
    deleteBtn: (id: string) => `[data-testid="btn-delete-handler-${id}"]`,
    anyDeleteBtn: '[data-testid^="btn-delete-handler-"]',
    inputName: '[data-testid="input-handler-name"]',
    inputMailServer: '[data-testid="input-mail-server"]',
    inputPort: '[data-testid="input-port"]',
    inputProtocol: '[data-testid="input-protocol"]',
    inputSecurity: '[data-testid="input-security"]',
    inputUsername: '[data-testid="input-username"]',
    inputPassword: '[data-testid="input-password"]',
    inputSmtpServer: '[data-testid="input-smtp-server"]',
    inputSmtpPort: '[data-testid="input-smtp-port"]',
    inputSmtpSecurity: '[data-testid="input-smtp-security"]',
    inputSmtpUsername: '[data-testid="input-smtp-username"]',
    inputSmtpPassword: '[data-testid="input-smtp-password"]',
    inputFilterNew: '[data-testid="input-filter-new"]',
    sectionDepartments: '[data-testid="section-step-departments"]',
    btnAdd: '[data-testid="btn-add"]',
    inputDeptEmail: '[data-testid="input-dept-email"]',
    inputDeptRules: '[data-testid="input-dept-rules"]',
    btnNext: '[data-testid="btn-next"]',
    btnPrev: '[data-testid="btn-prev"]',
    btnSave: '[data-testid="btn-save"]',
    btnClose: '[data-testid="btn-close"]',
  },
  toast: {},
} as const
