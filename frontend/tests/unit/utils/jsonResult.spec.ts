import { describe, expect, it } from 'vitest'
import {
  extractRecordList,
  looksLikeJsonDocument,
  parseJsonPayload,
  parseJsonValue,
  presentRecord,
} from '@/utils/jsonResult'

const chatList = {
  total: 2,
  chats: [
    {
      id: 41,
      title: 'Knowledge Base One',
      source: 'web',
      created_at: '2026-08-01T10:00:00Z',
      updated_at: '2026-08-20T09:00:00Z',
      message_count: 4,
    },
    {
      id: 42,
      title: 'Project notes',
      source: 'file',
      message_count: 1,
    },
  ],
}

describe('jsonResult', () => {
  it('detects a whole-message JSON object', () => {
    expect(looksLikeJsonDocument(JSON.stringify(chatList))).toBe(true)
    expect(looksLikeJsonDocument('[{"id":1,"title":"A"}]')).toBe(true)
    expect(looksLikeJsonDocument('not json')).toBe(false)
    expect(looksLikeJsonDocument('{"hello":"world"} and more text')).toBe(false)
  })

  it('rejects officemaker envelopes', () => {
    expect(looksLikeJsonDocument('{"BFILEPATH":"a.docx","BFILETEXT":"x"}')).toBe(false)
  })

  it('extracts a named list of records', () => {
    const list = extractRecordList(chatList)
    expect(list?.collectionKey).toBe('chats')
    expect(list?.total).toBe(2)
    expect(list?.records).toHaveLength(2)
  })

  it('presents a chat row with title, source and count', () => {
    const view = presentRecord(chatList.chats[0], 'en')
    expect(view.title).toBe('Knowledge Base One')
    expect(view.id).toBe('41')
    expect(view.source).toBe('web')
    expect(view.count).toBe(4)
    expect(view.date).toBeTruthy()
  })

  it('parses JSON or returns null', () => {
    expect(parseJsonValue('{"a":1}')).toEqual({ a: 1 })
    expect(parseJsonValue('{')).toBeNull()
    expect(parseJsonValue('"just a string"')).toBeNull()
  })

  describe('payloads cut off by the backend output cap', () => {
    // McpFetchRunner caps a tool result at 12000 chars and appends an ellipsis,
    // which leaves the JSON unparseable mid-token — the exact shape that used
    // to fall through to a raw text dump.
    const cutOff = `{
    "total": 50,
    "chats": [
        {
            "id": 12791,
            "title": "Guest Chat",
            "message_count": 4
        },
        {
            "id": 12753,
            "title": "Kurzbeschreibung",
            "message_count": 2
        },
        {
            "id": 12749,
            "message_…`

    it('recovers the complete records and flags the payload as truncated', () => {
      const payload = parseJsonPayload(cutOff)

      expect(payload?.truncated).toBe(true)
      const list = extractRecordList(payload?.value)
      expect(list?.records).toHaveLength(2)
      expect(list?.total).toBe(50)
      expect(presentRecord(list!.records[0], 'en').title).toBe('Guest Chat')
    })

    it('renders it as a JSON result instead of raw text', () => {
      expect(looksLikeJsonDocument(cutOff)).toBe(true)
    })

    it('reports a complete payload as not truncated', () => {
      expect(parseJsonPayload(JSON.stringify(chatList))?.truncated).toBe(false)
    })

    it('gives up when nothing complete was received yet', () => {
      expect(parseJsonPayload('{"total": 50, "chats": [{"id": 1, "titl')).toBeNull()
    })
  })
})
