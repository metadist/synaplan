export default {
  /**
   * Mount the SortX plugin into the provided container.
   * @param {HTMLElement} el - The container element
   * @param {Object} context - Context provided by the host app
   */
  mount(el, context) {
    el.innerHTML = `
      <div style="padding: 20px; font-family: sans-serif;">
        <h2 style="color: var(--brand, #00b79d); margin-bottom: 16px;">SortX Document Classification</h2>
        <p style="color: var(--txt-primary, #333); margin-bottom: 8px;">
          AI-powered document classification with <strong>multi-label support</strong> and <strong>metadata extraction</strong>.
        </p>
        <div style="background: var(--bg-card, #f5f5f5); padding: 16px; border-radius: 8px; margin-top: 16px; border: 1px solid var(--border-light, #e0e0e0);">
          <h3 style="color: var(--txt-primary, #333); margin-bottom: 12px; font-size: 18px;">Plugin Status</h3>
          <ul style="margin-top: 8px; font-size: 14px; color: var(--txt-secondary, #666); list-style: none; padding: 0;">
            <li style="margin-bottom: 8px;">✅ <strong>Status:</strong> Active</li>
            <li style="margin-bottom: 8px;">✅ <strong>Version:</strong> 2.0.0</li>
            <li style="margin-bottom: 8px;">✅ <strong>API Endpoints:</strong> Available</li>
          </ul>
        </div>
        <div style="background: var(--bg-card, #f5f5f5); padding: 16px; border-radius: 8px; margin-top: 16px; border: 1px solid var(--border-light, #e0e0e0);">
          <h3 style="color: var(--txt-primary, #333); margin-bottom: 12px; font-size: 18px;">Available API Endpoints</h3>
          <ul style="margin-top: 8px; font-size: 14px; color: var(--txt-secondary, #666); list-style: none; padding: 0;">
            <li style="margin-bottom: 8px;">📄 <code style="background: var(--bg-chip, #f0f0f0); padding: 2px 6px; border-radius: 4px;">GET /schema</code> - Get category schema, fields & prompt</li>
            <li style="margin-bottom: 8px;">📄 <code style="background: var(--bg-chip, #f0f0f0); padding: 2px 6px; border-radius: 4px;">GET /categories</code> - Get categories with metadata fields</li>
            <li style="margin-bottom: 8px;">📄 <code style="background: var(--bg-chip, #f0f0f0); padding: 2px 6px; border-radius: 4px;">POST /classify</code> - Classify text with metadata extraction</li>
            <li style="margin-bottom: 8px;">📄 <code style="background: var(--bg-chip, #f0f0f0); padding: 2px 6px; border-radius: 4px;">POST /analyze-file</code> - Full file analysis</li>
          </ul>
        </div>
        <div style="margin-top: 20px; padding: 12px; background: var(--bg-chip, #f0f0f0); border-radius: 8px; font-size: 14px; color: var(--txt-secondary, #666);">
          <strong>Note:</strong> This plugin provides API endpoints for the SortX local tool. Use the SortX Docker container to process documents locally, which will call these endpoints for AI classification.
        </div>
      </div>
    `;
  }
};
