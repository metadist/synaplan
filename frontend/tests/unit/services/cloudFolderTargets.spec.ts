import { describe, expect, it } from 'vitest'
import type { ConnectionItem } from '@/services/api/connectionsApi'
import { cloudFolderKindFor, cloudFolderTargetsFrom } from '@/services/cloudFolderTargets'

function connection(partial: Partial<ConnectionItem>): ConnectionItem {
  return {
    id: '1',
    source: 'registry',
    type: 'webdav',
    name: 'Folder',
    status: 'connected',
    last_checked: null,
    has_secret: true,
    ...partial,
  }
}

describe('cloudFolderTargetsFrom', () => {
  it('keeps Nextcloud and OpenCloud and drops generic WebDAV and Dropbox', () => {
    const targets = cloudFolderTargetsFrom([
      connection({
        id: '12',
        name: 'Office files',
        channel: 'nextcloud',
        config: { folder: 'Synaplan' },
      }),
      connection({
        id: '13',
        name: 'Team drive',
        channel: 'opencloud',
        config: { folder: 'Inbox' },
      }),
      connection({
        id: '14',
        name: 'Archive',
        channel: 'folder',
        config: { folder: 'Dump' },
      }),
      connection({
        id: '15',
        type: 'dropbox',
        name: 'Dropbox',
        channel: 'dropbox',
      }),
    ])

    expect(targets).toEqual([
      { id: 12, name: 'Office files', kind: 'nextcloud', folder: 'Synaplan' },
      { id: 13, name: 'Team drive', kind: 'opencloud', folder: 'Inbox' },
    ])
  })

  it('keeps a second OpenCloud folder whose planner channel is opencloud-2', () => {
    expect(
      cloudFolderKindFor(
        connection({
          name: 'Team drive',
          channel: 'opencloud-2',
          config: { base_url: 'https://cloud.example.com/remote.php/webdav' },
        })
      )
    ).toBe('opencloud')
  })

  it('does not treat a generic WebDAV row as OpenCloud just because the host says so', () => {
    expect(
      cloudFolderKindFor(
        connection({
          name: 'Archive',
          channel: 'folder',
          config: { base_url: 'https://opencloud.example.com/remote.php/webdav' },
        })
      )
    ).toBeNull()
  })

  it('omits destinations that cannot receive a file', () => {
    const targets = cloudFolderTargetsFrom([
      connection({ id: '12', name: 'Office files', channel: 'nextcloud' }),
      connection({
        id: '13',
        name: 'Broken',
        channel: 'opencloud',
        status: 'error',
      }),
      connection({
        id: '14',
        name: 'No secret',
        channel: 'nextcloud',
        has_secret: false,
      }),
      connection({
        id: '15',
        name: 'Never tested',
        channel: 'opencloud',
        status: 'never_tested',
      }),
    ])

    expect(targets.map((row) => row.id)).toEqual([12])
  })

  it('detects OpenCloud from the URL when no channel is stored', () => {
    expect(
      cloudFolderKindFor(
        connection({
          name: 'Team drive',
          config: { base_url: 'https://opencloud.example.com/remote.php/webdav' },
        })
      )
    ).toBe('opencloud')

    expect(
      cloudFolderKindFor(
        connection({
          name: 'Work files',
          config: { base_url: 'https://cloud.example.com/remote.php/webdav' },
        })
      )
    ).toBeNull()
  })
})
