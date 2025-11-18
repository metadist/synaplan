import { test, expect } from '@playwright/test';

test.describe('API Health Smoke Test', () => {
  const baseURL = process.env.BASE_URL || 'http://localhost:5137';
  const apiToken = process.env.API_TOKEN;

  test('@smoke sollte Health-Endpoint antworten', async ({ request }) => {
    // TODO: Passe Endpoint an (/api/health oder /api/version)
    const healthEndpoint = '/api/health'; // oder '/api/version'

    const headers: Record<string, string> = {};
    if (apiToken) {
      headers['Authorization'] = `Bearer ${apiToken}`;
    }

    const response = await request.get(healthEndpoint, { headers });

    // Erwarte Status 200
    expect(response.status()).toBe(200);

    // Erwarte JSON mit relevanten Keys
    const body = await response.json();
    expect(body).toBeDefined();
    
    // TODO: Passe erwartete Keys an deine Health-Response an
    // Beispiel: expect(body).toHaveProperty('status');
    // Beispiel: expect(body).toHaveProperty('version');
  });
});

