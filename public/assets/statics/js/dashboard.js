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
    
    // Re-initialize feather icons
    setTimeout(() => feather.replace({ 'aria-hidden': 'true' }), 100);
}

function confirmLogout() {
    window.location.href = 'index.php?action=logout';
}

// Initialize when DOM is loaded
(function () {
  feather.replace({ 'aria-hidden': 'true' });
})()
