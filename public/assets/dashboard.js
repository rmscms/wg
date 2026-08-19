(function () {

    /* ── Dropdown menus ── */
    let activeDropdown = null;

    function openDropdown(wrap) {
        if (activeDropdown && activeDropdown !== wrap) {
            closeDropdown(activeDropdown);
        }

        const menu    = wrap.querySelector('.dd-menu');
        const trigger = wrap.querySelector('.dd-trigger');

        if (!menu || !trigger) { return; }

        menu.style.visibility = 'hidden';
        menu.hidden = false;

        const tr  = trigger.getBoundingClientRect();
        const mw  = menu.offsetWidth  || 175;
        const mh  = menu.offsetHeight || 200;
        const vw  = window.innerWidth;
        const vh  = window.innerHeight;
        const gap = 6;

        let left = tr.left + tr.width / 2 - mw / 2;
        left = Math.max(8, Math.min(left, vw - mw - 8));

        const spaceBelow = vh - tr.bottom - gap;
        const goUp = spaceBelow < mh && tr.top > mh;
        let top;
        if (goUp) {
            top = tr.top - mh - gap;
        } else {
            top = tr.bottom + gap;
        }

        menu.style.left = left + 'px';
        menu.style.top  = top  + 'px';
        menu.style.visibility = '';
        menu.classList.toggle('dd-up', goUp);

        trigger.setAttribute('aria-expanded', 'true');
        activeDropdown = wrap;
    }

    function closeDropdown(wrap) {
        if (!wrap) { return; }
        const menu    = wrap.querySelector('.dd-menu');
        const trigger = wrap.querySelector('.dd-trigger');
        if (menu)    { menu.hidden = true; menu.classList.remove('dd-up'); }
        if (trigger) { trigger.setAttribute('aria-expanded', 'false'); }
        if (activeDropdown === wrap) { activeDropdown = null; }
    }

    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('.dd-trigger');

        if (trigger) {
            e.stopPropagation();
            const wrap = trigger.closest('.dd-wrap');
            if (!wrap) { return; }
            const isOpen = !wrap.querySelector('.dd-menu').hidden;
            if (isOpen) { closeDropdown(wrap); } else { openDropdown(wrap); }
            return;
        }

        const insideMenu = e.target.closest('.dd-menu');
        if (insideMenu) {
            const wrap = insideMenu.closest('.dd-wrap');
            setTimeout(function () { closeDropdown(wrap); }, 60);
            return;
        }

        if (activeDropdown) {
            closeDropdown(activeDropdown);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && activeDropdown) {
            closeDropdown(activeDropdown);
        }
    });

    /* ── Live dashboard search (AJAX) ── */
    const searchForm = document.getElementById('dashboard-search-form');
    const searchInput = document.getElementById('dashboard-search-input');
    const searchClear = document.getElementById('dashboard-search-clear');
    const searchSpinner = document.getElementById('dashboard-search-spinner');
    const searchField = document.getElementById('dashboard-search-field');
    const pageInput = document.getElementById('dashboard-page-input');
    const perPageSelect = document.getElementById('dashboard-per-page');
    const tbody = document.getElementById('dashboard-accounts-tbody');
    const metaEl = document.getElementById('dashboard-meta');
    const statsWrap = document.getElementById('dashboard-stats-wrap');
    const paginationWrap = document.getElementById('dashboard-pagination-wrap');
    const listCard = document.getElementById('dashboard-list-card');

    if (searchForm && searchInput && tbody && metaEl && paginationWrap) {
        let debounceTimer = null;
        let abortController = null;
        let requestSeq = 0;
        const DEBOUNCE_MS = 280;

        function readFilters() {
            const checked = searchForm.querySelector('input[name="status"]:checked');
            const createdFrom = document.getElementById('dashboard-created-from');
            const createdTo = document.getElementById('dashboard-created-to');
            const expiresFrom = document.getElementById('dashboard-expires-from');
            const expiresTo = document.getElementById('dashboard-expires-to');

            return {
                status: checked ? checked.value : '',
                created_from: createdFrom ? createdFrom.value : '',
                created_to: createdTo ? createdTo.value : '',
                expires_from: expiresFrom ? expiresFrom.value : '',
                expires_to: expiresTo ? expiresTo.value : '',
            };
        }

        function writeFilters(state) {
            const status = state.status || '';
            searchForm.querySelectorAll('input[name="status"]').forEach(function (radio) {
                radio.checked = radio.value === status;
            });

            const createdFrom = document.getElementById('dashboard-created-from');
            const createdTo = document.getElementById('dashboard-created-to');
            const expiresFrom = document.getElementById('dashboard-expires-from');
            const expiresTo = document.getElementById('dashboard-expires-to');
            if (createdFrom) {
                createdFrom.value = state.created_from || '';
            }
            if (createdTo) {
                createdTo.value = state.created_to || '';
            }
            if (expiresFrom) {
                expiresFrom.value = state.expires_from || '';
            }
            if (expiresTo) {
                expiresTo.value = state.expires_to || '';
            }

            toggleFilterReset(state);
        }

        function filtersFromUrl(url) {
            return {
                status: url.searchParams.get('status') || '',
                created_from: url.searchParams.get('created_from') || '',
                created_to: url.searchParams.get('created_to') || '',
                expires_from: url.searchParams.get('expires_from') || '',
                expires_to: url.searchParams.get('expires_to') || '',
            };
        }

        function hasActiveFilters(filters) {
            return !!(filters.status
                || filters.created_from
                || filters.created_to
                || filters.expires_from
                || filters.expires_to);
        }

        function toggleFilterReset(filters) {
            const resetBtn = document.getElementById('dashboard-filter-reset');
            if (!resetBtn) {
                return;
            }

            resetBtn.hidden = !hasActiveFilters(filters || readFilters());
        }

        function currentParams(overrides) {
            const filters = readFilters();
            const params = {
                q: searchInput.value.trim(),
                page: String(pageInput ? pageInput.value : '1'),
                per_page: perPageSelect ? perPageSelect.value : '20',
                status: filters.status,
                created_from: filters.created_from,
                created_to: filters.created_to,
                expires_from: filters.expires_from,
                expires_to: filters.expires_to,
            };

            if (overrides) {
                Object.keys(overrides).forEach(function (key) {
                    params[key] = String(overrides[key]);
                });
            }

            return params;
        }

        function buildFetchUrl(params) {
            const query = new URLSearchParams({
                ajax: 'search',
                q: params.q || '',
                page: params.page || '1',
                per_page: params.per_page || '20',
            });

            ['status', 'created_from', 'created_to', 'expires_from', 'expires_to'].forEach(function (key) {
                if (params[key]) {
                    query.set(key, params[key]);
                }
            });

            return '/?' + query.toString();
        }

        function setLoading(isLoading) {
            if (searchField) {
                searchField.classList.toggle('is-loading', isLoading);
            }
            if (searchSpinner) {
                searchSpinner.hidden = !isLoading;
            }
            if (listCard) {
                listCard.classList.toggle('is-loading', isLoading);
            }
            if (searchClear && !isLoading) {
                toggleClearButton(searchInput.value.trim());
            }
        }

        function toggleClearButton(query) {
            if (!searchClear) {
                return;
            }

            searchClear.hidden = query === '';
        }

        function setListField(name, value) {
            document.querySelectorAll('input[name="' + name + '"]').forEach(function (el) {
                el.value = value;
            });
        }

        function updateListFields(state) {
            setListField('list_q', state.search || '');
            setListField('list_page', String(state.page || 1));
            setListField('list_per_page', String(state.per_page || 20));
            setListField('list_status', state.status || '');
            setListField('list_created_from', state.created_from || '');
            setListField('list_created_to', state.created_to || '');
            setListField('list_expires_from', state.expires_from || '');
            setListField('list_expires_to', state.expires_to || '');
        }

        function applyPayload(payload) {
            if (!payload || !payload.html || !payload.state) {
                return;
            }

            tbody.innerHTML = payload.html.tbody || '';
            paginationWrap.innerHTML = payload.html.pagination || '';
            metaEl.innerHTML = '<span class="muted">' + (payload.html.meta || '') + '</span>';
            if (statsWrap) {
                statsWrap.innerHTML = payload.html.stats || '';
            }

            if (pageInput) {
                pageInput.value = String(payload.state.page || 1);
            }

            if (perPageSelect && String(perPageSelect.value) !== String(payload.state.per_page)) {
                perPageSelect.value = String(payload.state.per_page);
            }

            toggleClearButton(payload.state.search || '');
            writeFilters(payload.state);
            updateListFields(payload.state);

            if (payload.url && window.history && window.history.replaceState) {
                window.history.replaceState({ dashboardList: payload.state }, '', payload.url);
            }
        }

        window.wgApplyDashboardPayload = applyPayload;

        function fetchList(overrides, immediate) {
            const params = currentParams(overrides);

            if (abortController) {
                abortController.abort();
            }

            abortController = new AbortController();
            const seq = ++requestSeq;
            setLoading(true);

            fetch(buildFetchUrl(params), {
                credentials: 'same-origin',
                signal: abortController.signal,
                headers: {
                    Accept: 'application/json',
                },
            })
                .then(function (response) {
                    const contentType = response.headers.get('content-type') || '';
                    if (!response.ok || !contentType.includes('application/json')) {
                        throw new Error('bad-response');
                    }
                    return response.json();
                })
                .then(function (payload) {
                    if (seq !== requestSeq) {
                        return;
                    }
                    applyPayload(payload);
                })
                .catch(function (error) {
                    if (error && error.name === 'AbortError') {
                        return;
                    }
                })
                .finally(function () {
                    if (seq === requestSeq) {
                        setLoading(false);
                    }
                });
        }

        function scheduleSearch(overrides) {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(function () {
                fetchList(overrides || { page: 1 }, false);
            }, DEBOUNCE_MS);
        }

        searchInput.addEventListener('input', function () {
            toggleClearButton(searchInput.value.trim());
            scheduleSearch({ page: 1 });
        });

        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                searchInput.value = '';
                toggleClearButton('');
                fetchList({ q: '', page: 1 }, true);
                return;
            }

            if (event.key === 'Enter') {
                event.preventDefault();
                window.clearTimeout(debounceTimer);
                fetchList({ page: 1 }, true);
            }
        });

        if (searchClear) {
            searchClear.addEventListener('click', function () {
                searchInput.value = '';
                toggleClearButton('');
                searchInput.focus();
                fetchList({ q: '', page: 1 }, true);
            });
        }

        if (perPageSelect) {
            perPageSelect.addEventListener('change', function () {
                fetchList({ page: 1, per_page: perPageSelect.value }, true);
            });
        }

        paginationWrap.addEventListener('click', function (event) {
            const link = event.target.closest('a.page-link');
            if (!link || !link.href) {
                return;
            }

            event.preventDefault();
            const url = new URL(link.href, window.location.origin);
            fetchList({
                q: url.searchParams.get('q') || '',
                page: url.searchParams.get('page') || '1',
                per_page: url.searchParams.get('per_page') || (perPageSelect ? perPageSelect.value : '20'),
                status: url.searchParams.get('status') || '',
                created_from: url.searchParams.get('created_from') || '',
                created_to: url.searchParams.get('created_to') || '',
                expires_from: url.searchParams.get('expires_from') || '',
                expires_to: url.searchParams.get('expires_to') || '',
            }, true);
        });

        searchForm.addEventListener('submit', function (event) {
            event.preventDefault();
            window.clearTimeout(debounceTimer);
            fetchList({ page: 1 }, true);
        });

        searchForm.addEventListener('change', function (event) {
            const target = event.target;
            if (!target) {
                return;
            }

            if (target.name === 'status' || target.type === 'date') {
                fetchList({ page: 1 }, true);
            }
        });

        const filterReset = document.getElementById('dashboard-filter-reset');
        if (filterReset) {
            filterReset.addEventListener('click', function () {
                writeFilters({
                    status: '',
                    created_from: '',
                    created_to: '',
                    expires_from: '',
                    expires_to: '',
                });
                fetchList({
                    page: 1,
                    status: '',
                    created_from: '',
                    created_to: '',
                    expires_from: '',
                    expires_to: '',
                }, true);
            });
        }

        window.addEventListener('popstate', function () {
            const url = new URL(window.location.href);
            const filters = filtersFromUrl(url);
            searchInput.value = url.searchParams.get('q') || '';
            if (pageInput) {
                pageInput.value = url.searchParams.get('page') || '1';
            }
            if (perPageSelect && url.searchParams.get('per_page')) {
                perPageSelect.value = url.searchParams.get('per_page');
            }
            writeFilters(filters);
            toggleClearButton(searchInput.value.trim());
            fetchList({
                q: searchInput.value.trim(),
                page: pageInput ? pageInput.value : '1',
                per_page: perPageSelect ? perPageSelect.value : '20',
                status: filters.status,
                created_from: filters.created_from,
                created_to: filters.created_to,
                expires_from: filters.expires_from,
                expires_to: filters.expires_to,
            }, true);
        });
    }

    /* ── Reset modal ── */
    const modal = document.getElementById('reset-modal');
    const submitForm = document.getElementById('reset-submit-form');
    const submitAction = document.getElementById('reset-submit-action');
    const submitId = document.getElementById('reset-submit-id');
    const accountNameEl = document.getElementById('reset-modal-account-name');

    if (!modal || !submitForm || !submitAction || !submitId || !accountNameEl) {
        return;
    }

    let activeAccountId = null;

    function openModal(accountId, accountName) {
        activeAccountId = accountId;
        accountNameEl.textContent = accountName || '—';
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');

        const firstOption = modal.querySelector('.reset-option');
        if (firstOption) {
            firstOption.focus();
        }
    }

    function closeModal() {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        activeAccountId = null;
    }

    function submitReset(action) {
        if (!activeAccountId) {
            return;
        }

        submitAction.value = action;
        submitId.value = String(activeAccountId);
        submitForm.requestSubmit();
    }

    document.addEventListener('click', function (event) {
        const button = event.target.closest('.js-reset-open');
        if (!button) {
            return;
        }

        const accountId = button.getAttribute('data-account-id');
        const accountName = button.getAttribute('data-account-name') || '';

        if (!accountId) {
            return;
        }

        openModal(accountId, accountName);
    });

    modal.querySelectorAll('.js-reset-close').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });

    modal.querySelectorAll('[data-reset-action]').forEach(function (option) {
        option.addEventListener('click', function () {
            const action = option.getAttribute('data-reset-action');

            if (!action) {
                return;
            }

            submitReset(action);
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });
})();
