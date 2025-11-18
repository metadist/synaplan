import { test, expect } from '@playwright/test';

test.describe('API Auth Token Smoke Test', () => {
  const baseURL = process.env.BASE_URL || 'http://localhost:5137';
  const user = process.env.AUTH_USER || '';
  const pass = process.env.AUTH_PASS || '';

  test('@smoke sollte Token via API holen', async ({ request }) => {
    // Skip wenn keine Credentials vorhanden
    test.skip(!user || !pass, 'AUTH_USER und AUTH_PASS müssen gesetzt sein');
      // TODO: Passe Endpoint und Payload an deine Auth-API an
      const authEndpoint = '/api/auth'; // oder '/api/login', '/api/token'

      const response = await request.post(authEndpoint, {
        data: {
          username: user,
          password: pass,
          // TODO: Passe Payload-Struktur an
        },
      });

      // Erwarte Status 200
      expect(response.status()).toBe(200);

      // Erwarte Token-Feld
      const body = await response.json();
      expect(body).toHaveProperty('token'); // oder 'access_token', 'accessToken', etc.
      expect(body.token).toBeTruthy();
  });
});

