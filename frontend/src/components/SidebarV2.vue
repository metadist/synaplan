<template>
  <!--
    Desktop navigation rail (§4.3 #1). Hidden on phone chrome (narrow or
    short / landscape-phone viewports — see usePhoneChrome.ts); the push-drawer
    is the primary navigation there.
  -->
  <aside class="v2-sidebar-rail v2-desktop-chrome flex-col" data-testid="comp-sidebar-v2">
    <!-- Brand logo -->
    <div
      class="flex flex-col items-center justify-center flex-shrink-0 border-b border-white/[0.04] h-[76px]"
    >
      <img
        :src="iconSrc"
        :alt="configStore.branding.name"
        class="h-7 w-auto"
        data-testid="img-sidebar-brand"
      />
    </div>

    <!-- New Chat Button -->
    <div class="flex items-center justify-center py-3 flex-shrink-0">
      <button
        class="v2-new-chat-btn w-[72px] min-h-[48px] flex flex-col items-center justify-center gap-0.5 py-1.5 rounded-xl transition-all duration-200"
        :class="{ 'v2-new-chat-btn--creating': isCreatingChat }"
        :title="$t('nav.newDescription')"
        :disabled="isCreatingChat"
        data-testid="btn-sidebar-v2-new-chat"
        @click="handleQuickNewChat"
      >
        <Icon
          v-if="isCreatingChat"
          icon="mdi:loading"
          class="w-6 h-6 animate-spin"
          aria-hidden="true"
        />
        <PlusIcon v-else class="w-6 h-6" aria-hidden="true" />
        <span class="v2-rail-label text-[10px] font-medium leading-tight">
          {{ $t('nav.new') }}
        </span>
      </button>
    </div>

    <!-- Nav Icons -->
    <nav class="flex-1 flex flex-col items-center gap-1 py-1 overflow-y-auto sidebar-scroll">
      <button
        v-for="item in navItems"
        :key="item.path"
        :ref="(el) => setNavBtnRef(el, item.path)"
        :class="[
          'v2-rail-icon w-[72px] min-h-[48px] flex flex-col items-center justify-center gap-0.5 py-1.5 relative',
          isItemActive(item) && 'v2-rail-icon--active',
          item.isUpgrade && 'text-amber-500 dark:text-amber-400',
          item.requiresAuth && isGuestMode && 'opacity-60',
        ]"
        :title="item.description || item.label"
        :data-testid="`btn-sidebar-v2-nav-${item.key}`"
        :aria-haspopup="item.children?.length ? 'menu' : undefined"
        :aria-expanded="
          item.children?.length
            ? activeFlyout === 'nav' && activeFlyoutItem?.key === item.key
            : undefined
        "
        @click="handleNavClick(item)"
      >
        <component :is="item.icon" class="w-6 h-6 flex-shrink-0" aria-hidden="true" />
        <!-- Two-line clamp instead of truncate: longer rail labels (and their
             translations) would otherwise be cut off on the 72px rail. -->
        <span
          class="v2-rail-label text-[10px] font-medium leading-tight max-w-full px-0.5 text-center line-clamp-2 break-words"
        >
          {{ item.label }}
        </span>
      </button>
    </nav>

    <!-- Upgrade Button -->
    <div
      v-if="
        !isGuestMode &&
        !authStore.isAdmin &&
        configStore.billing.enabled &&
        purchaseAllowed &&
        !authStore.isPro
      "
      class="flex items-center justify-center py-2 flex-shrink-0"
    >
      <button
        class="v2-upgrade-btn w-[72px] min-h-[48px] flex flex-col items-center justify-center gap-0.5 py-1.5 rounded-xl"
        :title="$t('nav.upgrade')"
        data-testid="btn-sidebar-v2-upgrade"
        @click="handleNavigate('/subscription')"
      >
        <RocketLaunchIcon class="w-6 h-6" aria-hidden="true" />
        <span class="v2-rail-label text-[10px] font-medium leading-tight">
          {{ $t('nav.upgrade') }}
        </span>
      </button>
    </div>

    <!--
      Running release, for every user. Admins with a pending release get a link
      to the manual-update guide instead — Synaplan never updates itself, the
      operator does, so this is a pointer to documentation and nothing else.
    -->
    <div
      v-if="versionLabel"
      class="flex items-center justify-center pt-2 flex-shrink-0"
      data-testid="section-sidebar-v2-version"
    >
      <a
        v-if="showUpdateLink"
        :href="updatesStore.guideUrl ?? undefined"
        target="_blank"
        rel="noopener noreferrer"
        :class="[
          'inline-flex items-center gap-1 max-w-[72px] px-2 py-0.5 rounded-full text-[10px] font-semibold transition-opacity hover:opacity-80',
          isSecurityUpdate
            ? 'bg-[var(--status-error-muted)] text-[var(--status-error-text)]'
            : 'bg-[var(--status-warning-muted)] text-[var(--status-warning-text)]',
        ]"
        :title="updateHint"
        :aria-label="updateHint"
        data-testid="link-sidebar-v2-update"
      >
        <ArrowUpCircleIcon class="w-3.5 h-3.5 flex-shrink-0" aria-hidden="true" />
        <span class="truncate">{{ versionLabel }}</span>
      </a>
      <span
        v-else
        class="text-[10px] txt-secondary"
        :title="$t('updates.runningVersion', { version: versionLabel })"
        data-testid="text-sidebar-v2-version"
      >
        {{ versionLabel }}
      </span>
    </div>

    <!-- User Avatar -->
    <div class="flex items-center justify-center py-4 flex-shrink-0">
      <button
        ref="userBtnRef"
        class="v2-rail-icon w-[72px] min-h-[48px] flex flex-col items-center justify-center gap-0.5 py-1.5"
        :title="authStore.user?.email || $t('nav.accountDescription')"
        data-testid="btn-sidebar-v2-user"
        @click="toggleUserMenu"
      >
        <div
          class="w-8 h-8 rounded-full surface-chip flex items-center justify-center text-xs font-semibold"
        >
          {{ initials }}
        </div>
        <span class="v2-rail-label text-[10px] font-medium leading-tight">
          {{ $t('nav.account') }}
        </span>
      </button>
    </div>
  </aside>

  <!-- User Dropdown (teleported to #app to escape local stacking context) -->
  <Teleport to="#app">
    <Transition
      enter-active-class="transition ease-out duration-150"
      enter-from-class="opacity-0 scale-95"
      enter-to-class="opacity-100 scale-100"
      leave-active-class="transition ease-in duration-100"
      leave-from-class="opacity-100 scale-100"
      leave-to-class="opacity-0 scale-95"
    >
      <div
        v-if="userMenuOpen"
        class="fixed inset-0 z-[200]"
        data-testid="overlay-sidebar-v2-user"
        @click="userMenuOpen = false"
      >
        <div
          role="menu"
          class="fixed w-52 dropdown-panel origin-bottom-left"
          :style="userDropdownStyle"
          data-testid="dropdown-sidebar-v2-user"
          @click.stop
        >
          <!-- Guest user menu -->
          <template v-if="isGuestMode">
            <div class="px-3 py-2 border-b border-light-border/10 dark:border-dark-border/10">
              <p class="text-xs font-medium txt-secondary">
                {{ $t('guest.banner.title') }}
              </p>
            </div>
            <router-link
              v-if="configStore.auth.registrationEnabled"
              to="/register"
              class="dropdown-item font-medium"
              style="color: var(--brand)"
              data-testid="btn-sidebar-v2-guest-register"
              @click="userMenuOpen = false"
            >
              <Icon icon="mdi:account-plus-outline" class="w-4 h-4" />
              <span>{{ $t('guest.featureGate.registerButton') }}</span>
            </router-link>
            <router-link
              to="/login"
              class="dropdown-item"
              data-testid="btn-sidebar-v2-guest-login"
              @click="userMenuOpen = false"
            >
              <ArrowRightOnRectangleIcon class="w-4 h-4" />
              <span>{{ $t('auth.signIn') }}</span>
            </router-link>
          </template>

          <!-- Authenticated user menu -->
          <template v-else>
            <div class="px-3 py-2 border-b border-light-border/10 dark:border-dark-border/10">
              <p class="text-xs font-medium txt-primary truncate">
                {{ authStore.user?.email || '' }}
              </p>
            </div>
            <button
              role="menuitem"
              class="dropdown-item"
              data-testid="btn-sidebar-v2-profile"
              @click="handleProfileSettings"
            >
              <UserCircleIcon class="w-4 h-4" />
              <span>{{ $t('nav.profile') }}</span>
            </button>
            <button
              v-if="isMemoryServiceAvailable"
              role="menuitem"
              class="dropdown-item"
              :class="{ 'opacity-60': !memoriesEnabledForUser }"
              data-testid="btn-sidebar-v2-memories"
              @click="handleOpenMemories"
            >
              <Icon icon="mdi:brain" class="w-4 h-4" />
              <span>{{ $t('pageTitles.memories') }}</span>
              <Icon
                v-if="!memoriesEnabledForUser"
                icon="mdi:lock"
                class="w-3.5 h-3.5 ml-auto text-orange-500 dark:text-orange-400"
              />
            </button>
            <div class="border-t border-light-border/10 dark:border-dark-border/10">
              <button
                role="menuitem"
                class="dropdown-item"
                data-testid="btn-sidebar-v2-statistics"
                @click="handleNavigate('/statistics')"
              >
                <ChartBarIcon class="w-4 h-4" />
                <span>{{ $t('nav.statistics') }}</span>
              </button>
              <button
                role="menuitem"
                class="dropdown-item"
                data-testid="btn-sidebar-v2-feedback"
                @click="handleNavigate('/feedbacks')"
              >
                <Icon icon="mdi:comment-quote-outline" class="w-4 h-4" />
                <span>{{ $t('pageTitles.feedback') }}</span>
              </button>
              <button
                v-if="
                  !authStore.isAdmin &&
                  configStore.billing.enabled &&
                  purchaseAllowed &&
                  authStore.isPro
                "
                role="menuitem"
                class="dropdown-item"
                data-testid="btn-sidebar-v2-subscription"
                @click="handleNavigate('/subscription')"
              >
                <CreditCardIcon class="w-4 h-4" />
                <span>{{ $t('nav.subscription') }}</span>
              </button>
            </div>
          </template>

          <!--
            Preferences holds the language and the theme, both stored on the
            device rather than on the account, so it stays outside the
            guest/authenticated split and is offered to everyone.
          -->
          <div class="border-t border-light-border/10 dark:border-dark-border/10">
            <button
              role="menuitem"
              class="dropdown-item"
              data-testid="btn-sidebar-v2-preferences"
              @click="handleNavigate('/settings')"
            >
              <Cog6ToothIcon class="w-4 h-4" />
              <span>{{ $t('nav.preferences') }}</span>
            </button>
          </div>

          <!--
            Logout is intentionally hidden while impersonating: clicking
            it would clear the admin's session entirely (cookies + stash)
            instead of just ending the impersonation, which is almost
            never what the operator means. The "Exit" button on the
            floating impersonation pill is the correct action here.
          -->
          <div
            v-if="!isGuestMode && !isImpersonating"
            class="border-t border-light-border/10 dark:border-dark-border/10"
          >
            <button
              role="menuitem"
              class="dropdown-item text-red-500 dark:text-red-400"
              data-testid="btn-sidebar-v2-logout"
              @click="handleLogout"
            >
              <ArrowRightOnRectangleIcon class="w-4 h-4" />
              <span>{{ $t('settings.logout') }}</span>
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>

  <!-- Nav Children Dropdown (teleported to #app to escape local stacking context) -->
  <Teleport to="#app">
    <Transition
      enter-active-class="transition ease-out duration-150"
      enter-from-class="opacity-0 scale-95"
      enter-to-class="opacity-100 scale-100"
      leave-active-class="transition ease-in duration-100"
      leave-from-class="opacity-100 scale-100"
      leave-to-class="opacity-0 scale-95"
    >
      <SidebarNavFlyout
        v-if="activeFlyout === 'nav' && activeFlyoutItem"
        :item="activeFlyoutItem"
        :panel-style="navDropdownStyle"
        :current-path="route.path"
        @close="closeFlyout"
      />
    </Transition>
  </Teleport>

  <!-- Chat Management Modal -->
  <Teleport to="#app">
    <Transition name="modal">
      <div
        v-if="chatModalOpen"
        class="modal-overlay fixed inset-0 z-[100] flex items-end sm:items-center justify-center bg-black/40 backdrop-blur-sm"
        data-testid="modal-chat-manager-backdrop"
        @click.self="chatModalOpen = false"
      >
        <div
          class="modal-panel w-full sm:max-w-xl flex flex-col rounded-t-2xl sm:rounded-2xl shadow-2xl overflow-hidden bg-white/95 dark:bg-[#0e1628]/95 backdrop-blur-xl border-t sm:border border-white/20 dark:border-white/[0.08] sm:m-4"
          data-testid="modal-chat-manager"
          @click.stop
        >
          <!-- Mobile drag handle -->
          <div class="sm:hidden flex justify-center pt-2 pb-1">
            <div class="w-10 h-1 rounded-full bg-black/10 dark:bg-white/10" />
          </div>

          <!-- Header -->
          <div class="flex-shrink-0 px-4 pt-3 pb-3 sm:px-6 sm:pt-6 sm:pb-4">
            <div class="flex items-center justify-between mb-4">
              <div class="flex items-center gap-3">
                <div
                  class="w-9 h-9 rounded-xl bg-[var(--brand)]/10 flex items-center justify-center"
                >
                  <ChatBubbleLeftRightIcon class="w-5 h-5 text-[var(--brand)]" />
                </div>
                <div>
                  <h2 class="text-lg font-bold txt-primary leading-tight">
                    {{ $t('chat.recent') }}
                  </h2>
                  <p class="text-xs txt-secondary mt-0.5">
                    {{ chatList.length }}
                    {{ chatList.length === 1 ? $t('chat.conversation') : $t('chat.conversations') }}
                  </p>
                </div>
              </div>
              <button
                class="w-8 h-8 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center transition-colors txt-secondary"
                @click="chatModalOpen = false"
              >
                <Icon icon="mdi:close" class="w-5 h-5" />
              </button>
            </div>

            <!-- Search + New Chat Row -->
            <div class="flex items-center gap-2">
              <div class="flex-1 relative">
                <Icon
                  icon="mdi:magnify"
                  class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 txt-secondary pointer-events-none"
                />
                <input
                  v-model="chatSearchQuery"
                  type="text"
                  class="w-full pl-9 pr-3 py-2 text-sm rounded-xl bg-black/[0.04] dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/[0.06] txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/30 focus:border-[var(--brand)]/40 transition-all"
                  :placeholder="$t('chat.browser.searchPlaceholder')"
                />
              </div>
              <button
                class="flex-shrink-0 flex items-center gap-1.5 px-4 py-2 rounded-xl btn-primary text-sm font-medium transition-all hover:shadow-lg hover:shadow-brand/20"
                :disabled="isCreatingChat"
                data-testid="btn-chat-modal-new"
                @click="handleNewChat"
              >
                <Icon
                  :icon="isCreatingChat ? 'mdi:loading' : 'mdi:plus'"
                  :class="['w-4 h-4', isCreatingChat && 'animate-spin']"
                />
                <span class="hidden sm:inline">{{ $t('chat.newChat') }}</span>
              </button>
            </div>
          </div>

          <!-- Chat List -->
          <div class="flex-1 overflow-y-auto scroll-thin px-3 pb-4 sm:px-4">
            <!-- Empty State -->
            <div
              v-if="filteredChatList.length === 0 && chatSearchQuery"
              class="flex flex-col items-center justify-center py-10 gap-3"
            >
              <div
                class="w-12 h-12 rounded-2xl bg-black/[0.04] dark:bg-white/[0.04] flex items-center justify-center"
              >
                <Icon icon="mdi:chat-question-outline" class="w-6 h-6 txt-secondary" />
              </div>
              <p class="text-sm txt-secondary">{{ $t('common.noResults') }}</p>
            </div>
            <div
              v-else-if="filteredChatList.length === 0"
              class="flex flex-col items-center justify-center py-10 gap-3"
            >
              <div
                class="w-14 h-14 rounded-2xl bg-[var(--brand)]/[0.06] flex items-center justify-center"
              >
                <ChatBubbleLeftRightIcon class="w-7 h-7 text-[var(--brand)]/60" />
              </div>
              <div class="text-center">
                <p class="text-sm font-medium txt-primary">{{ $t('chat.noChats') }}</p>
                <p class="text-xs txt-secondary mt-1">{{ $t('chatInput.placeholder') }}</p>
              </div>
            </div>

            <!-- Chat Cards -->
            <div v-else class="space-y-1.5" role="list" data-testid="list-chat-manager-rows">
              <div
                v-for="chat in filteredChatList"
                :key="chat.id"
                role="listitem"
                class="group/chat relative flex items-center gap-3 px-3.5 py-3 rounded-xl cursor-pointer transition-all duration-150 active:scale-[0.99]"
                data-testid="row-chat-v2"
                :class="
                  chat.id === chatsStore.activeChatId
                    ? 'bg-[var(--brand)]/[0.08] ring-1 ring-[var(--brand)]/20'
                    : 'hover:bg-black/[0.03] dark:hover:bg-white/[0.03]'
                "
                @click="handleChatSelect(chat.id)"
              >
                <!-- Channel Indicator -->
                <div
                  class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 transition-colors"
                  :class="
                    chat.id === chatsStore.activeChatId
                      ? 'bg-[var(--brand)]/15'
                      : 'bg-black/[0.04] dark:bg-white/[0.04] group-hover/chat:bg-black/[0.06] dark:group-hover/chat:bg-white/[0.06]'
                  "
                >
                  <Icon
                    v-if="getChannelIcon(chat)"
                    :icon="getChannelIcon(chat)!"
                    class="w-4.5 h-4.5"
                    :class="getChannelIconClass(chat)"
                  />
                  <ChatBubbleLeftRightIcon
                    v-else
                    class="w-4.5 h-4.5"
                    :class="
                      chat.id === chatsStore.activeChatId
                        ? 'text-[var(--brand)]'
                        : 'txt-secondary opacity-60'
                    "
                  />
                </div>

                <!-- Content -->
                <div class="flex-1 min-w-0">
                  <p
                    class="text-[13px] leading-snug truncate"
                    :class="
                      chat.id === chatsStore.activeChatId
                        ? 'font-semibold text-[var(--brand)]'
                        : 'font-medium txt-primary'
                    "
                  >
                    {{ getDisplayTitle(chat) }}
                  </p>
                  <div class="flex items-center gap-2 mt-0.5">
                    <span class="text-[11px] txt-secondary">{{
                      formatTimestamp(chat.createdAt)
                    }}</span>
                    <span v-if="chat.messageCount" class="text-[11px] txt-secondary opacity-60">
                      · {{ chat.messageCount }} msg
                    </span>
                    <span
                      v-if="chat.isShared"
                      class="text-[11px] text-[var(--brand)] flex items-center gap-0.5"
                    >
                      <Icon icon="mdi:link-variant" class="w-3 h-3" />
                    </span>
                    <span
                      v-if="isGenerating(chat)"
                      class="text-[11px] text-[var(--brand)] flex items-center gap-1"
                      :title="$t('chat.stillGenerating')"
                      data-testid="indicator-chat-active-run"
                    >
                      <span class="w-1.5 h-1.5 rounded-full bg-[var(--brand)] animate-pulse" />
                      <span class="sr-only">{{ $t('chat.stillGenerating') }}</span>
                    </span>
                  </div>
                </div>

                <!-- Actions -->
                <div class="flex-shrink-0" @click.stop>
                  <button
                    class="w-9 h-9 sm:w-8 sm:h-8 rounded-lg flex items-center justify-center sm:opacity-0 sm:group-hover/chat:opacity-100 focus:opacity-100 hover:bg-black/5 dark:hover:bg-white/5 transition-all"
                    :class="chatMenuOpenId === chat.id && '!opacity-100 bg-black/5 dark:bg-white/5'"
                    data-testid="btn-chat-v2-row-menu"
                    @click="toggleChatMenu(chat.id, $event)"
                  >
                    <Icon icon="mdi:dots-horizontal" class="w-4.5 h-4.5 txt-secondary" />
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer -->
          <div
            v-if="chatList.length > 5"
            class="flex-shrink-0 px-4 py-3 sm:px-5 border-t border-black/[0.04] dark:border-white/[0.04]"
          >
            <button
              class="w-full flex items-center justify-center gap-2 px-4 py-2.5 sm:py-2 rounded-xl text-sm sm:text-xs font-medium text-[var(--brand)] bg-[var(--brand)]/[0.06] hover:bg-[var(--brand)]/[0.12] active:bg-[var(--brand)]/[0.18] transition-all duration-150 group/show"
              @click="((chatModalOpen = false), $router.push('/statistics#chats'))"
            >
              <ChartBarIcon class="w-4 h-4 sm:w-3.5 sm:h-3.5 opacity-70" />
              {{ $t('chat.showAll') }}
              <Icon
                icon="mdi:arrow-right"
                class="w-4 h-4 sm:w-3.5 sm:h-3.5 transition-transform duration-150 group-hover/show:translate-x-0.5"
              />
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>

  <!-- Chat Context Menu (teleported to escape overflow clipping) -->
  <Teleport to="#app">
    <Transition
      enter-active-class="transition ease-out duration-100"
      enter-from-class="opacity-0 scale-95"
      enter-to-class="opacity-100 scale-100"
      leave-active-class="transition ease-in duration-75"
      leave-from-class="opacity-100 scale-100"
      leave-to-class="opacity-0 scale-95"
    >
      <div
        v-if="chatMenuOpenId !== null"
        class="fixed inset-0 z-[150]"
        @click="chatMenuOpenId = null"
      >
        <div class="fixed w-44 dropdown-panel origin-top-right" :style="chatMenuStyle" @click.stop>
          <button
            class="dropdown-item"
            data-testid="btn-chat-v2-share"
            @click="handleChatShare(chatMenuOpenId!)"
          >
            <Icon icon="mdi:share-variant-outline" class="w-4 h-4" />
            {{ $t('common.share') }}
          </button>
          <button
            class="dropdown-item"
            data-testid="btn-chat-v2-rename"
            @click="handleChatRename(chatMenuOpenId!)"
          >
            <Icon icon="mdi:pencil-outline" class="w-4 h-4" />
            {{ $t('common.rename') }}
          </button>
          <button
            class="dropdown-item dropdown-item--danger"
            data-testid="btn-chat-v2-delete"
            @click="handleChatDelete(chatMenuOpenId!)"
          >
            <Icon icon="mdi:delete-outline" class="w-4 h-4" />
            {{ $t('common.delete') }}
          </button>
        </div>
      </div>
    </Transition>
  </Teleport>

  <!-- Chat Share Modal -->
  <ChatShareModal
    :is-open="shareModalOpen"
    :chat-id="shareModalChatId"
    :chat-title="shareModalChatTitle"
    @close="shareModalOpen = false"
    @shared="chatsStore.loadChats()"
    @unshared="chatsStore.loadChats()"
  />

  <!-- Memories Dialog -->
  <MemoriesDialog :is-open="isMemoriesDialogOpen" @close="isMemoriesDialogOpen = false" />

  <!-- Guest hint popover -->
  <GuestHintPopover
    :is-open="featureGateOpen"
    :feature-key="featureGateKey"
    @close="featureGateOpen = false"
  />
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowUpCircleIcon,
  ChatBubbleLeftRightIcon,
  CreditCardIcon,
  PlusIcon,
  RocketLaunchIcon,
  Cog6ToothIcon,
  ChartBarIcon,
  UserCircleIcon,
  ArrowRightOnRectangleIcon,
} from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { useSidebarStore } from '../stores/sidebar'
import { matchesPhoneChrome } from '../composables/usePhoneChrome'
import { triggerHapticImpact } from '../services/api/nativeHaptics'
import { isPurchaseAllowed } from '../services/api/nativeServer'
import { useAuthStore } from '../stores/auth'
import { useConfigStore } from '../stores/config'
import { useAuth } from '../composables/useAuth'
import {
  useNavItems,
  groupNavChildren,
  hasNestedNavGroups,
  type NavItem,
} from '../composables/useNavItems'
import SidebarNavFlyout from './SidebarNavFlyout.vue'
import { useTheme } from '../composables/useTheme'
import { useBrandLogo } from '../composables/useBrandLogo'
import { useChatsStore, isDefaultChatTitle, type Chat as StoreChat } from '../stores/chats'
import { useUpdatesStore } from '../stores/updates'
import { formatRunningVersion } from '@/utils/formatRunningVersion'
import { useDialog } from '../composables/useDialog'
import { useI18n } from 'vue-i18n'
import { useDateFormat } from '@/composables/useDateFormat'
import MemoriesDialog from './MemoriesDialog.vue'
import ChatShareModal from './ChatShareModal.vue'
import GuestHintPopover from './guest/GuestHintPopover.vue'

const { t } = useI18n()
const { formatRelativeTime } = useDateFormat()
const sidebarStore = useSidebarStore()
const authStore = useAuthStore()
const configStore = useConfigStore()

// No purchase path on a custom server in the native app (store IAP only).
const purchaseAllowed = isPurchaseAllowed()
const chatsStore = useChatsStore()
const updatesStore = useUpdatesStore()
const dialog = useDialog()
const { logout, isImpersonating } = useAuth()
const { navItems, isItemActive, isGuestMode, loadFeatureStatus } = useNavItems()
const { isDark } = useTheme()
const { iconSrc } = useBrandLogo(isDark)
const route = useRoute()
const router = useRouter()
const isMemoriesDialogOpen = ref(false)
const userMenuOpen = ref(false)
const userBtnRef = ref<HTMLElement | null>(null)
const userDropdownStyle = ref<Record<string, string>>({})
const navBtnRefs = ref<Record<string, HTMLElement | null>>({})
const navDropdownStyle = ref<Record<string, string>>({})

const setNavBtnRef = (el: unknown, path: string) => {
  navBtnRefs.value[path] = el as HTMLElement | null
}
/**
 * The history sheet's open state lives in the sidebar store so the mobile
 * bottom nav can open the same sheet (the sheet itself renders here).
 */
const chatModalOpen = computed({
  get: () => sidebarStore.chatSheetOpen,
  set: (value: boolean) => {
    sidebarStore.chatSheetOpen = value
  },
})
const chatMenuOpenId = ref<number | null>(null)
const chatMenuStyle = ref<Record<string, string>>({})
const shareModalOpen = ref(false)
const shareModalChatId = ref<number | null>(null)
const shareModalChatTitle = ref('')
const isCreatingChat = ref(false)
const chatSearchQuery = ref('')

const isMemoryServiceAvailable = computed(() => configStore.features?.memoryService ?? false)
const memoriesEnabledForUser = computed(() => authStore.user?.memoriesEnabled !== false)

type FlyoutType = 'nav' | null
const activeFlyout = ref<FlyoutType>(null)
const activeFlyoutItem = ref<NavItem | null>(null)

// Whoever opens the sheet (rail or mobile nav), the list refreshes.
watch(
  () => sidebarStore.chatSheetOpen,
  (open) => {
    if (open) {
      chatSearchQuery.value = ''
      chatMenuOpenId.value = null
      chatsStore.loadChats()
    }
  }
)

onMounted(() => {
  loadFeatureStatus()
  document.addEventListener('keydown', handleEscape)
  window.addEventListener('resize', handleViewportChange)
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', handleEscape)
  window.removeEventListener('resize', handleViewportChange)
})

const handleViewportChange = () => {
  closeFlyout()
  userMenuOpen.value = false
}

const toggleUserMenu = () => {
  triggerHapticImpact('light')
  if (!userMenuOpen.value && userBtnRef.value) {
    const rect = userBtnRef.value.getBoundingClientRect()
    const dropdownHeight = 280
    const spaceBelow = window.innerHeight - rect.bottom
    const spaceAbove = rect.top

    const left = `${rect.right + 8}px`

    if (spaceBelow >= dropdownHeight || spaceBelow >= spaceAbove) {
      const top = Math.min(rect.bottom - dropdownHeight, window.innerHeight - dropdownHeight - 8)
      userDropdownStyle.value = { left, top: `${Math.max(8, top)}px` }
    } else {
      const bottom = window.innerHeight - rect.top
      userDropdownStyle.value = { left, bottom: `${Math.max(8, bottom - dropdownHeight)}px` }
    }
  }
  userMenuOpen.value = !userMenuOpen.value
}

const handleEscape = (event: KeyboardEvent) => {
  if (event.key === 'Escape') {
    if (chatModalOpen.value) {
      chatModalOpen.value = false
      chatMenuOpenId.value = null
      return
    }
    closeFlyout()
  }
}

const initials = computed(() => {
  const email = authStore.user?.email || 'G'
  return email.charAt(0).toUpperCase()
})

/**
 * Running release from the public runtime config, so every user sees it. Empty
 * while the config is still loading (or unavailable), and when the image only
 * knows a mutable tag such as `latest` — that word must never appear here.
 */
const versionLabel = computed(() => formatRunningVersion(configStore.build.version))

const showUpdateLink = computed(() => updatesStore.showBadge && !!updatesStore.guideUrl)
const isSecurityUpdate = computed(() => updatesStore.severity === 'security')
const updateHint = computed(() =>
  t(isSecurityUpdate.value ? 'updates.badge.securityHint' : 'updates.badge.availableHint', {
    version: updatesStore.latestVersion ?? '',
  })
)

// Admins only, once per session (the store caches, so navigating never refetches).
// Watched rather than read on mount because `isAdmin` only becomes true once
// /auth/me has resolved, which can be after the rail is already rendered.
watch(
  () => updatesStore.canRead,
  (canRead) => {
    if (canRead) updatesStore.ensureLoaded()
  },
  { immediate: true }
)

const handleQuickNewChat = async () => {
  if (isCreatingChat.value) return
  isCreatingChat.value = true
  closeFlyout()
  try {
    await chatsStore.findOrCreateEmptyChat()
    if (route.path !== '/') router.push('/')
    chatModalOpen.value = false
  } finally {
    setTimeout(() => {
      isCreatingChat.value = false
    }, 300)
  }
}

const featureGateOpen = ref(false)
const featureGateKey = ref('general')

const handleNavClick = (item: NavItem) => {
  userMenuOpen.value = false

  if (item.requiresAuth && isGuestMode.value) {
    featureGateKey.value = item.gateFeature || 'general'
    featureGateOpen.value = true
    return
  }

  if (item.path === '/') {
    closeFlyout()
    // On desktop chrome while chatting, the History icon toggles the persistent
    // left history panel. Everywhere else (other routes, or phone chrome where
    // there is no panel) it falls back to the modal history sheet.
    if (route.name === 'chat' && !matchesPhoneChrome()) {
      sidebarStore.toggleChatHistoryCollapsed()
    } else {
      sidebarStore.toggleChatSheet()
    }
    return
  }

  if (item.children && item.children.length > 0) {
    if (activeFlyout.value === 'nav' && activeFlyoutItem.value?.path === item.path) {
      closeFlyout()
    } else {
      const btn = navBtnRefs.value[item.path]
      if (btn) {
        const rect = btn.getBoundingClientRect()
        const groups = groupNavChildren(item.children)
        const rowCount = hasNestedNavGroups(item.children) ? groups.length : item.children.length
        const estimatedHeight = (rowCount + 2) * 40 + 16
        const maxTop = window.innerHeight - estimatedHeight - 8
        navDropdownStyle.value = {
          left: `${rect.right + 8}px`,
          top: `${Math.max(8, Math.min(rect.top, maxTop))}px`,
        }
      }
      activeFlyout.value = 'nav'
      activeFlyoutItem.value = item
    }
    return
  }

  closeFlyout()
  router.push(item.path)
}

const handleNavigate = (path: string) => {
  userMenuOpen.value = false
  closeFlyout()
  router.push(path)
}

const handleProfileSettings = () => {
  handleNavigate('/profile')
}

const handleOpenMemories = () => {
  userMenuOpen.value = false
  closeFlyout()
  if (!memoriesEnabledForUser.value) {
    router.push('/profile?highlight=memories')
    return
  }
  isMemoriesDialogOpen.value = true
}

const handleLogout = async () => {
  userMenuOpen.value = false
  closeFlyout()
  await logout()
  router.push('/login')
}

const chatActivityTimestamp = (chat: StoreChat): number => {
  const ts = Date.parse(chat.updatedAt ?? '') || Date.parse(chat.createdAt ?? '') || 0
  return ts
}

const chatList = computed(() => {
  return chatsStore.chats
    .filter((c) => {
      if (c.widgetSession) return false
      if (c.id === chatsStore.activeChatId) return true
      const isEmpty =
        (!c.messageCount || c.messageCount === 0) &&
        !c.firstMessagePreview &&
        (c.title === t('chat.newChat') ||
          c.title === 'New Chat' ||
          c.title === 'Neuer Chat' ||
          c.title.startsWith('Chat '))
      return !isEmpty
    })
    .slice()
    .sort((a, b) => chatActivityTimestamp(b) - chatActivityTimestamp(a))
})

const filteredChatList = computed(() => {
  const q = chatSearchQuery.value.toLowerCase().trim()
  if (!q) return chatList.value
  return chatList.value.filter((c) => {
    return getDisplayTitle(c).toLowerCase().includes(q)
  })
})

const getDisplayTitle = (chat: StoreChat): string => {
  if (!isDefaultChatTitle(chat.title, t('chat.newChat'))) return chat.title
  if (chat.firstMessagePreview) return chat.firstMessagePreview
  return t('chat.newChat')
}

const formatTimestamp = (dateStr: string): string => {
  return formatRelativeTime(new Date(dateStr))
}

/**
 * A turn keeps generating after the tab that started it navigated away, so the
 * dot tells the user which chat is still worth returning to.
 */
const isGenerating = (chat: StoreChat): boolean => chatsStore.activeRunChatIds.has(chat.id)

const getChannelIcon = (chat: StoreChat): string | null => {
  switch (chat.source) {
    case 'whatsapp':
      return 'mdi:whatsapp'
    case 'email':
      return 'mdi:email-outline'
    case 'widget':
      return 'mdi:widgets-outline'
    case 'api':
      return 'mdi:console'
    default:
      return null
  }
}

const getChannelIconClass = (chat: StoreChat): string => {
  switch (chat.source) {
    case 'whatsapp':
      return 'text-green-500'
    case 'email':
      return 'text-blue-500'
    case 'widget':
      return 'text-purple-500'
    case 'api':
      return 'text-orange-500'
    default:
      return ''
  }
}

const handleNewChat = async () => {
  if (isCreatingChat.value) return
  isCreatingChat.value = true
  try {
    await chatsStore.findOrCreateEmptyChat()
    if (route.path !== '/') router.push('/')
    chatModalOpen.value = false
  } finally {
    setTimeout(() => {
      isCreatingChat.value = false
    }, 300)
  }
}

const handleChatSelect = (chatId: number) => {
  chatsStore.setActiveChat(chatId)
  if (route.path !== '/') router.push('/')
  chatModalOpen.value = false
  chatMenuOpenId.value = null
}

const handleChatRename = async (chatId: number) => {
  const chat = chatsStore.chats.find((c) => c.id === chatId)
  chatMenuOpenId.value = null
  const newTitle = await dialog.prompt({
    title: t('chat.rename'),
    message: t('chat.enterNewName'),
    placeholder: t('chat.namePlaceholder'),
    defaultValue: chat?.title || '',
    confirmText: t('common.rename'),
    cancelText: t('common.cancel'),
  })
  if (newTitle && newTitle.trim()) {
    await chatsStore.updateChatTitle(chatId, newTitle.trim())
  }
}

const handleChatDelete = async (chatId: number) => {
  chatMenuOpenId.value = null
  const confirmed = await dialog.confirm({
    title: t('chat.delete'),
    message: t('chat.deleteConfirm'),
    confirmText: t('common.delete'),
    cancelText: t('common.cancel'),
    danger: true,
  })
  if (confirmed) {
    await chatsStore.deleteChat(chatId)
  }
}

const handleChatShare = (chatId: number) => {
  const chat = chatsStore.chats.find((c) => c.id === chatId)
  shareModalChatId.value = chatId
  shareModalChatTitle.value = chat?.title || 'Chat'
  shareModalOpen.value = true
  chatMenuOpenId.value = null
}

const toggleChatMenu = (chatId: number, event: MouseEvent) => {
  triggerHapticImpact('light')
  if (chatMenuOpenId.value === chatId) {
    chatMenuOpenId.value = null
    return
  }
  const btn = event.currentTarget as HTMLElement
  const rect = btn.getBoundingClientRect()
  const menuHeight = 140
  const menuWidth = 176
  const spaceBelow = window.innerHeight - rect.bottom
  const top = spaceBelow < menuHeight ? rect.top - menuHeight : rect.bottom + 4
  const left = Math.max(8, rect.right - menuWidth)
  chatMenuStyle.value = {
    top: `${top}px`,
    left: `${left}px`,
  }
  chatMenuOpenId.value = chatId
}

const closeFlyout = () => {
  activeFlyout.value = null
  activeFlyoutItem.value = null
  userMenuOpen.value = false
}
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
.modal-enter-active [data-testid='modal-chat-manager'],
.modal-leave-active [data-testid='modal-chat-manager'] {
  transition:
    transform 0.2s ease,
    opacity 0.2s ease;
}
.modal-enter-from [data-testid='modal-chat-manager'],
.modal-leave-to [data-testid='modal-chat-manager'] {
  transform: scale(0.95) translateY(10px);
  opacity: 0;
}
</style>
