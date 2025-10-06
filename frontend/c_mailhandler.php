<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" id="contentMain">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Mail Handler Configuration</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <div class="btn-group me-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.href='index.php/chat'">
                    <i class="fas fa-comments"></i> Chat
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.href='index.php/tools'">
                    <i class="fas fa-tools"></i> Tools
                </button>
            </div>
        </div>
    </div>

    <form id="mailhandlerForm" method="POST" action="index.php/mailhandler">
        <input type="hidden" name="action" id="action" value="updateMailhandler">
        
        <!-- Mail Pick Up Config Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-server"></i> Mail Pick Up Config</h5>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <label for="mailServer" class="col-sm-2 col-form-label"><strong>Mail Server:</strong></label>
                    <div class="col-sm-4">
                        <input type="text" class="form-control" name="mailServer" id="mailServer" placeholder="mail.example.com" required>
                        <div class="form-text">POP3 or IMAP server address</div>
                    </div>
                    <label for="mailPort" class="col-sm-2 col-form-label"><strong>Port:</strong></label>
                    <div class="col-sm-4">
                        <input type="number" class="form-control" name="mailPort" id="mailPort" placeholder="993" min="1" max="65535" value="993" required>
                        <div class="form-text">Server port (993 for IMAP SSL, 995 for POP3 SSL)</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <label for="mailProtocol" class="col-sm-2 col-form-label"><strong>Protocol:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="mailProtocol" id="mailProtocol" required>
                            <option value="imap">IMAP</option>
                            <option value="pop3">POP3</option>
                        </select>
                        <div class="form-text">Mail protocol to use</div>
                    </div>
                    <label for="mailSecurity" class="col-sm-2 col-form-label"><strong>Security:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="mailSecurity" id="mailSecurity" required>
                            <option value="ssl">SSL/TLS</option>
                            <option value="tls">STARTTLS</option>
                            <option value="none">None</option>
                        </select>
                        <div class="form-text">Connection security method</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <label for="mailUsername" class="col-sm-2 col-form-label"><strong>Username:</strong></label>
                    <div class="col-sm-4">
                        <input type="text" class="form-control" name="mailUsername" id="mailUsername" placeholder="user@example.com or account123" required>
                        <div class="form-text">Email address or username for authentication</div>
                    </div>
                    <label for="mailPassword" class="col-sm-2 col-form-label"><strong>Password:</strong></label>
                    <div class="col-sm-4">
                        <input type="password" class="form-control" name="mailPassword" id="mailPassword" placeholder="Enter password" required>
                        <div class="form-text">Password for mail account</div>
                    </div>
                </div>

                

                <div class="row mb-3">
                    <label for="mailCheckInterval" class="col-sm-2 col-form-label"><strong>Check Interval:</strong></label>
                    <div class="col-sm-4">
                        <select class="form-select" name="mailCheckInterval" id="mailCheckInterval" required>
                            <option value="5">5 minutes</option>
                            <option value="10" selected>10 minutes</option>
                            <option value="15">15 minutes</option>
                            <option value="30">30 minutes</option>
                            <option value="60">1 hour</option>
                        </select>
                        <div class="form-text">How often to check for new emails</div>
                    </div>
                    <label for="mailDeleteAfter" class="col-sm-2 col-form-label"><strong>Delete After:</strong></label>
                    <div class="col-sm-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="mailDeleteAfter" id="mailDeleteAfter">
                            <label class="form-check-label" for="mailDeleteAfter">
                                Delete processed emails from server
                            </label>
                        </div>
                        <div class="form-text">Remove emails after processing (POP3 only)</div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-sm-10 offset-sm-2">
                        <button type="button" id="testConnectionBtn" class="btn btn-outline-primary" onclick="testMailConnection()">
                            <i class="fas fa-plug"></i> Test Connection
                        </button>
                        <button type="button" class="btn btn-outline-info" onclick="showMailHelp()">
                            <i class="fas fa-question-circle"></i> Connection Help
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mail Departments Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-envelope"></i> Mail Departments</h5>
                <small class="text-muted">Configure up to 10 target email addresses for forwarding processed emails</small>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>Note:</strong> You must set at least one department as DEFAULT. Processed emails will be forwarded to the appropriate department based on content analysis.
                </div>

                <div id="mailDepartments">
                    <!-- Department entries will be dynamically added here -->
                </div>

                <div class="row mt-3">
                    <div class="col-12">
                        <button type="button" class="btn btn-outline-success" onclick="addMailDepartment()" id="addDepartmentBtn">
                            <i class="fas fa-plus"></i> Add Department
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="resetMailDepartments()">
                            <i class="fas fa-refresh"></i> Reset to Default
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="card">
            <div class="card-body text-center">
                <div class="btn-group" role="group" aria-label="Mail handler actions">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fas fa-save"></i> Save Configuration
                    </button>
                    <button type="button" class="btn btn-secondary btn-lg" onclick="loadMailhandlerConfig()">
                        <i class="fas fa-refresh"></i> Reset Form
                    </button>
                    <button type="button" class="btn btn-info btn-lg" onclick="previewMailProcessing()">
                        <i class="fas fa-eye"></i> Preview Processing
                    </button>
                </div>
                <div class="mt-3">
                    <small class="text-muted">Mail handler will automatically process incoming emails and forward them to appropriate departments.</small>
                </div>
            </div>
        </div>

    </form>
