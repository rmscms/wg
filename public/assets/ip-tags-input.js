/**
 * IP Tags Input — تب API تنظیمات
 * هر IP با Enter یا کاما به تگ تبدیل می‌شود؛ هنگام ارسال فرم به رشتهٔ کاما-جدا تبدیل می‌شود.
 */
(function () {
    'use strict';

    var IPV4_RE = /^(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?)$/;
    var IPV6_RE = /^([0-9a-fA-F]{0,4}:){2,7}[0-9a-fA-F]{0,4}$|^::1$|^::$/;

    function isValidIp(ip) {
        return IPV4_RE.test(ip) || IPV6_RE.test(ip);
    }

    function isValidRule(rule) {
        if (isValidIp(rule)) {
            return true;
        }

        var slash = rule.lastIndexOf('/');
        if (slash < 0) {
            return false;
        }

        var ip = rule.slice(0, slash);
        var bits = rule.slice(slash + 1);
        if (!/^\d+$/.test(bits)) {
            return false;
        }

        var prefix = parseInt(bits, 10);
        if (IPV4_RE.test(ip)) {
            return prefix >= 0 && prefix <= 32;
        }
        if (IPV6_RE.test(ip)) {
            return prefix >= 0 && prefix <= 128;
        }

        return false;
    }

    function parseIpList(text) {
        return String(text || '')
            .split(/[,;\n\r\t\s]+/)
            .map(function (s) { return s.trim(); })
            .filter(Boolean);
    }

    function IpTagsInput(wrapper) {
        this.wrapper = wrapper;
        this.container = wrapper.querySelector('.ip-tags-container');
        this.chipsEl = wrapper.querySelector('.ip-tags-chips');
        this.inputEl = wrapper.querySelector('.ip-tags-input');
        this.hiddenEl = wrapper.querySelector('.ip-tags-hidden');
        this.msgEl = wrapper.querySelector('.ip-tags-msg');
        this.ips = [];

        if (!this.container || !this.chipsEl || !this.inputEl || !this.hiddenEl) {
            return;
        }

        this._bindEvents();
        this._loadInitial();
    }

    IpTagsInput.prototype._showMsg = function (message) {
        if (!this.msgEl) {
            return;
        }
        this.msgEl.hidden = !message;
        this.msgEl.textContent = message || '';
    };

    IpTagsInput.prototype._loadInitial = function () {
        var raw = this.wrapper.getAttribute('data-initial-ips') || '';
        var initial = [];

        try {
            initial = JSON.parse(raw);
        } catch (e) {
            initial = parseIpList(raw);
        }

        if (!Array.isArray(initial) || initial.length === 0) {
            var hiddenValue = (this.hiddenEl.value || '').trim();
            if (hiddenValue) {
                initial = parseIpList(hiddenValue);
            }
        }

        if (!Array.isArray(initial)) {
            initial = [];
        }

        var self = this;
        initial.forEach(function (ip) {
            self._addIp(String(ip).trim(), true);
        });
        this._render();
        this._syncHidden();
    };

    IpTagsInput.prototype._bindEvents = function () {
        var self = this;

        this.container.addEventListener('click', function () {
            self.inputEl.focus();
        });

        this.inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',' || e.key === '،' || e.key === ' ') {
                e.preventDefault();
                self._commitInput();
                return;
            }

            if (e.key === 'Backspace' && self.inputEl.value === '' && self.ips.length) {
                e.preventDefault();
                self.ips.pop();
                self._render();
                self._syncHidden();
            }
        });

        this.inputEl.addEventListener('blur', function () {
            if ((self.inputEl.value || '').trim()) {
                self._commitInput();
            }
        });

        this.inputEl.addEventListener('paste', function (e) {
            var pasted = (e.clipboardData || window.clipboardData).getData('text');
            if (!pasted || !/[,;\n\r\t\s]/.test(pasted)) {
                return;
            }
            e.preventDefault();
            parseIpList(pasted).forEach(function (ip) {
                self._addIp(ip);
            });
            self.inputEl.value = '';
            self._render();
            self._syncHidden();
        });

        var form = this.wrapper.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                self.flush();
            }, true);
            form.addEventListener('reset', function () {
                self.ips = [];
                self.inputEl.value = '';
                self._render();
                self._syncHidden();
            });
        }
    };

    IpTagsInput.prototype._addIp = function (ip, silent) {
        ip = (ip || '').trim().replace(/,$/, '');
        if (!ip) {
            return false;
        }

        if (this.ips.some(function (existing) { return existing.value === ip; })) {
            if (!silent) {
                this._showMsg('این IP قبلاً اضافه شده: ' + ip);
            }
            return false;
        }

        this._showMsg('');
        this.ips.push({
            value: ip,
            valid: isValidRule(ip),
        });
        return true;
    };

    IpTagsInput.prototype._commitInput = function () {
        var value = (this.inputEl.value || '').trim().replace(/,$/, '');
        if (!value) {
            return;
        }

        if (this._addIp(value)) {
            this.inputEl.value = '';
            this._render();
            this._syncHidden();
        } else {
            this.inputEl.value = '';
        }
    };

    IpTagsInput.prototype._render = function () {
        var self = this;
        this.chipsEl.innerHTML = '';

        this.ips.forEach(function (item, index) {
            var chip = document.createElement('span');
            chip.className = 'ip-tags-chip' + (item.valid ? '' : ' is-invalid');

            var label = document.createElement('span');
            label.textContent = item.value;

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.setAttribute('aria-label', 'حذف');
            remove.textContent = '×';
            remove.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                self.ips.splice(index, 1);
                self._render();
                self._syncHidden();
                self.inputEl.focus();
            });

            chip.appendChild(label);
            chip.appendChild(remove);
            self.chipsEl.appendChild(chip);
        });
    };

    IpTagsInput.prototype._syncHidden = function () {
        this.hiddenEl.value = this.ips.map(function (item) { return item.value; }).join(',');
    };

    IpTagsInput.prototype.flush = function () {
        this._commitInput();
        this._syncHidden();
    };

    function init() {
        document.querySelectorAll('.ip-tags-wrapper').forEach(function (wrapper) {
            if (wrapper._ipTagsInput) {
                return;
            }
            wrapper._ipTagsInput = new IpTagsInput(wrapper);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
