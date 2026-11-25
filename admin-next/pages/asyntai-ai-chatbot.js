/* Asyntai AI Chatbot settings page for Admin2 (Grav 2). */
(function () {
  var TAG = window.__GRAV_PAGE_TAG || 'grav-asyntai-ai-chatbot--page';
  if (customElements.get(TAG)) { return; }

  var CSS = [
    ':host{display:block;color:var(--foreground)}',
    '.wrap{max-width:820px;padding:8px 0 40px}',
    '.panel{border:1px solid var(--border);background:var(--background);padding:20px 22px;margin:0 0 16px}',
    'h3{margin:0 0 10px;font-size:20px;font-weight:700;letter-spacing:-.02em}',
    'p{margin:0 0 10px;font-size:14px;color:var(--muted-foreground);max-width:94ch}',
    '.state{color:var(--foreground);font-weight:600;font-size:15px}',
    '.dot{display:inline-block;width:8px;height:8px;margin-inline-end:9px;vertical-align:middle;background:#1a7f37}',
    '.btn{display:inline-block;background:#111;color:#fff;border:1px solid #111;padding:10px 20px;font-size:14px;font-weight:600;cursor:pointer;border-radius:0}',
    '.btn:hover{background:#000}',
    'label.check{display:flex;gap:10px;align-items:flex-start;margin:14px 0;font-size:14px;cursor:pointer}',
    'label.check input{margin-top:3px}',
    '.fine{display:block;color:var(--muted-foreground);font-size:12px;margin-top:2px}',
    '.links{display:flex;gap:20px;flex-wrap:wrap;margin-top:10px}',
    '.links a{color:var(--muted-foreground);font-size:14px}',
    '.danger{color:#b32d2e !important}',
    '.alert{border:1px solid var(--border);border-inline-start-width:3px;padding:10px 14px;margin:0 0 16px;font-size:14px}'
  ].join('');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  class AsyntaiChatbotPage extends HTMLElement {
    connectedCallback() {
      if (!this.shadowRoot) { this.attachShadow({ mode: 'open' }); }
      this._status = null;
      this._popup = null;
      this._render('<div class="panel"><p>Loading...</p></div>');
      this._load();
    }

    _url(path) {
      return (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1') + path;
    }

    _headers(json) {
      var h = {};
      if (window.__GRAV_API_TOKEN) { h['X-API-Token'] = window.__GRAV_API_TOKEN; }
      if (json) { h['Content-Type'] = 'application/json'; }
      return h;
    }

    async _api(method, path, body) {
      var opts = { method: method, headers: this._headers(!!body) };
      if (body) { opts.body = JSON.stringify(body); }
      var resp = await fetch(this._url(path), opts);
      var parsed = null;
      try { parsed = await resp.json(); } catch (e) { parsed = null; }
      if (!resp.ok) {
        var msg = parsed && (parsed.detail || parsed.message || parsed.title);
        throw new Error(msg || ('Request failed (' + resp.status + ')'));
      }
      return parsed ? parsed.data : null;
    }

    _task(task, extra) {
      var body = extra || {};
      body.task = task;
      return this._api('POST', '/asyntai-ai-chatbot/action', body);
    }

    async _load() {
      try {
        this._status = await this._api('GET', '/asyntai-ai-chatbot/status');
        this._draw();
        if (this._status.sharing) { this._showSync(); }
      } catch (e) {
        this._render('<div class="panel"><p>' + esc(e.message) + '</p></div>');
      }
    }

    _render(inner) {
      this.shadowRoot.innerHTML = '<style>' + CSS + '</style><div class="wrap"><div class="alert" id="alert" hidden></div>' + inner + '</div>';
    }

    _say(message, ok) {
      var box = this.shadowRoot.getElementById('alert');
      if (!box) { return; }
      box.hidden = false;
      box.style.borderInlineStartColor = ok ? '#1a7f37' : '#b32d2e';
      box.textContent = message;
    }

    _draw() {
      var s = this._status || {};
      if (!s.connected) {
        this._render(
          '<div class="panel"><h3>Connect your site to Asyntai</h3>' +
          '<p>Create a free Asyntai account or sign in. The chatbot then appears on every page of this site.</p>' +
          '<label class="check"><input type="checkbox" id="share" checked><span>Share my published pages with the chatbot, so its answers stay up to date.' +
          '<span class="fine">Only published pages that any visitor can open. You can switch this off at any time.</span></span></label>' +
          '<p><button type="button" class="btn" id="connect">Get started</button></p></div>'
        );
        var self = this;
        this.shadowRoot.getElementById('connect').addEventListener('click', function () {
          self._connect(self.shadowRoot.getElementById('share').checked);
        });
        return;
      }

      this._render(
        '<div class="panel"><p class="state"><span class="dot"></span>The chatbot is live on your site</p>' +
        (s.account_email ? '<p>Connected as ' + esc(s.account_email) + '</p>' : '') +
        '<label class="check"><input type="checkbox" id="toggle"' + (s.sharing ? ' checked' : '') + '><span>Share my published pages with the chatbot' +
        '<span class="fine" id="sync">' + (s.sharing ? 'Checking what Asyntai has read...' : 'Switched off.') + '</span></span></label>' +
        '<p class="links"><a href="https://asyntai.com/dashboard" target="_blank" rel="noopener">Open the Asyntai dashboard</a>' +
        '<a href="https://asyntai.com/dashboard#setup" target="_blank" rel="noopener">Change how the AI answers</a>' +
        '<a href="#" class="danger" id="disconnect">Disconnect</a></p></div>'
      );
      var me = this;
      this.shadowRoot.getElementById('toggle').addEventListener('change', function (e) { me._toggle(e.target); });
      this.shadowRoot.getElementById('disconnect').addEventListener('click', function (e) { e.preventDefault(); me._disconnect(); });
    }

    // The window opens inside the click, before any network call, or the
    // browser treats it as unrequested and blocks it.
    async _connect(share) {
      this._popup = window.open('about:blank', 'asyntai_connect', 'width=820,height=740,scrollbars=yes,resizable=yes');
      if (!this._popup) { this._say('Your browser blocked the sign-in window. Please allow pop-ups for this site and try again.', false); return; }
      this._say('Preparing the connection...', true);
      try {
        var data = await this._task('prepare', { share: !!share });
        this._popup.location = data.url;
        this._say('Waiting for you to sign in to Asyntai in the new window...', true);
        this._poll(data.state, 0);
      } catch (e) {
        try { this._popup.close(); } catch (x) {}
        this._say(e.message, false);
      }
    }

    async _poll(state, attempt) {
      var me = this;
      if (attempt > 300) { this._say('The sign-in took too long. Please press Get started again.', false); return; }
      try {
        var data = await this._task('poll', { state: state });
        if (data && data.ready && data.site_id) {
          this._say('Connected. Saving...', true);
          await this._task('finish', { site_id: data.site_id, script_url: data.script_url || '', account_email: data.account_email || '' });
          window.__GRAV_TOAST && window.__GRAV_TOAST.success('Asyntai connected');
          this._load();
          return;
        }
        setTimeout(function () { me._poll(state, attempt + 1); }, 2000);
      } catch (e) {
        if (/expired|Unknown handshake/i.test(e.message)) { this._say(e.message, false); return; }
        setTimeout(function () { me._poll(state, attempt + 1); }, 3000);
      }
    }

    async _toggle(box) {
      var wanted = box.checked;
      if (wanted && !(this._status && this._status.has_token)) {
        // Connected before page sharing existed: Asyntai has no key for this
        // site yet, so the owner signs in once more to hand one over.
        box.checked = false;
        this._connect(true);
        return;
      }
      try {
        var data = await this._task('share', { enabled: wanted });
        if (data && data.needs_connect) { box.checked = false; this._connect(true); return; }
        this._status.sharing = wanted;
        this._say(wanted ? 'Page sharing is on.' : 'Page sharing is off. Asyntai removes the shared pages at its next read.', true);
        var line = this.shadowRoot.getElementById('sync');
        if (line) { line.textContent = wanted ? 'Asyntai reads your pages once a day.' : 'Switched off.'; }
        if (wanted) { this._showSync(); }
      } catch (e) {
        box.checked = !wanted;
        this._say(e.message, false);
      }
    }

    async _showSync(tries) {
      var me = this;
      tries = tries || 0;
      var line = this.shadowRoot.getElementById('sync');
      if (!line) { return; }
      try {
        var data = await this._task('sync', {});
        var s = data && data.sync;
        if (!s) { line.textContent = 'Asyntai has not read your pages yet.'; return; }
        if (s.status === 'syncing') {
          line.textContent = 'Asyntai is reading your pages now...';
          if (tries < 20) { setTimeout(function () { me._showSync(tries + 1); }, 5000); }
          return;
        }
        if (s.status === 'failed') { line.textContent = 'The last read failed: ' + (s.error || 'unknown error'); return; }
        line.textContent = 'Pages shared: ' + (s.count || 0) + ' items. Asyntai checks them again for changes.';
      } catch (e) {
        line.textContent = '';
      }
    }

    async _disconnect() {
      var ok = window.__GRAV_DIALOGS
        ? await window.__GRAV_DIALOGS.confirm({
            title: 'Disconnect Asyntai?',
            message: 'The chatbot disappears from your pages, and page sharing stops.',
            confirmLabel: 'Disconnect',
            variant: 'destructive'
          })
        : false;
      if (!ok) { return; }
      try {
        await this._task('disconnect', {});
        this._load();
      } catch (e) {
        this._say(e.message, false);
      }
    }
  }

  customElements.define(TAG, AsyntaiChatbotPage);
})();
