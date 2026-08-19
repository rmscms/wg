(function () {
    function resolveLabelEl(root) {
        return root.querySelector('.online-label')
            || root.querySelector('.edit-online-label')
            || root.querySelector('.connection-live-title');
    }

    function resolveMetaEl(root) {
        return root.querySelector('.online-meta')
            || root.querySelector('.edit-online-meta')
            || root.querySelector('.connection-live-meta');
    }

    function resolveState(data) {
        if (data.state) {
            return data.state;
        }

        if (data.label === 'قطع') {
            return 'disabled';
        }

        return data.online ? 'online' : 'offline';
    }

    function dotClassForState(state) {
        if (state === 'online') {
            return 'is-online';
        }

        if (state === 'disabled') {
            return 'is-disconnected';
        }

        if (state === 'pending') {
            return 'online-dot-pending';
        }

        return 'is-offline';
    }

    function formatMeta(data) {
        if (data.state === 'disabled' || data.label === 'قطع') {
            return '';
        }

        if (data.relative && data.relative !== '—') {
            return 'handshake: ' + data.relative;
        }

        return 'بدون handshake';
    }

    function setOnlineStatus(el, data) {
        const dot = el.querySelector('.online-dot');
        const label = resolveLabelEl(el);
        const meta = resolveMetaEl(el);
        const state = resolveState(data);

        el.classList.remove('is-online', 'is-offline', 'is-disconnected', 'is-pending', 'is-stale');
        el.classList.add('is-' + state);

        if (dot) {
            dot.classList.remove('online-dot-pending', 'is-online', 'is-offline', 'is-disconnected');
            dot.classList.add(dotClassForState(state));
        }

        if (label) {
            label.textContent = data.label || (state === 'online' ? 'آنلاین' : (state === 'disabled' ? 'قطع' : (state === 'pending' ? 'نامشخص' : 'آفلاین')));
        }

        if (meta) {
            meta.textContent = formatMeta(data);
        }

        if (data.title) {
            el.title = data.title;
        }
    }

    function lookupAccountStatus(accounts, id) {
        if (!accounts || id === null || id === '') {
            return null;
        }

        return accounts[id]
            || accounts[String(id)]
            || accounts[Number(id)]
            || null;
    }

    /* ── Page Visibility: pause polling when tab is hidden ── */
    var pageVisible = !document.hidden;
    document.addEventListener('visibilitychange', function () {
        pageVisible = !document.hidden;
    });

    function pollAdminStatus() {
        // Skip if tab is hidden — save server resources
        if (!pageVisible) {
            return;
        }

        const nodes = document.querySelectorAll('[data-live-online]');

        if (nodes.length === 0) {
            return;
        }

        // Collect only the IDs visible on this page — avoids loading ALL accounts
        const ids = [];
        nodes.forEach(function (el) {
            const id = el.getAttribute('data-live-online');
            if (id) {
                ids.push(id);
            }
        });

        const url = '/?ajax=online-status' + (ids.length > 0 ? '&ids=' + ids.join(',') : '');

        fetch(url, { credentials: 'same-origin' })
            .then(function (response) {
                const contentType = response.headers.get('content-type') || '';

                if (!response.ok || !contentType.includes('application/json')) {
                    throw new Error('bad-response');
                }

                return response.json();
            })
            .then(function (payload) {
                if (!payload || !payload.accounts) {
                    throw new Error('invalid-payload');
                }

                const wgOk = payload.wg_ok !== false;

                nodes.forEach(function (el) {
                    const id = el.getAttribute('data-live-online');
                    const data = lookupAccountStatus(payload.accounts, id);

                    if (!data) {
                        return;
                    }

                    if (!wgOk || data.wg_ok === false || data.state === 'unknown') {
                        if (el.dataset.liveLoaded === '1') {
                            el.classList.add('is-stale');
                            return;
                        }
                    }

                    setOnlineStatus(el, data);
                    el.dataset.liveLoaded = '1';
                    el.classList.remove('is-stale');
                });
            })
            .catch(function () {
                nodes.forEach(function (el) {
                    if (el.dataset.liveLoaded === '1') {
                        el.classList.add('is-stale');
                        return;
                    }

                    setOnlineStatus(el, {
                        online: false,
                        state: 'pending',
                        label: 'نامشخص',
                        relative: '',
                        title: 'خطا در دریافت وضعیت زنده',
                    });
                });
            });
    }

    function pollSubscribeStatus() {
        // Skip if tab is hidden
        if (!pageVisible) {
            return;
        }

        const root = document.getElementById('live-subscribe-root');

        if (!root) {
            return;
        }

        const token = root.getAttribute('data-token');

        if (!token) {
            return;
        }

        fetch('/api/subscribe-status.php?token=' + encodeURIComponent(token))
            .then(function (response) {
                const contentType = response.headers.get('content-type') || '';

                if (!response.ok || !contentType.includes('application/json')) {
                    throw new Error('bad-response');
                }

                return response.json();
            })
            .then(function (data) {
                if (data.wg_ok === false || data.state === 'unknown') {
                    if (root.dataset.liveLoaded === '1') {
                        root.classList.add('is-stale');
                        return;
                    }
                }

                setOnlineStatus(root, data);
                root.dataset.liveLoaded = '1';
                root.classList.remove('is-stale');

                const volumeText = document.getElementById('live-volume-text');
                const volumeBar = document.getElementById('live-volume-bar');
                const volumeMeta = document.getElementById('live-volume-meta');
                const accountBadge = document.getElementById('live-account-badge');

                if (volumeText) {
                    if (data.volume_display_html) {
                        volumeText.innerHTML = data.volume_display_html;
                    } else {
                        volumeText.textContent = data.volume_used_human + ' / ' + data.volume_limit_human;
                    }
                }

                if (volumeBar && data.volume_percent !== null && data.volume_percent !== undefined) {
                    volumeBar.style.width = data.volume_percent + '%';
                    volumeBar.classList.toggle('progress-danger', data.volume_percent >= 90);
                    volumeBar.classList.toggle('progress-warning', data.volume_percent >= 70 && data.volume_percent < 90);
                }

                if (volumeMeta && data.volume_percent !== null) {
                    if (data.volume_percent_html) {
                        volumeMeta.innerHTML = data.volume_percent_html;
                    } else {
                        volumeMeta.textContent = data.volume_percent + '٪ مصرف شده';
                    }
                }

                if (accountBadge && data.account_status) {
                    accountBadge.className = 'badge badge-lg ' + data.account_status.class;
                    accountBadge.textContent = data.account_status.label;
                }
            })
            .catch(function () {
                if (root.dataset.liveLoaded === '1') {
                    root.classList.add('is-stale');
                    return;
                }

                setOnlineStatus(root, {
                    online: false,
                    state: 'pending',
                    label: 'نامشخص',
                    relative: '',
                    title: 'خطا در دریافت وضعیت',
                });
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (document.querySelector('[data-live-online]')) {
            pollAdminStatus();
            setInterval(pollAdminStatus, 10000);
        }

        if (document.getElementById('live-subscribe-root')) {
            pollSubscribeStatus();
            setInterval(pollSubscribeStatus, 10000);
        }
    });
})();
