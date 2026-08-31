import { z } from 'zod'
import { httpClient } from './httpClient'
import {
  CreateDesktopPairingCodeResponseSchema,
  ListDesktopDevicesResponseSchema,
  RevokeDesktopDeviceResponseSchema,
  EnqueueDesktopJobResponseSchema,
  GetDesktopJobResponseSchema,
  ListDesktopJobsResponseSchema,
} from '@/generated/api-schemas'

/**
 * Channels → Desktop: pair and manage the user's computers, and enqueue
 * `skill.run` jobs for them.
 *
 * Every route 404s when the DESKTOP_AGENT feature flag is off, so callers must
 * gate the UI with {@link isDesktopAgentEnabled} first. Pairing itself is a
 * two-step, human-in-the-loop flow: the web app only MINTS a code
 * (`createPairingCode`); the actual code→key exchange (`POST /pair`) happens on
 * the desktop client, never here — the scoped key must never touch the browser.
 */

export type DesktopDevice = NonNullable<
  z.infer<typeof ListDesktopDevicesResponseSchema>['devices']
>[number]

export type DesktopJob = z.infer<typeof GetDesktopJobResponseSchema>['job']

export interface PairingCode {
  code: string
  expiresAt: number
}

export interface EnqueueJobPayload {
  deviceId: number
  skill: string
  prompt: string
  chatId?: number | null
  fileIds?: number[]
}

export const desktopApi = {
  async listDevices(): Promise<DesktopDevice[]> {
    const data = await httpClient('/api/v1/desktop/devices', {
      method: 'GET',
      schema: ListDesktopDevicesResponseSchema,
    })
    return data.devices ?? []
  },

  async createPairingCode(): Promise<PairingCode> {
    const data = await httpClient('/api/v1/desktop/pairing-codes', {
      method: 'POST',
      schema: CreateDesktopPairingCodeResponseSchema,
    })
    return { code: data.code, expiresAt: data.expiresAt }
  },

  async revokeDevice(id: number): Promise<void> {
    await httpClient(`/api/v1/desktop/devices/${id}`, {
      method: 'DELETE',
      schema: RevokeDesktopDeviceResponseSchema,
    })
  },

  async enqueueJob(payload: EnqueueJobPayload): Promise<{ jobId: number; status: string }> {
    const data = await httpClient('/api/v1/desktop/jobs', {
      method: 'POST',
      body: JSON.stringify({
        deviceId: payload.deviceId,
        type: 'skill.run',
        input: {
          skill: payload.skill,
          prompt: payload.prompt,
          fileIds: payload.fileIds ?? [],
        },
        chatId: payload.chatId ?? null,
      }),
      schema: EnqueueDesktopJobResponseSchema,
    })
    return { jobId: data.jobId, status: data.status }
  },

  async getJob(id: number): Promise<DesktopJob> {
    const data = await httpClient(`/api/v1/desktop/jobs/${id}`, {
      method: 'GET',
      schema: GetDesktopJobResponseSchema,
    })
    return data.job
  },

  async listJobs(): Promise<z.infer<typeof ListDesktopJobsResponseSchema>['jobs']> {
    const data = await httpClient('/api/v1/desktop/jobs', {
      method: 'GET',
      schema: ListDesktopJobsResponseSchema,
    })
    return data.jobs ?? []
  },
}
