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

        function currentParams(overrides) {
            const params = {
                q: searchInput.value.trim(),
                page: String(pageInput ? pageInput.value : '1'),
                per_page: perPageSelect ? perPageSelect.value : '20',
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

        function updateListFields(state) {
            document.querySelectorAll('input[name="list_q"]').forEach(function (el) {
                el.value = state.search || '';
            });
            document.querySelectorAll('input[name="list_page"]').forEach(function (el) {
                el.value = String(state.page || 1);
            });
            document.querySelectorAll('input[name="list_per_page"]').forEach(function (el) {
                el.value = String(state.per_page || 20);
            });
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
            updateListFields(payload.state);

            if (payload.url && window.history && window.history.replaceState) {
                window.history.replaceState({ dashboardList: payload.state }, '', payload.url);
            }
        }

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
            }, true);
        });

        searchForm.addEventListener('submit', function (event) {
            event.preventDefault();
            window.clearTimeout(debounceTimer);
            fetchList({ page: 1 }, true);
        });

        window.addEventListener('popstate', function () {
            const url = new URL(window.location.href);
            searchInput.value = url.searchParams.get('q') || '';
            if (pageInput) {
                pageInput.value = url.searchParams.get('page') || '1';
            }
            if (perPageSelect && url.searchParams.get('per_page')) {
                perPageSelect.value = url.searchParams.get('per_page');
            }
            toggleClearButton(searchInput.value.trim());
            fetchList({
                q: searchInput.value.trim(),
                page: pageInput ? pageInput.value : '1',
                per_page: perPageSelect ? perPageSelect.value : '20',
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
