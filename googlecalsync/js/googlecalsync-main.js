/* global OC, OCA */
'use strict';

(function () {

  // -------------------------------------------------------------------------
  // Utilities
  // -------------------------------------------------------------------------

  function apiUrl(path) {
    return OC.generateUrl('/apps/googlecalsync' + path);
  }

  async function apiFetch(path, opts = {}) {
    const defaults = {
      headers: {
        'Content-Type': 'application/json',
        'requesttoken': OC.requestToken,
      },
    };
    const options = Object.assign({}, defaults, opts);
    if (options.body && typeof options.body === 'object') {
      options.body = JSON.stringify(options.body);
    }
    const res = await fetch(apiUrl(path), options);
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      throw new Error(json.error || 'Request failed (' + res.status + ')');
    }
    return json;
  }

  // -------------------------------------------------------------------------
  // State
  // -------------------------------------------------------------------------

  const state = {
    connected: false,
    email: null,
    googleCalendars: [],
    nextcloudCalendars: [],
    mappings: [],
    defaultInterval: 5,
    loading: false,
    error: null,
    // New-mapping form
    form: {
      id: null,
      mode: 'one_to_one',
      googleCalendarIds: [],
      nextcloudCalendar: '',
      readOnly: false,
      syncIntervalMinutes: 5,
    },
  };

  // -------------------------------------------------------------------------
  // Render
  // -------------------------------------------------------------------------

  function render() {
    const root = document.getElementById('googlecalsync-app');
    if (!root) return;
    root.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.className = 'gcs-wrap';

    wrap.appendChild(renderHeader());

    if (state.loading) {
      const p = document.createElement('p');
      p.className = 'gcs-loading';
      p.textContent = 'Loading…';
      wrap.appendChild(p);
    } else if (state.error) {
      wrap.appendChild(renderError(state.error));
    } else if (!state.connected) {
      wrap.appendChild(renderConnectPanel());
    } else {
      wrap.appendChild(renderConnectedPanel());
    }

    root.appendChild(wrap);
  }

  function renderHeader() {
    const h = document.createElement('div');
    h.className = 'gcs-header';
    h.innerHTML = '<h2>Google Calendar Sync</h2>';
    return h;
  }

  function renderError(msg) {
    const d = document.createElement('div');
    d.className = 'gcs-error';
    d.textContent = msg;
    return d;
  }

  // -------------------------------------------------------------------------
  // Connect panel (OAuth setup)
  // -------------------------------------------------------------------------

  function renderConnectPanel() {
    const wrap = document.createElement('div');
    wrap.className = 'gcs-section';

    wrap.innerHTML = `
      <h3>Connect Google Account</h3>
      <p>
        To get started, create a Google Cloud project, enable the <strong>Google Calendar API</strong>,
        and create an <strong>OAuth 2.0 client ID</strong> (type: <em>Desktop app</em>).
        Download the <code>credentials.json</code> file and paste its contents below.
      </p>
      <p>
        <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">
          Open Google Cloud Console →
        </a>
      </p>
      <label for="gcs-creds-input">Paste credentials.json contents</label>
      <textarea id="gcs-creds-input" rows="6" placeholder='{"installed":{"client_id":"…"}}'></textarea>
      <button id="gcs-connect-btn" class="button primary">Connect Google Account</button>
    `;

    wrap.querySelector('#gcs-connect-btn').addEventListener('click', async () => {
      const raw = wrap.querySelector('#gcs-creds-input').value.trim();
      if (!raw) { alert('Please paste your credentials.json contents.'); return; }
      try {
        JSON.parse(raw);
      } catch {
        alert('Invalid JSON — please double-check the contents.'); return;
      }

      state.loading = true; render();

      try {
        const data = await apiFetch('/oauth/start', {
          method: 'POST',
          body: { credentials_json: raw },
        });
        // Open the Google consent page in the same window
        window.location.href = data.auth_url;
      } catch (e) {
        state.loading = false;
        state.error = e.message;
        render();
      }
    });

    return wrap;
  }

  // -------------------------------------------------------------------------
  // Connected panel
  // -------------------------------------------------------------------------

  function renderConnectedPanel() {
    const wrap = document.createElement('div');

    // Account info + disconnect
    const acct = document.createElement('div');
    acct.className = 'gcs-section gcs-account';
    acct.innerHTML = `
      <p>Connected as <strong>${escHtml(state.email || 'Unknown')}</strong></p>
      <button id="gcs-disconnect-btn" class="button">Disconnect</button>
    `;
    acct.querySelector('#gcs-disconnect-btn').addEventListener('click', async () => {
      if (!confirm('Disconnect your Google account? This will remove all tokens.')) return;
      await apiFetch('/oauth/disconnect', { method: 'POST' });
      state.connected = false;
      state.email = null;
      state.mappings = [];
      render();
    });
    wrap.appendChild(acct);

    // Default sync interval
    const settings = document.createElement('div');
    settings.className = 'gcs-section';
    settings.innerHTML = `
      <h3>Default Sync Interval</h3>
      <label for="gcs-default-interval">Sync every
        <input id="gcs-default-interval" type="number" min="1" max="1440"
               value="${state.defaultInterval}" style="width:70px"> minutes
      </label>
      <button id="gcs-save-settings" class="button">Save</button>
    `;
    settings.querySelector('#gcs-save-settings').addEventListener('click', async () => {
      const val = parseInt(settings.querySelector('#gcs-default-interval').value, 10) || 5;
      await apiFetch('/settings', { method: 'POST', body: { default_sync_interval_minutes: val } });
      state.defaultInterval = val;
      state.form.syncIntervalMinutes = val;
      render();
    });
    wrap.appendChild(settings);

    // Existing mappings
    const mappingsList = document.createElement('div');
    mappingsList.className = 'gcs-section';
    mappingsList.innerHTML = '<h3>Calendar Mappings</h3>';

    if (state.mappings.length === 0) {
      const p = document.createElement('p');
      p.textContent = 'No mappings yet. Add one below.';
      mappingsList.appendChild(p);
    } else {
      const ul = document.createElement('ul');
      ul.className = 'gcs-mappings-list';
      for (const m of state.mappings) {
        ul.appendChild(renderMappingItem(m));
      }
      mappingsList.appendChild(ul);
    }
    wrap.appendChild(mappingsList);

    // Sync Now button
    const syncNow = document.createElement('div');
    syncNow.className = 'gcs-section';
    syncNow.innerHTML = `<button id="gcs-sync-now" class="button primary">Sync Now</button>
      <span id="gcs-sync-result" style="margin-left:10px"></span>`;
    syncNow.querySelector('#gcs-sync-now').addEventListener('click', async () => {
      const result = syncNow.querySelector('#gcs-sync-result');
      result.textContent = 'Syncing…';
      try {
        const data = await apiFetch('/sync/run', { method: 'POST' });
        const total = (data.results || []).reduce((s, r) => s + (r.pulled || 0) + (r.pushed || 0), 0);
        result.textContent = `Done — ${total} event(s) processed.`;
        await loadMappings();
        render();
      } catch (e) {
        result.textContent = 'Error: ' + e.message;
      }
    });
    wrap.appendChild(syncNow);

    // Add/edit mapping form
    wrap.appendChild(renderMappingForm());

    return wrap;
  }

  function renderMappingItem(m) {
    const li = document.createElement('li');
    li.className = 'gcs-mapping-item';

    const googleIds = Array.isArray(m.google_calendar_ids) ? m.google_calendar_ids : [];
    const googleNames = googleIds.map(id => {
      const cal = state.googleCalendars.find(c => c.id === id);
      return cal ? cal.summary : id;
    }).join(', ');

    const ncCal = state.nextcloudCalendars.find(c => c.uri === m.nextcloud_calendar);
    const ncName = ncCal ? ncCal.displayname : m.nextcloud_calendar;

    const modeLabel = m.mode === 'many_to_one'
      ? `Many → One (${googleIds.length} Google calendars)`
      : 'One ↔ One';

    li.innerHTML = `
      <div class="gcs-mapping-info">
        <strong>${escHtml(googleNames)}</strong>
        <span class="gcs-arrow">${m.read_only ? '→' : '↔'}</span>
        <strong>${escHtml(ncName)}</strong>
        <span class="gcs-badge">${modeLabel}</span>
        <span class="gcs-badge">${m.read_only ? 'Read-only' : 'Bidirectional'}</span>
        <span class="gcs-badge">Every ${m.sync_interval_minutes} min</span>
        ${m.last_synced_at ? `<span class="gcs-muted">Last sync: ${new Date(m.last_synced_at).toLocaleString()}</span>` : ''}
      </div>
      <div class="gcs-mapping-actions">
        <button class="gcs-edit-btn button" data-id="${m.id}">Edit</button>
        <button class="gcs-delete-btn button" data-id="${m.id}">Delete</button>
      </div>
    `;

    li.querySelector('.gcs-edit-btn').addEventListener('click', () => {
      state.form = {
        id: m.id,
        mode: m.mode,
        googleCalendarIds: googleIds,
        nextcloudCalendar: m.nextcloud_calendar,
        readOnly: m.read_only,
        syncIntervalMinutes: m.sync_interval_minutes,
      };
      render();
      document.querySelector('.gcs-form-section')?.scrollIntoView({ behavior: 'smooth' });
    });

    li.querySelector('.gcs-delete-btn').addEventListener('click', async () => {
      if (!confirm('Delete this mapping?')) return;
      await apiFetch('/mappings/' + m.id, { method: 'DELETE' });
      await loadMappings();
      render();
    });

    return li;
  }

  // -------------------------------------------------------------------------
  // Mapping form
  // -------------------------------------------------------------------------

  function renderMappingForm() {
    const f = state.form;
    const wrap = document.createElement('div');
    wrap.className = 'gcs-section gcs-form-section';
    wrap.innerHTML = `<h3>${f.id ? 'Edit Mapping' : 'Add New Mapping'}</h3>`;

    // Mode selector
    const modeDiv = document.createElement('div');
    modeDiv.className = 'gcs-field';
    modeDiv.innerHTML = `
      <label>Sync Mode</label>
      <div class="gcs-radio-group">
        <label>
          <input type="radio" name="gcs-mode" value="one_to_one" ${f.mode === 'one_to_one' ? 'checked' : ''}>
          One-to-One (one Google calendar ↔ one Nextcloud calendar)
        </label>
        <label>
          <input type="radio" name="gcs-mode" value="many_to_one" ${f.mode === 'many_to_one' ? 'checked' : ''}>
          Many-to-One (multiple Google calendars → one Nextcloud calendar, read-only pull)
        </label>
      </div>
    `;
    modeDiv.querySelectorAll('input[name="gcs-mode"]').forEach(r => {
      r.addEventListener('change', () => {
        state.form.mode = r.value;
        if (r.value === 'many_to_one') {
          state.form.readOnly = true;
        }
        render();
        document.querySelector('.gcs-form-section')?.scrollIntoView({ behavior: 'smooth' });
      });
    });
    wrap.appendChild(modeDiv);

    // Google calendar select
    const gcDiv = document.createElement('div');
    gcDiv.className = 'gcs-field';
    const isMulti = f.mode === 'many_to_one';
    gcDiv.innerHTML = `
      <label for="gcs-google-cal">Google Calendar${isMulti ? '(s) — hold Ctrl/Cmd to select multiple' : ''}</label>
      <select id="gcs-google-cal" ${isMulti ? 'multiple size="5"' : ''}>
        ${state.googleCalendars.map(c => `
          <option value="${escAttr(c.id)}" ${f.googleCalendarIds.includes(c.id) ? 'selected' : ''}>
            ${escHtml(c.summary)}${c.primary ? ' (primary)' : ''}
          </option>
        `).join('')}
      </select>
    `;
    gcDiv.querySelector('#gcs-google-cal').addEventListener('change', e => {
      const sel = e.target;
      state.form.googleCalendarIds = Array.from(sel.selectedOptions).map(o => o.value);
    });
    wrap.appendChild(gcDiv);

    // Nextcloud calendar select
    const ncDiv = document.createElement('div');
    ncDiv.className = 'gcs-field';
    ncDiv.innerHTML = `
      <label for="gcs-nc-cal">Nextcloud Calendar</label>
      <select id="gcs-nc-cal">
        <option value="">— choose —</option>
        ${state.nextcloudCalendars.map(c => `
          <option value="${escAttr(c.uri)}" ${f.nextcloudCalendar === c.uri ? 'selected' : ''}>
            ${escHtml(c.displayname || c.uri)}
          </option>
        `).join('')}
      </select>
    `;
    ncDiv.querySelector('#gcs-nc-cal').addEventListener('change', e => {
      state.form.nextcloudCalendar = e.target.value;
    });
    wrap.appendChild(ncDiv);

    // Read-only toggle (disabled for many_to_one, which is always read-only)
    if (f.mode === 'one_to_one') {
      const roDiv = document.createElement('div');
      roDiv.className = 'gcs-field';
      roDiv.innerHTML = `
        <label>
          <input type="checkbox" id="gcs-readonly" ${f.readOnly ? 'checked' : ''}>
          Read-only (Google → Nextcloud only; do not push Nextcloud events back to Google)
        </label>
      `;
      roDiv.querySelector('#gcs-readonly').addEventListener('change', e => {
        state.form.readOnly = e.target.checked;
      });
      wrap.appendChild(roDiv);
    }

    // Sync interval
    const intervalDiv = document.createElement('div');
    intervalDiv.className = 'gcs-field';
    intervalDiv.innerHTML = `
      <label for="gcs-interval">Sync every
        <input id="gcs-interval" type="number" min="1" max="1440"
               value="${f.syncIntervalMinutes}" style="width:70px"> minutes
      </label>
    `;
    intervalDiv.querySelector('#gcs-interval').addEventListener('input', e => {
      state.form.syncIntervalMinutes = parseInt(e.target.value, 10) || 5;
    });
    wrap.appendChild(intervalDiv);

    // Buttons
    const btnDiv = document.createElement('div');
    btnDiv.className = 'gcs-field gcs-btn-row';
    btnDiv.innerHTML = `
      <button id="gcs-save-mapping" class="button primary">
        ${f.id ? 'Update Mapping' : 'Add Mapping'}
      </button>
      ${f.id ? '<button id="gcs-cancel-edit" class="button">Cancel</button>' : ''}
    `;

    btnDiv.querySelector('#gcs-save-mapping').addEventListener('click', async () => {
      const frm = state.form;
      if (frm.googleCalendarIds.length === 0) {
        alert('Please select at least one Google calendar.'); return;
      }
      if (!frm.nextcloudCalendar) {
        alert('Please select a Nextcloud calendar.'); return;
      }
      const body = {
        id:                   frm.id,
        mode:                 frm.mode,
        google_calendar_ids:  frm.googleCalendarIds,
        nextcloud_calendar:   frm.nextcloudCalendar,
        read_only:            frm.mode === 'many_to_one' ? true : frm.readOnly,
        sync_interval_minutes: frm.syncIntervalMinutes,
      };
      await apiFetch('/mappings', { method: 'POST', body });
      state.form = {
        id: null, mode: 'one_to_one', googleCalendarIds: [],
        nextcloudCalendar: '', readOnly: false,
        syncIntervalMinutes: state.defaultInterval,
      };
      await loadMappings();
      render();
    });

    if (f.id) {
      btnDiv.querySelector('#gcs-cancel-edit').addEventListener('click', () => {
        state.form = {
          id: null, mode: 'one_to_one', googleCalendarIds: [],
          nextcloudCalendar: '', readOnly: false,
          syncIntervalMinutes: state.defaultInterval,
        };
        render();
      });
    }

    wrap.appendChild(btnDiv);
    return wrap;
  }

  // -------------------------------------------------------------------------
  // Data loaders
  // -------------------------------------------------------------------------

  async function loadAll() {
    state.loading = true;
    state.error = null;
    render();

    try {
      // Check OAuth status
      const statusData = await apiFetch('/oauth/status');
      state.connected = statusData.connected;
      state.email     = statusData.email;

      if (state.connected) {
        const [gcData, ncData, mappingsData, settingsData] = await Promise.all([
          apiFetch('/calendars/google'),
          apiFetch('/calendars/nextcloud'),
          apiFetch('/mappings'),
          apiFetch('/settings'),
        ]);
        state.googleCalendars    = gcData.calendars    || [];
        state.nextcloudCalendars = ncData.calendars    || [];
        state.mappings           = mappingsData.mappings || [];
        state.defaultInterval    = settingsData.default_sync_interval_minutes || 5;
        state.form.syncIntervalMinutes = state.defaultInterval;
      }

      // Check for OAuth callback result in URL
      const params = new URLSearchParams(window.location.search);
      if (params.has('oauth_success')) {
        state.connected = true;
        history.replaceState({}, '', window.location.pathname);
      } else if (params.has('oauth_error')) {
        state.error = 'Google auth error: ' + decodeURIComponent(params.get('oauth_error'));
        history.replaceState({}, '', window.location.pathname);
      }
    } catch (e) {
      state.error = e.message;
    }

    state.loading = false;
    render();
  }

  async function loadMappings() {
    const data     = await apiFetch('/mappings');
    state.mappings = data.mappings || [];
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function escAttr(str) { return escHtml(str); }

  // -------------------------------------------------------------------------
  // Bootstrap
  // -------------------------------------------------------------------------

  document.addEventListener('DOMContentLoaded', () => {
    if (!document.getElementById('googlecalsync-app')) return;
    loadAll();
  });

  // Also handle immediate execution if DOM is already ready
  if (document.readyState !== 'loading') {
    if (document.getElementById('googlecalsync-app')) {
      loadAll();
    }
  }

})();
