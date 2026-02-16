export default {
  /**
   * Mount the Casting Data Connector settings into the provided container.
   * @param {HTMLElement} el - The container element
   * @param {Object} context - Context provided by the host app (userId, apiBaseUrl, pluginBaseUrl, token)
   */
  mount(el, context) {
    const baseUrl = context.pluginBaseUrl

    el.innerHTML = `
      <div style="padding: 20px; font-family: sans-serif; max-width: 600px;">
        <h2 style="color: var(--brand, #007bff); margin-bottom: 8px;">Casting Data Connector</h2>
        <p style="color: var(--txt-secondary, #666); margin-bottom: 24px; font-size: 14px;">
          Connect an external casting platform to provide live production and audition data in chat.
        </p>

        <div id="castingdata-form" style="display: flex; flex-direction: column; gap: 16px;">
          <!-- API URL -->
          <div style="display: flex; flex-direction: column; gap: 4px;">
            <label style="font-weight: 600; font-size: 14px; color: var(--txt-primary, #333);">API URL</label>
            <input
              id="cd-api-url"
              type="url"
              placeholder="https://backstage.castapp.pro"
              style="padding: 10px 12px; border: 1px solid var(--border-light, #ccc); border-radius: 6px; font-size: 14px; background: var(--bg-card, #fff); color: var(--txt-primary, #333);"
            />
            <span style="font-size: 12px; color: var(--txt-secondary, #888);">Base URL of the casting platform API</span>
          </div>

          <!-- API Key -->
          <div style="display: flex; flex-direction: column; gap: 4px;">
            <label style="font-weight: 600; font-size: 14px; color: var(--txt-primary, #333);">API Key</label>
            <input
              id="cd-api-key"
              type="password"
              placeholder="Enter API key..."
              style="padding: 10px 12px; border: 1px solid var(--border-light, #ccc); border-radius: 6px; font-size: 14px; background: var(--bg-card, #fff); color: var(--txt-primary, #333);"
            />
            <span id="cd-key-hint" style="font-size: 12px; color: var(--txt-secondary, #888);">API key for authentication (Bearer token)</span>
          </div>

          <!-- Enabled Toggle -->
          <div style="display: flex; align-items: center; gap: 10px; margin-top: 4px;">
            <input id="cd-enabled" type="checkbox" style="width: 18px; height: 18px; cursor: pointer;" />
            <label for="cd-enabled" style="font-weight: 600; font-size: 14px; color: var(--txt-primary, #333); cursor: pointer;">
              Enable casting data in chat
            </label>
          </div>

          <!-- Buttons -->
          <div style="display: flex; gap: 12px; margin-top: 8px;">
            <button
              id="cd-save-btn"
              style="padding: 10px 20px; background: var(--brand, #007bff); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600;"
            >
              Save
            </button>
            <button
              id="cd-test-btn"
              style="padding: 10px 20px; background: var(--bg-card, #f5f5f5); color: var(--txt-primary, #333); border: 1px solid var(--border-light, #ccc); border-radius: 6px; cursor: pointer; font-size: 14px;"
            >
              Test Connection
            </button>
          </div>

          <!-- Status Message -->
          <div
            id="cd-status"
            style="display: none; padding: 12px; border-radius: 6px; font-size: 14px; margin-top: 4px;"
          ></div>
        </div>
      </div>
    `

    const apiUrlInput = el.querySelector('#cd-api-url')
    const apiKeyInput = el.querySelector('#cd-api-key')
    const enabledCheckbox = el.querySelector('#cd-enabled')
    const keyHint = el.querySelector('#cd-key-hint')
    const saveBtn = el.querySelector('#cd-save-btn')
    const testBtn = el.querySelector('#cd-test-btn')
    const statusEl = el.querySelector('#cd-status')

    function showStatus(message, isSuccess) {
      statusEl.style.display = 'block'
      statusEl.textContent = message
      statusEl.style.background = isSuccess
        ? 'var(--bg-success, #d4edda)'
        : 'var(--bg-danger, #f8d7da)'
      statusEl.style.color = isSuccess
        ? 'var(--txt-success, #155724)'
        : 'var(--txt-danger, #721c24)'
      statusEl.style.border = isSuccess
        ? '1px solid var(--border-success, #c3e6cb)'
        : '1px solid var(--border-danger, #f5c6cb)'
    }

    function setLoading(btn, loading) {
      btn.disabled = loading
      btn.style.opacity = loading ? '0.6' : '1'
    }

    async function apiFetch(path, options = {}) {
      const headers = {
        'Content-Type': 'application/json',
        ...options.headers,
      }
      if (context.token) {
        headers['Authorization'] = `Bearer ${context.token}`
      }
      const response = await fetch(`${baseUrl}${path}`, {
        ...options,
        headers,
        credentials: 'include',
      })
      return response.json()
    }

    // Load current config
    async function loadConfig() {
      try {
        const data = await apiFetch('/config')
        apiUrlInput.value = data.api_url || ''
        enabledCheckbox.checked = !!data.enabled
        if (data.has_api_key) {
          apiKeyInput.placeholder = data.api_key_masked || 'Key configured'
          keyHint.textContent = 'API key is configured. Leave empty to keep current key.'
        }
      } catch (e) {
        showStatus('Failed to load configuration', false)
      }
    }

    // Save config
    saveBtn.onclick = async () => {
      setLoading(saveBtn, true)
      try {
        const payload = {
          api_url: apiUrlInput.value,
          enabled: enabledCheckbox.checked,
        }
        if (apiKeyInput.value) {
          payload.api_key = apiKeyInput.value
        }

        const result = await apiFetch('/config', {
          method: 'PUT',
          body: JSON.stringify(payload),
        })

        if (result.success) {
          showStatus('Configuration saved successfully', true)
          apiKeyInput.value = ''
          if (result.has_api_key) {
            apiKeyInput.placeholder = result.api_key_masked || 'Key configured'
            keyHint.textContent = 'API key is configured. Leave empty to keep current key.'
          }
        } else {
          showStatus(result.error || 'Failed to save configuration', false)
        }
      } catch (e) {
        showStatus('Failed to save configuration: ' + e.message, false)
      } finally {
        setLoading(saveBtn, false)
      }
    }

    // Test connection
    testBtn.onclick = async () => {
      setLoading(testBtn, true)
      try {
        const result = await apiFetch('/test-connection', { method: 'POST' })
        showStatus(result.message || (result.success ? 'Connection OK' : 'Connection failed'), result.success)
      } catch (e) {
        showStatus('Connection test failed: ' + e.message, false)
      } finally {
        setLoading(testBtn, false)
      }
    }

    loadConfig()
  }
}
