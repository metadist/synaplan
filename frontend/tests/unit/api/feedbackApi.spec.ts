import { describe, it, expect } from 'vitest'
import {
  FalsePositivePreviewResponseSchema,
  CheckContradictionsResponseSchema,
  KbSourceSchema,
  KbSourcesResponseSchema,
  WebSourceSchema,
  WebSourcesResponseSchema,
  ContradictionSchema,
  FalsePositiveResponseSchema,
  RegenerateCorrectionResponseSchema,
} from '@/services/api/feedbackApi'

describe('Feedback API Schemas', () => {
  describe('FalsePositivePreviewResponseSchema', () => {
    it('should parse valid preview response', () => {
      const data = {
        classification: 'feedback',
        summaryOptions: ['Option A', 'Option B'],
        correctionOptions: ['Correction 1'],
        relatedMemoryIds: [42, 99],
      }

      const result = FalsePositivePreviewResponseSchema.parse(data)
      expect(result.classification).toBe('feedback')
      expect(result.summaryOptions).toHaveLength(2)
      expect(result.relatedMemoryIds).toEqual([42, 99])
    })

    it('should default relatedMemoryIds to empty array', () => {
      const data = {
        classification: 'memory',
        summaryOptions: [],
        correctionOptions: [],
      }

      const result = FalsePositivePreviewResponseSchema.parse(data)
      expect(result.relatedMemoryIds).toEqual([])
    })

    it('should reject invalid classification', () => {
      const data = {
        classification: 'invalid',
        summaryOptions: [],
        correctionOptions: [],
      }

      expect(() => FalsePositivePreviewResponseSchema.parse(data)).toThrow()
    })
  })

  describe('CheckContradictionsResponseSchema', () => {
    it('should parse response with contradictions', () => {
      const data = {
        hasContradictions: true,
        contradictions: [
          {
            id: 1,
            type: 'memory',
            value: 'User likes PHP',
            reason: 'User said they prefer TypeScript',
          },
          {
            id: 2,
            type: 'false_positive',
            value: 'Earth is flat',
            reason: 'Contradicts scientific consensus',
          },
        ],
      }

      const result = CheckContradictionsResponseSchema.parse(data)
      expect(result.hasContradictions).toBe(true)
      expect(result.contradictions).toHaveLength(2)
    })

    it('should parse response without contradictions', () => {
      const data = {
        hasContradictions: false,
        contradictions: [],
      }

      const result = CheckContradictionsResponseSchema.parse(data)
      expect(result.hasContradictions).toBe(false)
      expect(result.contradictions).toEqual([])
    })
  })

  describe('ContradictionSchema', () => {
    it('should accept all valid types', () => {
      const types = ['memory', 'false_positive', 'positive'] as const
      for (const type of types) {
        const result = ContradictionSchema.parse({
          id: 1,
          type,
          value: 'test',
          reason: 'test reason',
        })
        expect(result.type).toBe(type)
      }
    })

    it('should reject invalid type', () => {
      expect(() =>
        ContradictionSchema.parse({
          id: 1,
          type: 'unknown',
          value: 'test',
          reason: 'test',
        })
      ).toThrow()
    })
  })

  describe('KbSourceSchema', () => {
    it('should parse valid KB source', () => {
      const data = {
        id: 1,
        sourceType: 'file',
        fileName: 'document.pdf',
        excerpt: 'Some excerpt...',
        summary: 'Source summary',
        score: 0.85,
      }

      const result = KbSourceSchema.parse(data)
      expect(result.sourceType).toBe('file')
      expect(result.score).toBe(0.85)
    })

    it('should accept all source types', () => {
      const sourceTypes = ['file', 'feedback_false', 'feedback_correct', 'memory'] as const
      for (const sourceType of sourceTypes) {
        const result = KbSourceSchema.parse({
          id: 1,
          sourceType,
          fileName: 'test',
          excerpt: 'test',
          summary: 'test',
          score: 0.5,
        })
        expect(result.sourceType).toBe(sourceType)
      }
    })

    it('should reject invalid source type', () => {
      expect(() =>
        KbSourceSchema.parse({
          id: 1,
          sourceType: 'unknown',
          fileName: 'test',
          excerpt: 'test',
          summary: 'test',
          score: 0.5,
        })
      ).toThrow()
    })
  })

  describe('KbSourcesResponseSchema', () => {
    it('should parse response with multiple sources', () => {
      const data = {
        sources: [
          {
            id: 1,
            sourceType: 'file',
            fileName: 'doc.pdf',
            excerpt: 'excerpt',
            summary: 'summary',
            score: 0.9,
          },
          {
            id: 2,
            sourceType: 'memory',
            fileName: 'Memory',
            excerpt: 'user likes TS',
            summary: 'TypeScript preference',
            score: 0.7,
          },
        ],
      }

      const result = KbSourcesResponseSchema.parse(data)
      expect(result.sources).toHaveLength(2)
    })

    it('should parse empty sources', () => {
      const result = KbSourcesResponseSchema.parse({ sources: [] })
      expect(result.sources).toEqual([])
    })
  })

  describe('WebSourceSchema', () => {
    it('should parse valid web source', () => {
      const data = {
        id: 1,
        title: 'Wikipedia Article',
        url: 'https://en.wikipedia.org/wiki/Test',
        summary: 'Article summary',
        snippet: 'A short snippet...',
      }

      const result = WebSourceSchema.parse(data)
      expect(result.url).toContain('wikipedia.org')
    })
  })

  describe('WebSourcesResponseSchema', () => {
    it('should parse response with web sources', () => {
      const data = {
        sources: [
          {
            id: 1,
            title: 'Result 1',
            url: 'https://example.com',
            summary: 'Summary 1',
            snippet: 'Snippet 1',
          },
        ],
      }

      const result = WebSourcesResponseSchema.parse(data)
      expect(result.sources).toHaveLength(1)
    })
  })

  describe('FalsePositiveResponseSchema', () => {
    it('should parse successful response', () => {
      const data = {
        success: true,
        example: {
          id: 42,
          category: 'feedback_negative',
          key: 'false_claim',
          value: 'Sydney is the capital of Australia',
          source: 'user_created',
          messageId: null,
          created: 1705234567,
          updated: 1705234567,
        },
      }

      const result = FalsePositiveResponseSchema.parse(data)
      expect(result.success).toBe(true)
      expect(result.example.id).toBe(42)
    })
  })

  describe('RegenerateCorrectionResponseSchema', () => {
    it('should parse correction response', () => {
      const data = { correction: 'The capital of Australia is Canberra' }
      const result = RegenerateCorrectionResponseSchema.parse(data)
      expect(result.correction).toBe('The capital of Australia is Canberra')
    })
  })
})
