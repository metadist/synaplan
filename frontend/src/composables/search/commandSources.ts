import { computed, type Component } from 'vue'
import { useRouter } from 'vue-router'
import {
  ArrowRightOnRectangleIcon,
  ChatBubbleLeftRightIcon,
  CloudArrowUpIcon,
  ComputerDesktopIcon,
  GlobeAltIcon,
  LanguageIcon,
  MoonIcon,
  PhotoIcon,
  PlusIcon,
  PuzzlePieceIcon,
  QuestionMarkCircleIcon,
  SpeakerWaveIcon,
  SunIcon,
  VideoCameraIcon,
} from '@heroicons/vue/24/outline'
import { i18n } from '@/i18n/instance'
import { languageOptions } from '@/i18n/shared'
import { setLocale } from '@/i18n/loader'
import { useTheme } from '@/composables/useTheme'
import { useAuth } from '@/composables/useAuth'
import { useChatsStore } from '@/stores/chats'
import { useCommandsStore } from '@/stores/commands'
import { useSmartSearchStore } from '@/stores/smartSearch'
import { allLocaleTexts } from './localeTexts'
import type { LocalEntry } from './pageSources'

interface CommandDef {
  id: string
  titleKey: string
  titleParams?: Record<string, string>
  icon: Component
  run: () => unknown
  /** Extra search terms that are not translated (e.g. `/pic`). */
  extra?: string
}

const SLASH_ICONS: Record<string, Component> = {
  pic: PhotoIcon,
  vid: VideoCameraIcon,
  tts: SpeakerWaveIcon,
  search: GlobeAltIcon,
  help: QuestionMarkCircleIcon,
}

/** App-wide commands: things you *do*, as opposed to places you go. */
export function useCommandSources() {
  const router = useRouter()
  const { setTheme } = useTheme()
  const { logout, isImpersonating, isAuthenticated } = useAuth()
  const chatsStore = useChatsStore()
  const commandsStore = useCommandsStore()
  const smartSearchStore = useSmartSearchStore()
  const t = i18n.global.t

  const prefillChat = async (text: string, options?: { send?: boolean }) => {
    // Ask-in-chat goes through the in-memory store, never the URL, so only a
    // click in the palette can send. ChatView picks it up once the composer
    // is on screen. Slash commands stay a draft in `?prefill=`.
    if (options?.send) {
      smartSearchStore.askInChat(text)
      await router.push('/')
      return
    }
    await chatsStore.findOrCreateEmptyChat()
    await router.push({ path: '/', query: { prefill: text } })
  }

  const definitions = computed<CommandDef[]>(() => {
    const defs: CommandDef[] = [
      {
        id: 'command:new-chat',
        titleKey: 'search.palette.commands.newChat',
        icon: PlusIcon,
        run: async () => {
          await chatsStore.findOrCreateEmptyChat()
          await router.push('/')
        },
      },
      {
        id: 'command:all-chats',
        titleKey: 'search.palette.commands.allChats',
        icon: ChatBubbleLeftRightIcon,
        run: () => router.push('/chats'),
      },
      {
        id: 'command:upload',
        titleKey: 'search.palette.commands.uploadFiles',
        icon: CloudArrowUpIcon,
        run: () => router.push('/files'),
      },
      {
        id: 'command:theme-light',
        titleKey: 'search.palette.commands.themeLight',
        icon: SunIcon,
        run: () => setTheme('light'),
      },
      {
        id: 'command:theme-dark',
        titleKey: 'search.palette.commands.themeDark',
        icon: MoonIcon,
        run: () => setTheme('dark'),
      },
      {
        id: 'command:theme-system',
        titleKey: 'search.palette.commands.themeSystem',
        icon: ComputerDesktopIcon,
        run: () => setTheme('system'),
      },
      ...languageOptions.map((option) => ({
        id: `command:language-${option.value}`,
        titleKey: 'search.palette.commands.language',
        titleParams: { language: option.label },
        icon: LanguageIcon,
        extra: `${option.label} ${option.value}`,
        run: async () => {
          await setLocale(option.value)
        },
      })),
    ]

    for (const command of commandsStore.commands) {
      const builtinKey = `search.palette.commands.slash.${command.name}`
      const hasBuiltin = i18n.global.te(builtinKey, 'en')
      defs.push({
        id: `command:slash-${command.name}`,
        titleKey: hasBuiltin ? builtinKey : 'search.palette.commands.pluginCommand',
        titleParams: hasBuiltin ? undefined : { description: command.description },
        icon: SLASH_ICONS[command.name] ?? PuzzlePieceIcon,
        extra: `/${command.name} ${command.description}`,
        run: () => prefillChat(`/${command.name} `),
      })
    }

    if (isAuthenticated.value && !isImpersonating.value) {
      defs.push({
        id: 'command:logout',
        titleKey: 'search.palette.commands.logout',
        icon: ArrowRightOnRectangleIcon,
        run: async () => {
          await logout()
          await router.push('/login')
        },
      })
    }
    return defs
  })

  const entries = computed<LocalEntry[]>(() => {
    const commandLabel = String(t('search.palette.kind.command'))
    return definitions.value.map((def) => {
      const title = String(t(def.titleKey, def.titleParams ?? {}))
      const keywords = [
        ...(def.titleParams ? [] : allLocaleTexts(def.titleKey)),
        ...allLocaleTexts(`${def.titleKey}Keywords`),
        def.extra ?? '',
      ]
      return {
        result: {
          id: def.id,
          kind: 'command',
          title,
          icon: def.icon,
          matchedBy: 'local',
          run: def.run,
        },
        doc: { id: def.id, title, keywords: keywords.join(' '), subtitle: commandLabel },
      }
    })
  })

  return { entries, prefillChat }
}
