import { computed, type Ref } from 'vue'
import { useConfigStore } from '@/stores/config'
import { useResearchStore } from '@/stores/research'
import { isNativeApp } from '@/services/api/nativeRuntime'
import { isTerminalStatus } from '@/services/chat/stream/handlers/research'
import { liveVoiceButtonVisible, liveVoiceReplacesSend } from '@/services/liveVoice/visibility'

/**
 * While a reply streams or a research run is going, the composer's send
 * button is the stop control. Live speech must not take that slot then:
 * an empty composer would otherwise show the waveform instead of Stop.
 */
export function useComposerStop(input: {
  streaming: Ref<boolean>
  guest: Ref<boolean>
  hasText: Ref<boolean>
  hasAttachment: Ref<boolean>
  livePress: () => void
}) {
  const research = useResearchStore()
  const config = useConfigStore()

  const researchRunning = computed(() => {
    const progress = research.progress
    return progress !== null && !isTerminalStatus(progress.status)
  })
  const composerStopping = computed(() => input.streaming.value || researchRunning.value)

  const showLiveInSendSlot = computed(() =>
    liveVoiceReplacesSend({
      available: liveVoiceButtonVisible({
        enabled: config.voiceLive?.enabled === true,
        guest: input.guest.value,
        nativeApp: isNativeApp(),
      }),
      streaming: composerStopping.value,
      hasText: input.hasText.value,
      hasAttachment: input.hasAttachment.value,
    })
  )

  const onLivePress = () => {
    if (showLiveInSendSlot.value) void input.livePress()
  }

  const onSendSlot = (send: () => void, stopStream: () => void) => {
    if (input.streaming.value) {
      stopStream()
      return
    }
    if (researchRunning.value) {
      if (!research.stopping) void research.stop()
      return
    }
    send()
  }

  const researchStopping = computed(() => research.stopping)

  return {
    composerStopping,
    researchRunning,
    researchStopping,
    showLiveInSendSlot,
    onLivePress,
    onSendSlot,
  }
}
