export default {
  /**
   * Mount the plugin into the provided container.
   * @param {HTMLElement} el - The container element
   * @param {Object} context - Context provided by the host app
   */
  mount(el, context) {
    el.innerHTML = `
      <div style="padding: 20px; font-family: sans-serif;">
        <h2 style="color: var(--brand, #007bff); margin-bottom: 16px;">Hello World Plugin!</h2>
        <p style="color: var(--txt-primary, #333); margin-bottom: 8px;">
          This plugin is running natively in the Synaplan UI without an iframe.
        </p>
        <div style="background: var(--bg-app, #f5f5f5); padding: 12px; border-radius: 8px; margin-top: 16px;">
          <strong>Context Data:</strong>
          <ul style="margin-top: 8px; font-size: 14px;">
            <li>User ID: ${context.userId}</li>
            <li>API Base: ${context.apiBaseUrl}</li>
            <li>Plugin Base: ${context.pluginBaseUrl}</li>
          </ul>
        </div>
        <button id="plugin-action-btn" style="margin-top: 20px; padding: 8px 16px; background: var(--brand, #007bff); color: white; border: none; border-radius: 6px; cursor: pointer;">
          Click Me!
        </button>
      </div>
    `;

    const btn = el.querySelector('#plugin-action-btn');
    if (btn) {
      btn.onclick = () => alert('Plugin action triggered!');
    }
  }
};

