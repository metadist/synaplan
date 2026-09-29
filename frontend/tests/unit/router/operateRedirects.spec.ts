import { describe, expect, it, vi } from 'vitest'
import type { RouteLocationNormalized } from 'vue-router'

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => ({ features: {} }),
}))

import {
  adminDashboardRedirect,
  aiInfrastructureRedirect,
  modelStatusRedirect,
  systemConfigRedirect,
} from '@/router/operateRedirects'

function route(
  path: string,
  query: Record<string, string> = {},
  hash = ''
): RouteLocationNormalized {
  return { path, query, hash } as RouteLocationNormalized
}

describe('Operate redirects (topic regrouping)', () => {
  it('sends the old Overview tabs to their topic pages', () => {
    expect(adminDashboardRedirect(route('/admin', { tab: 'users' }))).toEqual({
      name: 'admin-people',
    })
    expect(adminDashboardRedirect(route('/admin', { tab: 'prompts' }))).toEqual({
      path: '/admin/setup',
      query: { tab: 'prompts' },
      hash: '',
    })
    expect(adminDashboardRedirect(route('/admin', { tab: 'moderation' }))).toEqual({
      name: 'admin-people',
      query: { tab: 'moderation' },
      hash: '',
    })
    expect(adminDashboardRedirect(route('/admin', { tab: 'usage' }))).toBe(true)
    expect(adminDashboardRedirect(route('/admin'))).toBe(true)
  })

  it('keeps the rest of the URL when Prompts and Moderation move', () => {
    expect(
      adminDashboardRedirect(route('/admin', { tab: 'prompts', connected: '1' }, '#details'))
    ).toEqual({
      path: '/admin/setup',
      query: { tab: 'prompts', connected: '1' },
      hash: '#details',
    })
    expect(
      adminDashboardRedirect(route('/admin', { tab: 'moderation', report: '4' }, '#open'))
    ).toEqual({
      name: 'admin-people',
      query: { tab: 'moderation', report: '4' },
      hash: '#open',
    })
  })

  it('maps the old AI infrastructure tab ids and keeps the rest of the query', () => {
    expect(
      aiInfrastructureRedirect(route('/admin/setup', { tab: 'models', connected: '1' }))
    ).toEqual({ path: '/admin/setup', query: { tab: 'providers', connected: '1' }, hash: '' })
    expect(aiInfrastructureRedirect(route('/admin/setup', { tab: 'extraction' }))).toEqual({
      path: '/admin/setup',
      query: { tab: 'documents' },
      hash: '',
    })
    expect(aiInfrastructureRedirect(route('/admin/setup', { tab: 'rerank' }))).toEqual({
      path: '/admin/setup',
      query: { tab: 'search' },
      hash: '',
    })
    expect(aiInfrastructureRedirect(route('/admin/setup', { tab: 'web-search' }))).toEqual({
      path: '/admin/config',
      query: { tab: 'web_search' },
      hash: '',
    })
    expect(aiInfrastructureRedirect(route('/admin/setup', { tab: 'health' }))).toBe(true)
    expect(aiInfrastructureRedirect(route('/admin/setup'))).toBe(true)
  })

  it('moves AI settings links from System configuration to AI infrastructure', () => {
    expect(
      systemConfigRedirect(route('/admin/config', { tab: 'processing', section: 'docling' }))
    ).toEqual({
      path: '/admin/setup',
      query: { tab: 'documents', section: 'docling' },
      hash: '',
    })
    expect(systemConfigRedirect(route('/admin/config', { tab: 'ai' }))).toEqual({
      path: '/admin/setup',
      query: { tab: 'providers' },
      hash: '',
    })
    expect(
      systemConfigRedirect(route('/admin/config', { tab: 'processing', section: 'brave' }))
    ).toEqual({
      path: '/admin/config',
      query: { tab: 'web_search', section: 'brave' },
      hash: '',
    })
    expect(systemConfigRedirect(route('/admin/config', { tab: 'channels', section: 'm365' }))).toBe(
      true
    )
    expect(systemConfigRedirect(route('/admin/config', { tab: 'tools' }))).toBe(true)
  })

  it('opens Model health for the old Model status page', () => {
    expect(modelStatusRedirect(route('/admin/model-status', { provider: 'openai' }))).toEqual({
      path: '/admin/setup',
      query: { provider: 'openai', tab: 'health' },
      hash: '',
    })
  })
})
