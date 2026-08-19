(function () {
    const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const WEEKDAYS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
    const PERSIAN_DIGITS = {
        '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
        '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
        '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
        '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
    };

    function changeNumberToEn(value) {
        return String(value || '').replace(/[۰-۹٠-٩]/g, function (ch) {
            return PERSIAN_DIGITS[ch] || ch;
        });
    }

    function pad(num) {
        return String(num).padStart(2, '0');
    }

    function toJalali(gy, gm, gd) {
        const gDaysInMonth = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        const gy2 = gm > 2 ? gy + 1 : gy;
        let days = 355666 + (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100)
            + Math.floor((gy2 + 399) / 400) + gd + gDaysInMonth[gm - 1];
        let jy = -1595 + (33 * Math.floor(days / 12053));
        days %= 12053;
        jy += 4 * Math.floor(days / 1461);
        days %= 1461;
        if (days > 365) {
            jy += Math.floor((days - 1) / 365);
            days = (days - 1) % 365;
        }
        let jm;
        let jd;
        if (days < 186) {
            jm = 1 + Math.floor(days / 31);
            jd = 1 + (days % 31);
        } else {
            jm = 7 + Math.floor((days - 186) / 30);
            jd = 1 + ((days - 186) % 30);
        }
        return [jy, jm, jd];
    }

    function toGregorian(jy, jm, jd) {
        jy += 1595;
        let days = -355668 + (365 * jy) + (Math.floor(jy / 33) * 8) + Math.floor(((jy % 33) + 3) / 4) + jd
            + (jm < 7 ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
        let gy = 400 * Math.floor(days / 146097);
        days %= 146097;
        if (days > 36524) {
            gy += 100 * Math.floor(--days / 36524);
            days %= 36524;
            if (days >= 365) {
                days++;
            }
        }
        gy += 4 * Math.floor(days / 1461);
        days %= 1461;
        if (days > 365) {
            gy += Math.floor((days - 1) / 365);
            days = (days - 1) % 365;
        }
        let gd = days + 1;
        const leap = ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28;
        const salA = [0, 31, leap, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        let gm = 0;
        for (let i = 1; i <= 12 && gd > salA[i]; i++) {
            gd -= salA[i];
            gm = i;
        }
        gm++;
        return [gy, gm, gd];
    }

    function daysInJalaliMonth(jy, jm) {
        if (jm <= 6) {
            return 31;
        }
        if (jm <= 11) {
            return 30;
        }
        const g = toGregorian(jy, 12, 30);
        const j = toJalali(g[0], g[1], g[2]);
        return j[0] === jy && j[1] === 12 && j[2] === 30 ? 30 : 29;
    }

    function parseValue(value) {
        const clean = changeNumberToEn(value).trim().replace('T', ' ');
        const match = clean.match(/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})(?:[ T](\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?$/);
        if (!match) {
            return null;
        }
        let y = parseInt(match[1], 10);
        let m = parseInt(match[2], 10);
        let d = parseInt(match[3], 10);
        const h = match[4] !== undefined ? parseInt(match[4], 10) : 23;
        const i = match[5] !== undefined ? parseInt(match[5], 10) : 59;
        if (y >= 1600) {
            const j = toJalali(y, m, d);
            return { jy: j[0], jm: j[1], jd: j[2], h: h, i: i };
        }
        return { jy: y, jm: m, jd: d, h: h, i: i };
    }

    function formatDate(jy, jm, jd) {
        return jy + '/' + pad(jm) + '/' + pad(jd);
    }

    function formatDateTime(jy, jm, jd, h, i) {
        return formatDate(jy, jm, jd) + ' ' + pad(h) + ':' + pad(i);
    }

    function todayJalali() {
        const now = new Date();
        const j = toJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
        return { jy: j[0], jm: j[1], jd: j[2], h: 23, i: 59 };
    }

    let popup = null;
    let currentInput = null;
    let viewYear = 1404;
    let viewMonth = 1;
    let selected = todayJalali();
    let timeEnabled = false;

    function ensurePopup() {
        if (popup) {
            return popup;
        }
        popup = document.createElement('div');
        popup.className = 'jalali-popup';
        popup.hidden = true;
        popup.innerHTML = [
            '<div class="jalali-popup-nav">',
            '  <button type="button" class="jalali-nav-btn" data-jalali-nav="-1" aria-label="ماه قبل">‹</button>',
            '  <div class="jalali-popup-title">',
            '    <select class="jalali-month"></select>',
            '    <select class="jalali-year"></select>',
            '  </div>',
            '  <button type="button" class="jalali-nav-btn" data-jalali-nav="1" aria-label="ماه بعد">›</button>',
            '</div>',
            '<div class="jalali-weekdays"></div>',
            '<div class="jalali-days"></div>',
            '<div class="jalali-time" hidden>',
            '  <label>ساعت <input type="number" class="jalali-hour" min="0" max="23"></label>',
            '  <label>دقیقه <input type="number" class="jalali-minute" min="0" max="59"></label>',
            '</div>',
            '<div class="jalali-popup-actions">',
            '  <button type="button" class="jalali-today">امروز</button>',
            '  <button type="button" class="jalali-clear">پاک</button>',
            '  <button type="button" class="jalali-ok">تأیید</button>',
            '</div>',
        ].join('');
        document.body.appendChild(popup);

        const monthSelect = popup.querySelector('.jalali-month');
        MONTHS.forEach(function (name, index) {
            const option = document.createElement('option');
            option.value = String(index + 1);
            option.textContent = name;
            monthSelect.appendChild(option);
        });
        const yearSelect = popup.querySelector('.jalali-year');
        for (let y = 1380; y <= 1500; y++) {
            const option = document.createElement('option');
            option.value = String(y);
            option.textContent = String(y);
            yearSelect.appendChild(option);
        }
        popup.querySelector('.jalali-weekdays').innerHTML = WEEKDAYS.map(function (day) {
            return '<span>' + day + '</span>';
        }).join('');

        popup.querySelector('[data-jalali-nav="-1"]').addEventListener('click', function () {
            shiftMonth(-1);
        });
        popup.querySelector('[data-jalali-nav="1"]').addEventListener('click', function () {
            shiftMonth(1);
        });
        monthSelect.addEventListener('change', function () {
            viewMonth = parseInt(monthSelect.value, 10);
            renderDays();
        });
        yearSelect.addEventListener('change', function () {
            viewYear = parseInt(yearSelect.value, 10);
            renderDays();
        });
        popup.querySelector('.jalali-today').addEventListener('click', function () {
            selected = todayJalali();
            viewYear = selected.jy;
            viewMonth = selected.jm;
            apply(true);
        });
        popup.querySelector('.jalali-clear').addEventListener('click', function () {
            if (!currentInput) {
                return;
            }
            currentInput.value = '';
            currentInput.dispatchEvent(new Event('input', { bubbles: true }));
            currentInput.dispatchEvent(new Event('change', { bubbles: true }));
            close();
        });
        popup.querySelector('.jalali-ok').addEventListener('click', function () {
            apply(true);
        });
        popup.querySelector('.jalali-days').addEventListener('click', function (event) {
            const btn = event.target.closest('[data-day]');
            if (!btn) {
                return;
            }
            selected.jy = viewYear;
            selected.jm = viewMonth;
            selected.jd = parseInt(btn.getAttribute('data-day'), 10);
            if (timeEnabled) {
                renderDays();
                return;
            }
            apply(true);
        });

        return popup;
    }

    function shiftMonth(delta) {
        viewMonth += delta;
        if (viewMonth < 1) {
            viewMonth = 12;
            viewYear -= 1;
        } else if (viewMonth > 12) {
            viewMonth = 1;
            viewYear += 1;
        }
        renderDays();
    }

    function readTime() {
        const hourInput = popup.querySelector('.jalali-hour');
        const minuteInput = popup.querySelector('.jalali-minute');
        let h = parseInt(hourInput.value, 10);
        let i = parseInt(minuteInput.value, 10);
        if (isNaN(h)) {
            h = 23;
        }
        if (isNaN(i)) {
            i = 59;
        }
        selected.h = Math.max(0, Math.min(23, h));
        selected.i = Math.max(0, Math.min(59, i));
    }

    function apply(closeAfter) {
        if (!currentInput) {
            return;
        }
        if (timeEnabled) {
            readTime();
        }
        currentInput.value = timeEnabled
            ? formatDateTime(selected.jy, selected.jm, selected.jd, selected.h, selected.i)
            : formatDate(selected.jy, selected.jm, selected.jd);
        currentInput.dispatchEvent(new Event('input', { bubbles: true }));
        currentInput.dispatchEvent(new Event('change', { bubbles: true }));
        if (closeAfter) {
            close();
        }
    }

    function renderDays() {
        const monthSelect = popup.querySelector('.jalali-month');
        const yearSelect = popup.querySelector('.jalali-year');
        monthSelect.value = String(viewMonth);
        yearSelect.value = String(viewYear);
        popup.querySelector('.jalali-hour').value = pad(selected.h);
        popup.querySelector('.jalali-minute').value = pad(selected.i);
        popup.querySelector('.jalali-time').hidden = !timeEnabled;

        const g = toGregorian(viewYear, viewMonth, 1);
        const first = new Date(g[0], g[1] - 1, g[2]);
        const offset = (first.getDay() + 1) % 7;
        const total = daysInJalaliMonth(viewYear, viewMonth);
        const today = todayJalali();
        const cells = [];
        for (let i = 0; i < offset; i++) {
            cells.push('<span class="jalali-empty"></span>');
        }
        for (let day = 1; day <= total; day++) {
            const classes = ['jalali-day'];
            if (selected.jy === viewYear && selected.jm === viewMonth && selected.jd === day) {
                classes.push('is-selected');
            }
            if (today.jy === viewYear && today.jm === viewMonth && today.jd === day) {
                classes.push('is-today');
            }
            cells.push('<button type="button" class="' + classes.join(' ') + '" data-day="' + day + '">' + day + '</button>');
        }
        popup.querySelector('.jalali-days').innerHTML = cells.join('');
    }

    function position() {
        if (!currentInput) {
            return;
        }
        const rect = currentInput.getBoundingClientRect();
        const width = popup.offsetWidth || 280;
        const height = popup.offsetHeight || 320;
        let left = rect.right - width;
        if (left < 8) {
            left = 8;
        }
        if (left + width > window.innerWidth - 8) {
            left = window.innerWidth - width - 8;
        }
        let top = rect.bottom + 6;
        if (top + height > window.innerHeight - 8 && rect.top > height) {
            top = rect.top - height - 6;
        }
        popup.style.left = Math.round(left) + 'px';
        popup.style.top = Math.round(top) + 'px';
    }

    function openFor(input) {
        ensurePopup();
        currentInput = input;
        timeEnabled = input.hasAttribute('data-jalali-datetime');
        const parsed = parseValue(input.value) || todayJalali();
        selected = parsed;
        viewYear = parsed.jy;
        viewMonth = parsed.jm;
        popup.hidden = false;
        renderDays();
        position();
    }

    function close() {
        if (popup) {
            popup.hidden = true;
        }
        currentInput = null;
    }

    function bind(input) {
        if (input.dataset.jalaliBound === '1') {
            return;
        }
        input.dataset.jalaliBound = '1';
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('dir', 'ltr');
        input.addEventListener('focus', function () {
            openFor(input);
        });
        input.addEventListener('click', function () {
            openFor(input);
        });
        input.addEventListener('input', function () {
            const next = changeNumberToEn(input.value);
            if (next !== input.value) {
                input.value = next;
            }
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();
            }
        });
    }

    function scan(root) {
        (root || document).querySelectorAll('[data-jalali-date], [data-jalali-datetime]').forEach(bind);
    }

    document.addEventListener('click', function (event) {
        if (!popup || popup.hidden) {
            return;
        }
        if (popup.contains(event.target) || event.target === currentInput) {
            return;
        }
        close();
    });

    window.addEventListener('resize', function () {
        if (popup && !popup.hidden) {
            position();
        }
    });

    scan();
    window.WgJalaliPicker = { scan: scan, bind: bind, changeNumberToEn: changeNumberToEn };
})();
