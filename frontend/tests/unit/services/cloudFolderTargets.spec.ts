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
