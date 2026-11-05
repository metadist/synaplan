import type { Component } from 'vue'
import {
  ArchiveBoxIcon,
  ChatBubbleLeftEllipsisIcon,
  CreditCardIcon,
  DevicePhoneMobileIcon,
  LockClosedIcon,
  ScaleIcon,
  SwatchIcon,
  TrashIcon,
  UserCircleIcon,
} from '@heroicons/vue/24/outline'

/** Same glyph on the desktop settings sidebar and the phone settings list. */
export const SETTINGS_SECTION_ICONS: Record<string, Component> = {
  profile: UserCircleIcon,
  appearance: SwatchIcon,
  chat: ChatBubbleLeftEllipsisIcon,
  data: ArchiveBoxIcon,
  billing: CreditCardIcon,
  security: LockClosedIcon,
  app: DevicePhoneMobileIcon,
  legal: ScaleIcon,
  delete: TrashIcon,
}
