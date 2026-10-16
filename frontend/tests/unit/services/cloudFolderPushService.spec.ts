import { describe, expect, it } from 'vitest'
import { ApiError } from '@/services/api/httpClient'
import { pushFailureCopy, pushSuccessFolder } from '@/services/cloudFolderPushService'

const t = (key: string, values?: Record<string, unknown>) => {
  if (key === 'files.push.failure.unauthorized') {
    return `${values?.connection} rejected the login. Nothing was copied.`
  }
  if (key === 'files.push.failed') {
    return 'The file was not copied.'
  }
  if (key === 'files.push.action') {
    return 'Send to cloud'
  }
  return key
}

describe('cloudFolderPushService helpers', () => {
  it('maps a destination failure code to honest copy', () => {
    const err = new ApiError(422, 'rejected', 'unauthorized', {
      context: { connection: 'Office files' },
    })
    expect(pushFailureCopy(err, t, (key) => key.startsWith('files.push.failure.'))).toBe(
      'Office files rejected the login. Nothing was copied.'
    )
  })

  it('falls back when the error has no known code', () => {
    expect(pushFailureCopy(new Error('boom'), t, () => false)).toBe('The file was not copied.')
  })

  it('uses the remote path from the response when present', () => {
    expect(
      pushSuccessFolder(
        'Synaplan/probe.txt',
        {
          id: 12,
          name: 'Office files',
          kind: 'nextcloud',
          folder: 'Synaplan',
        },
        'probe.txt'
      )
    ).toBe('Synaplan/probe.txt')
  })
})