</main>

<script>
    let departmentCount = 0;
    const maxDepartments = 10;

    // Load mail handler configuration when page loads
    document.addEventListener('DOMContentLoaded', function() {
        loadMailhandlerConfig();
        // warn once if username has no @
        let warnedUserNoAt = false;
        const u = document.getElementById('mailUsername');
        if (u) {
            u.addEventListener('blur', function() {
                const val = (u.value || '').trim();
                if (val && val.indexOf('@') === -1 && !warnedUserNoAt) {
                    alert('Note: Some servers accept usernames without an @ sign. If your login requires a full email address, include the domain.');
                    warnedUserNoAt = true;
                }
            });
        }
    });

    // Function to load current mail handler configuration
    function loadMailhandlerConfig() {
        fetch('api.php?action=getMailhandler', { credentials: 'include' })
            .then(r => r.json())
            .then(data => {
                if (!data.success) { throw new Error(data.error || 'Failed to load'); }
                const c = data.config || {};
                document.getElementById('mailServer').value = c.mailServer || '';
                document.getElementById('mailPort').value = c.mailPort || '993';
                document.getElementById('mailProtocol').value = c.mailProtocol || 'imap';
                document.getElementById('mailSecurity').value = c.mailSecurity || 'ssl';
                document.getElementById('mailUsername').value = c.mailUsername || '';
                document.getElementById('mailPassword').value = c.mailPassword || '';
                document.getElementById('mailCheckInterval').value = c.mailCheckInterval || '10';
                document.getElementById('mailDeleteAfter').checked = (c.mailDeleteAfter === '1' || c.mailDeleteAfter === 1);
                
                // departments
                const departmentsContainer = document.getElementById('mailDepartments');
                departmentsContainer.innerHTML = '';
                departmentCount = 0;
                const depts = Array.isArray(data.departments) ? data.departments : [];
                if (depts.length === 0) {
                    addMailDepartment();
                } else {
                    depts.forEach((d, idx) => addMailDepartmentPrefill(d.email, d.description, d.isDefault ? idx : -1, idx));
                }
            })
            .catch(err => {
                console.error(err);
                // ensure one default entry
                const departmentsContainer = document.getElementById('mailDepartments');
                if (departmentsContainer.children.length === 0) { addMailDepartment(); }
            });
    }

    

    function addMailDepartmentPrefill(email, description, defaultIdx, currentIdx) {
        if (departmentCount >= maxDepartments) { return; }
        const departmentsContainer = document.getElementById('mailDepartments');
        const departmentDiv = document.createElement('div');
        departmentDiv.className = 'department-entry border rounded p-3 mb-3';
        departmentDiv.id = 'department-' + departmentCount;
        const isDefault = (defaultIdx === currentIdx) ? 'checked' : '';
        departmentDiv.innerHTML = `
            <div class="row">
                <div class="col-sm-5">
                    <label class="form-label"><strong>Email Address:</strong></label>
                    <input type="email" class="form-control" name="departmentEmail[]" value="${email || ''}" placeholder="department@example.com" required>
                </div>
                <div class="col-sm-5">
                    <label class="form-label"><strong>Rules for forwarding:</strong></label>
                    <input type="text" class="form-control" name="departmentDescription[]" value="${description || ''}" placeholder="e.g., Customer Support, Sales, Technical" required>
                </div>
                <div class="col-sm-2">
                    <label class="form-label"><strong>Default:</strong></label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="defaultDepartment" value="${departmentCount}" ${isDefault}>
                        <label class="form-check-label">Set as default</label>
                    </div>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-12">
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeMailDepartment(${departmentCount})">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
            </div>
        `;
        departmentsContainer.appendChild(departmentDiv);
        departmentCount++;
        updateAddButtonState();
    }

    // Function to add a new mail department
    function addMailDepartment() {
        if (departmentCount >= maxDepartments) {
            alert('Maximum of ' + maxDepartments + ' departments allowed');
            return;
        }

        const departmentsContainer = document.getElementById('mailDepartments');
        const departmentDiv = document.createElement('div');
        departmentDiv.className = 'department-entry border rounded p-3 mb-3';
        departmentDiv.id = 'department-' + departmentCount;

        departmentDiv.innerHTML = `
            <div class="row">
                <div class="col-sm-5">
                    <label class="form-label"><strong>Email Address:</strong></label>
                    <input type="email" class="form-control" name="departmentEmail[]" placeholder="department@example.com" required>
                </div>
                <div class="col-sm-5">
                    <label class="form-label"><strong>Description:</strong></label>
                    <input type="text" class="form-control" name="departmentDescription[]" placeholder="e.g., Customer Support, Sales, Technical" required>
                </div>
                <div class="col-sm-2">
                    <label class="form-label"><strong>Default:</strong></label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="defaultDepartment" value="${departmentCount}" ${departmentCount === 0 ? 'checked' : ''}>
                        <label class="form-check-label">Set as default</label>
                    </div>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-12">
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeMailDepartment(${departmentCount})">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
            </div>
        `;

        departmentsContainer.appendChild(departmentDiv);
        departmentCount++;

        // Update add button state
        updateAddButtonState();
    }

    // Function to remove a mail department
    function removeMailDepartment(index) {
        const departmentDiv = document.getElementById('department-' + index);
        if (departmentDiv) {
            departmentDiv.remove();
            
            // Reindex remaining departments
            const departments = document.querySelectorAll('.department-entry');
            departments.forEach((dept, newIndex) => {
                dept.id = 'department-' + newIndex;
                const radio = dept.querySelector('input[type="radio"]');
                if (radio) {
                    radio.value = newIndex;
                }
                const removeBtn = dept.querySelector('button');
                if (removeBtn) {
                    removeBtn.onclick = () => removeMailDepartment(newIndex);
                }
            });
            
            departmentCount--;
            updateAddButtonState();
        }
    }

    // Function to update add button state
    function updateAddButtonState() {
        const addBtn = document.getElementById('addDepartmentBtn');
        if (departmentCount >= maxDepartments) {
            addBtn.disabled = true;
            addBtn.innerHTML = '<i class="fas fa-ban"></i> Max Departments Reached';
        } else {
            addBtn.disabled = false;
            addBtn.innerHTML = '<i class="fas fa-plus"></i> Add Department';
        }
    }

    // Function to reset mail departments to default
    function resetMailDepartments() {
        const departmentsContainer = document.getElementById('mailDepartments');
        departmentsContainer.innerHTML = '';
        departmentCount = 0;
        addMailDepartment(); // Add one default department
    }

    // Function to test mail connection
    function testMailConnection() {
        const server = document.getElementById('mailServer').value;
        const port = document.getElementById('mailPort').value;
        const protocol = document.getElementById('mailProtocol').value;
        const security = document.getElementById('mailSecurity').value;
        const username = document.getElementById('mailUsername').value;
        const password = document.getElementById('mailPassword').value;

        if (!server || !port || !username) {
            alert('Please fill in server, port and username before testing connection.');
            return;
        }

        // set loading state on the button
        const btn = document.getElementById('testConnectionBtn');
        if (btn) {
            btn.disabled = true;
            btn.setAttribute('data-prev', btn.innerHTML);
            btn.classList.remove('btn-outline-primary');
            btn.classList.add('btn-primary');
            btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Testing…';
        }

        const formData = new FormData();
        formData.append('mailServer', server);
        formData.append('mailPort', port);
        formData.append('mailProtocol', protocol);
        formData.append('mailSecurity', security);
        formData.append('mailUsername', username);
        formData.append('mailPassword', password);
        // include current authMethod if present in DOM
        const authEl = document.querySelector('[name="authMethod"]');
        if (authEl && authEl.value) { formData.append('authMethod', authEl.value); }

        fetch('api.php?action=mailTestConnection', {
            method: 'POST',
            body: formData,
            credentials: 'include'
        }).then(r => r.json()).then(data => {
            renderTestConnectionResult(data);
        }).catch(err => {
            console.error(err);
            alert('Connection test failed to run.');
        }).finally(() => {
            if (btn) {
                btn.disabled = false;
                const prev = btn.getAttribute('data-prev');
                if (prev !== null) { btn.innerHTML = prev; btn.removeAttribute('data-prev'); }
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-outline-primary');
            }
        });
    }

    function renderTestConnectionResult(data) {
        const lines = [];
        const ok = !!(data && data.success);
        lines.push(`Result: ${ok ? 'SUCCESS' : 'FAILURE'}`);
        if (data && data.error) { lines.push(`Error: ${data.error}`); }
        const c = (data && data.connection) ? data.connection : {};
        const d = (data && data.details) ? data.details : {};
        const usernameMasked = c.username_masked || c.username || '';
        lines.push('Connection');
        lines.push(`- Host: ${c.host || ''}`);
        lines.push(`- Port: ${c.port || ''}`);
        lines.push(`- Protocol: ${c.protocol || ''}`);
        lines.push(`- Security: ${c.encryption || ''}`);
        lines.push(`- Validate TLS cert: ${c.validate_cert ? 'yes' : 'no'}`);
        lines.push(`- Auth method: ${c.authMethod || 'password'}`);
        lines.push(`- Username: ${usernameMasked}`);
        if (d && (d.connected !== undefined || d.inboxAccessible !== undefined || d.foldersCount !== undefined)) {
            lines.push('Diagnostics');
            if (d.connected !== undefined) { lines.push(`- Connected: ${d.connected ? 'yes' : 'no'}`); }
            if (d.inboxAccessible !== undefined) { lines.push(`- INBOX accessible: ${d.inboxAccessible ? 'yes' : 'no'}`); }
            if (d.foldersCount !== undefined && d.foldersCount !== null) { lines.push(`- Folders: ${d.foldersCount}`); }
        }

        // Prefer generic modal from parent page; fallback to alert
        openGenericModal('Mail Server Test', lines);
    }

    function openGenericModal(title, lines) {
        const modalEl = document.getElementById('genericModal');
        const titleEl = document.getElementById('genericModalLabel');
        const bodyEl = document.getElementById('genericModalBody');
        if (!modalEl || !titleEl || !bodyEl || !(window.bootstrap && bootstrap.Modal)) {
            alert(lines.join('\n'));
            return;
        }
        titleEl.textContent = title || 'Info';
        const content = '<pre class="mb-0" style="white-space: pre-wrap">' + (lines || []).join('\n') + '</pre>';
        bodyEl.innerHTML = content;
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }

    // Function to show mail help
    function showMailHelp() {
        const helpText = `
Common Mail Server Settings:

Gmail IMAP:
- Server: imap.gmail.com
- Port: 993
- Protocol: IMAP
- Security: SSL/TLS

Outlook/Hotmail IMAP:
- Server: outlook.office365.com
- Port: 993
- Protocol: IMAP
- Security: SSL/TLS

Yahoo Mail IMAP:
- Server: imap.mail.yahoo.com
- Port: 993
- Protocol: IMAP
- Security: SSL/TLS

Note: You may need to enable "Less secure app access" or use app-specific passwords for some providers.
        `;
        alert(helpText);
    }

    // Function to preview mail processing
    function previewMailProcessing() {
        // TODO: Implement preview functionality
        console.log('Opening mail processing preview...');
        alert('Preview feature will show how emails will be processed and forwarded.');
    }

    // Form validation
    document.getElementById('mailhandlerForm').addEventListener('submit', function(e) {
        const defaultDept = document.querySelector('input[name="defaultDepartment"]:checked');
        if (!defaultDept) {
            e.preventDefault();
            alert('Please select a default department.');
            return;
        }

        const departments = document.querySelectorAll('input[name="departmentEmail[]"]');
        let hasValidDepartments = false;
        departments.forEach(dept => {
            if (dept.value.trim() !== '') {
                hasValidDepartments = true;
            }
        });

        if (!hasValidDepartments) {
            e.preventDefault();
            alert('Please add at least one department.');
            return;
        }

        // send via API instead of direct POST navigation
        e.preventDefault();
        const form = document.getElementById('mailhandlerForm');
        const formData = new FormData(form);
        // Remove conflicting hidden form action that would override the API query param
        if (formData.has('action')) { formData.delete('action'); }
        fetch('api.php?action=saveMailhandler', {
            method: 'POST',
            body: formData,
            credentials: 'include'
        }).then(r => r.json()).then(data => {
            if (data && data.success) {
                alert('Configuration saved');
                loadMailhandlerConfig();
            } else {
                alert('Save failed: ' + (data.error || 'Unknown error'));
            }
        }).catch(err => {
            console.error(err);
            alert('Save failed');
        });
    });
</script>

<style>
    .card-header h5 {
        color: #495057;
    }
    .btn-group .btn {
        min-width: 150px;
    }
    .text-muted {
        font-size: 0.875rem;
    }
    .department-entry {
        background-color: #f8f9fa;
        border-color: #dee2e6 !important;
    }
    .department-entry:hover {
        background-color: #e9ecef;
    }
    .form-check-input:checked {
        background-color: #0d6efd;
        border-color: #0d6efd;
    }
    .alert-info {
        background-color: #d1ecf1;
        border-color: #bee5eb;
        color: #0c5460;
    }
</style> 