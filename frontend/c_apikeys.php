<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4" id="contentMain">
  <h1>API Keys</h1>
  <p>Create and manage API keys for accessing the API.</p>

  <div class="card mb-3">
    <div class="card-body">
      <form id="createApiKeyForm" class="row g-2">
        <div class="col-sm-6 col-md-4">
          <input type="text" class="form-control" id="apiKeyName" placeholder="Name (e.g. Server A)" maxlength="80">
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary">Create API Key</button>
        </div>
      </form>
      <div id="createApiKeyResult" class="mt-2" style="display:none;"></div>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-striped align-middle" id="apiKeysTable">
      <thead>
        <tr>
          <th style="width:28%">Name</th>
          <th style="width:24%">Key</th>
          <th style="width:12%">Status</th>
          <th style="width:18%">Created</th>
          <th style="width:18%">Last used</th>
          <th class="text-end" style="width:10%">Actions</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>

  <script>
  (function(){
    function fmt(ts){ if(!ts) return '-'; const d=new Date(ts*1000); return d.toLocaleString(); }
    function escapeHtml(s){ return s.replace(/[&<>\"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[c])); }

    function renderRows(list){
      const tbody = document.querySelector('#apiKeysTable tbody');
      tbody.innerHTML = '';
      list.forEach(item => {
        const tr = document.createElement('tr');
        const safeName = escapeHtml(item.BNAME||'');
        const keyCell = item.BMASKEDKEY ? `<code>${escapeHtml(item.BMASKEDKEY)}</code>` : '<span class="text-muted">********</span>';
        tr.innerHTML = `
          <td>${safeName}</td>
          <td>${keyCell}</td>
          <td><span class="badge ${item.BSTATUS==='active'?'bg-success':'bg-secondary'}">${item.BSTATUS}</span></td>
          <td>${fmt(parseInt(item.BCREATED||0))}</td>
          <td>${fmt(parseInt(item.BLASTUSED||0))}</td>
          <td class="text-end">
            <div class="btn-group btn-group-sm" role="group">
              <button class="btn btn-${item.BSTATUS==='active'?'warning':'success'} toggleBtn" data-id="${item.BID}" data-next="${item.BSTATUS==='active'?'paused':'active'}">${item.BSTATUS==='active'?'Pause':'Resume'}</button>
              <button class="btn btn-danger deleteBtn" data-id="${item.BID}">Delete</button>
            </div>
          </td>`;
        tbody.appendChild(tr);
      });
    }

    function loadKeys(){
      $.getJSON('api.php', { action: 'getApiKeys' }, function(res){
        if(res && res.success){ renderRows(res.keys || []); }
      });
    }

    function showCreateResult(key){
      const box = $('#createApiKeyResult');
      const html = `<div class="alert alert-success">
        <div><strong>API key created.</strong> Copy it now; it will be hidden on refresh.</div>
        <div class="mt-2"><code style="font-size:0.95rem">${key}</code>
        <button type="button" class="btn btn-sm btn-outline-secondary ms-2" id="copyNewKey">Copy</button></div>
      </div>`;
      box.html(html).show();
      $('#copyNewKey').on('click', function(){
        navigator.clipboard.writeText(key);
      });
    }

    $(document).on('click', '.toggleBtn', function(){
      const id = $(this).data('id');
      const status = $(this).data('next');
      $.post('api.php', { action: 'setApiKeyStatus', id: id, status: status }, function(res){
        if(res && res.success){ loadKeys(); }
      }, 'json');
    });

    $(document).on('click', '.deleteBtn', function(){
      const id = $(this).data('id');
      if(!confirm('Delete this API key? This cannot be undone.')) return;
      $.post('api.php', { action: 'deleteApiKey', id: id }, function(res){
        if(res && res.success){ loadKeys(); }
      }, 'json');
    });

    $('#createApiKeyForm').on('submit', function(e){
      e.preventDefault();
      const name = $('#apiKeyName').val().trim();
      $.post('api.php', { action: 'createApiKey', name: name }, function(res){
        if(res && res.success){
          showCreateResult(res.key);
          $('#apiKeyName').val('');
          loadKeys();
        }
      }, 'json');
    });

    loadKeys();
  })();
  </script>
</main> 