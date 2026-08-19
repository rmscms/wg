(function () {
    const root = document.getElementById('account-modal');
    if (!root) {
        return;
    }

    const body = document.getElementById('am-body');
    const empty = document.getElementById('am-empty');
    const toast = document.getElementById('am-toast');
    const form = document.getElementById('am-form');
    const saveBtn = document.getElementById('am-save');
    let currentId = null;
    let currentTab = 'view';

    function showToast(message, ok) {
        if (!toast) {
            return;
        }
        toast.hidden = !message;
        toast.textContent = message || '';
        toast.className = 'am-toast ' + (ok ? 'is-ok' : 'is-err');
        if (message) {
            window.setTimeout(function () {
                if (toast.textContent === message) {
                    toast.hidden = true;
                }
            }, 2800);
        }
    }

    function setTab(tab) {
        currentTab = tab === 'edit' || tab === 'share' ? tab : 'view';
        root.querySelectorAll('.am-tab').forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-am-tab') === currentTab);
        });
        root.querySelectorAll('.am-pane').forEach(function (pane) {
            pane.hidden = pane.getAttribute('data-am-pane') !== currentTab;
        });
        if (saveBtn) {
            saveBtn.hidden = currentTab !== 'edit';
        }
    }

    function toggleExpiry() {
        const selected = form.querySelector('input[name="expiry_mode"]:checked');
        const isFirst = selected && selected.value === 'first_connect';
        document.getElementById('am-expiry-fixed').hidden = isFirst;
        document.getElementById('am-expiry-first').hidden = !isFirst;
        form.querySelectorAll('.am-expiry-tab').forEach(function (tab) {
            const input = tab.querySelector('input[type="radio"]');
            tab.classList.toggle('is-active', !!(input && input.checked));
        });
    }

    function fillOnline(data) {
        const chip = document.getElementById('am-online');
        if (!chip || !data) {
            return;
        }
        const state = data.state || (data.online ? 'online' : 'offline');
        chip.className = 'online-status online-chip is-' + state;
        chip.setAttribute('data-live-online', String(currentId || ''));
        chip.title = data.title || '';
        const dot = chip.querySelector('.online-dot');
        if (dot) {
            dot.className = 'online-dot ' + (state === 'online' ? 'is-online' : (state === 'disabled' ? 'is-disconnected' : 'is-offline'));
        }
        const label = chip.querySelector('.online-label');
        const meta = chip.querySelector('.online-meta');
        if (label) {
            label.textContent = data.label || '—';
        }
        if (meta) {
            meta.textContent = data.relative && data.relative !== '—' ? 'handshake: ' + data.relative : '';
        }
    }

    function fillAccount(account) {
        currentId = account.id;
        empty.hidden = true;
        document.getElementById('am-title').textContent = account.name;
        const badge = document.getElementById('am-badge');
        badge.className = 'badge ' + (account.badge.class || '');
        badge.textContent = account.badge.label || '—';
        document.getElementById('am-ip').textContent = account.ip_address;
        fillOnline(account.online);
        document.getElementById('am-stat-volume').textContent = account.volume_used_human + ' / ' + account.volume_limit_human;
        document.getElementById('am-stat-speed').textContent = account.speed_human;
        document.getElementById('am-stat-expiry').textContent = account.expiry_display;
        document.getElementById('am-stat-key').textContent = account.public_key_short;
        const progress = document.getElementById('am-volume-progress');
        if (account.volume_percent === null || account.volume_percent === undefined) {
            progress.hidden = true;
        } else {
            progress.hidden = false;
            progress.className = 'am-progress' + (account.volume_percent >= 90 ? ' is-danger' : (account.volume_percent >= 70 ? ' is-warn' : ''));
            progress.querySelector('span').style.width = account.volume_percent + '%';
        }

        document.getElementById('am-d-name').textContent = account.name;
        document.getElementById('am-d-ip').textContent = account.ip_address;
        document.getElementById('am-d-status').textContent = account.badge.label;
        document.getElementById('am-d-speed').textContent = account.speed_human;
        document.getElementById('am-d-volume').textContent = account.volume_used_human + ' / ' + account.volume_limit_human;
        document.getElementById('am-d-expiry').textContent = account.expiry_display;
        document.getElementById('am-d-first').textContent = account.first_connected_display;
        document.getElementById('am-qr-config').src = account.qr_config + '&t=' + Date.now();
        document.getElementById('am-qr-panel').src = account.qr_panel + '&t=' + Date.now();
        document.getElementById('am-sub-url').value = account.subscribe_panel_url;
        document.getElementById('am-download').href = account.download_url;
        document.getElementById('am-config-text').textContent = account.config_error || account.config_text || '—';

        document.getElementById('am-form-id').value = String(account.id);
        document.getElementById('am-f-name').value = account.name;
        document.getElementById('am-f-speed').value = String(account.speed_limit_kbps);
        document.getElementById('am-f-volume').value = account.volume_limit_input;
        document.getElementById('am-f-expires').value = account.expires_at_local || '';
        document.getElementById('am-f-days').value = String(account.expiry_duration_days);
        document.getElementById('am-f-active').checked = !!account.is_active;
        form.querySelectorAll('input[name="expiry_mode"]').forEach(function (input) {
            input.checked = input.value === account.expiry_mode;
        });
        document.getElementById('am-first-note').textContent = account.first_connected_at
            ? 'اولین اتصال: ' + account.first_connected_display
            : 'تا قبل از اولین اتصال، انقضا شروع نمی‌شود.';
        toggleExpiry();
    }

    function openModal(id, tab) {
        currentId = id;
        setTab(tab || 'view');
        empty.hidden = false;
        empty.textContent = 'در حال بارگذاری…';
        root.hidden = false;
        root.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        showToast('', true);
        body.classList.add('am-loading');

        fetch('/?ajax=account-modal&id=' + encodeURIComponent(id), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then(function (res) { return res.json().then(function (data) { return { res: res, data: data }; }); })
            .then(function (pack) {
                if (!pack.res.ok || !pack.data.account) {
                    throw new Error(pack.data.error || 'بارگذاری ناموفق بود.');
                }
                fillAccount(pack.data.account);
                setTab(tab || 'view');
            })
            .catch(function (err) {
                empty.hidden = false;
                empty.textContent = err.message || 'خطا در بارگذاری.';
                showToast(empty.textContent, false);
            })
            .finally(function () {
                body.classList.remove('am-loading');
            });
    }

    function closeModal() {
        root.hidden = true;
        root.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        currentId = null;
        const url = new URL(window.location.href);
        if (url.searchParams.has('account') || url.searchParams.has('tab')) {
            url.searchParams.delete('account');
            url.searchParams.delete('tab');
            window.history.replaceState({}, '', url.pathname + url.search);
        }
    }

    function applyList(payload) {
        if (window.wgApplyDashboardPayload) {
            window.wgApplyDashboardPayload(payload);
        }
    }

    root.querySelectorAll('.js-am-close').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });

    root.querySelectorAll('.am-tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setTab(btn.getAttribute('data-am-tab'));
        });
    });

    form.querySelectorAll('input[name="expiry_mode"]').forEach(function (input) {
        input.addEventListener('change', toggleExpiry);
    });

    document.getElementById('am-copy-sub').addEventListener('click', function () {
        const input = document.getElementById('am-sub-url');
        navigator.clipboard.writeText(input.value).then(function () {
            showToast('لینک کپی شد.', true);
        });
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const data = new FormData(form);
        if (!form.querySelector('#am-f-active').checked) {
            data.delete('is_active');
        }
        saveBtn.disabled = true;
        body.classList.add('am-loading');

        fetch('/?ajax=account-save', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            body: data,
        })
            .then(function (res) { return res.json().then(function (json) { return { res: res, json: json }; }); })
            .then(function (pack) {
                if (!pack.json.ok) {
                    throw new Error(pack.json.error || 'ذخیره نشد.');
                }
                fillAccount(pack.json.account);
                if (pack.json.list) {
                    applyList(pack.json.list);
                }
                showToast(pack.json.message || 'ذخیره شد.', true);
            })
            .catch(function (err) {
                showToast(err.message || 'خطا در ذخیره.', false);
            })
            .finally(function () {
                saveBtn.disabled = false;
                body.classList.remove('am-loading');
            });
    });

    document.addEventListener('click', function (event) {
        const opener = event.target.closest('.js-account-modal');
        if (!opener) {
            return;
        }
        event.preventDefault();
        const id = opener.getAttribute('data-account-id');
        const tab = opener.getAttribute('data-am-tab') || 'view';
        if (id) {
            openModal(id, tab);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !root.hidden) {
            closeModal();
        }
    });

    const params = new URLSearchParams(window.location.search);
    const bootId = params.get('account');
    if (bootId) {
        openModal(bootId, params.get('tab') || 'view');
    }
})();
