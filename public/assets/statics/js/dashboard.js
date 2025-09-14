/* globals Chart:false, feather:false, bootstrap:false */

// Logout Modal Functions (global)
function showLogoutModal() {
    // Use the existing generic modal
    const genericModal = document.getElementById('genericModal');
    const modalTitle = document.getElementById('genericModalLabel');
    const modalBody = document.getElementById('genericModalBody');
    const modalFooter = document.getElementById('genericModalFooter');
    
    // Set modal content
    modalTitle.textContent = 'Sign out of your account?';
    modalBody.innerHTML = `
        <div class="text-center">
            <div class="mb-3">
                <span data-feather="log-out" style="width: 48px; height: 48px; color: #dc3545;"></span>
            </div>
            <p class="text-muted">You can always sign back in at any time.</p>
        </div>
    `;
    modalFooter.innerHTML = `
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" onclick="confirmLogout()">Sign out</button>
    `;
    
    // Show modal
    const modal = new bootstrap.Modal(genericModal);
    modal.show();
    
    // Re-initialize feather icons (if available)
    setTimeout(() => { if (typeof feather !== 'undefined') { feather.replace({ 'aria-hidden': 'true' }); } }, 100);
}

function confirmLogout() {
    window.location.href = 'index.php?action=logout';
}

// Initialize when DOM is loaded
(function () {
  if (typeof feather !== 'undefined') {
    feather.replace({ 'aria-hidden': 'true' });
  }
})()

// Unified notification (Bootstrap Toast)
window.notify = function(type, message, title) {
  try {
    const containerId = 'toastContainer';
    let container = document.getElementById(containerId);
    if (!container) {
      container = document.createElement('div');
      container.id = containerId;
      container.className = 'toast-container position-fixed top-0 end-0 p-3';
      container.style.zIndex = '1100';
      document.body.appendChild(container);
    }

    // Map type to bootstrap bg class
    const typeClass = {
      success: 'bg-success text-white',
      error: 'bg-danger text-white',
      danger: 'bg-danger text-white',
      warning: 'bg-warning',
      info: 'bg-info'
    }[String(type || 'info').toLowerCase()] || 'bg-secondary text-white';

    const toastEl = document.createElement('div');
    toastEl.className = 'toast align-items-center border-0 shadow';
    toastEl.setAttribute('role', 'status');
    toastEl.setAttribute('aria-live', 'polite');
    toastEl.setAttribute('aria-atomic', 'true');
    toastEl.innerHTML = `
      <div class="toast-header ${typeClass}">
        <strong class="me-auto">${title || (type === 'success' ? 'Success' : type === 'error' || type === 'danger' ? 'Error' : 'Notice')}</strong>
        <button type="button" class="btn-close btn-close-white ms-2 mb-1" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
      <div class="toast-body">${message || ''}</div>
    `;
    container.appendChild(toastEl);
    // Compute readable duration: min 5s, max 12s, scale with content length
    const textLen = (message || '').toString().length;
    let delayMs = Math.max(5000, Math.min(12000, 60 * textLen));
    // Errors stay until dismissed
    const isSticky = ['error', 'danger'].includes(String(type || '').toLowerCase());

    const toast = new bootstrap.Toast(toastEl, { delay: isSticky ? undefined : delayMs, autohide: !isSticky });
    toast.show();
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
  } catch (e) {
    // Fallback
    alert(message || '');
  }
}

// Show deferred notification after reload if present
document.addEventListener('DOMContentLoaded', function() {
  try {
    const stored = sessionStorage.getItem('notifyAfterReload');
    if (stored) {
      const obj = JSON.parse(stored);
      if (obj && obj.message) {
        window.notify(obj.type || 'info', obj.message, obj.title || '');
      }
      sessionStorage.removeItem('notifyAfterReload');
    }
  } catch (e) {}
});
