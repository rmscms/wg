<div id="account-modal" class="am-root" hidden aria-hidden="true">
    <div class="am-backdrop js-am-close" tabindex="-1"></div>
    <div class="am-dialog" role="dialog" aria-modal="true" aria-labelledby="am-title">
        <div class="am-toast" id="am-toast" hidden></div>
        <div class="am-header">
            <div class="am-header-main">
                <span class="am-kicker">جزئیات و ویرایش اکانت</span>
                <div class="am-title-row">
                    <h2 class="am-title" id="am-title">—</h2>
                    <span class="badge" id="am-badge">—</span>
                </div>
                <div class="am-header-meta">
                    <code class="am-ip" id="am-ip" dir="ltr">—</code>
                    <span class="online-status online-chip" id="am-online">
                        <span class="online-dot is-offline"></span>
                        <span class="online-chip-text">
                            <span class="online-label">—</span>
                            <span class="online-meta"></span>
                        </span>
                    </span>
                </div>
            </div>
            <button type="button" class="am-close js-am-close" aria-label="بستن">&times;</button>
        </div>

        <div class="am-stats">
            <div class="am-stat">
                <span class="am-stat-label">حجم</span>
                <span class="am-stat-value" id="am-stat-volume">—</span>
                <div class="am-progress" id="am-volume-progress" hidden><span></span></div>
            </div>
            <div class="am-stat">
                <span class="am-stat-label">سرعت</span>
                <span class="am-stat-value" id="am-stat-speed">—</span>
            </div>
            <div class="am-stat">
                <span class="am-stat-label">انقضا</span>
                <span class="am-stat-value" id="am-stat-expiry">—</span>
            </div>
            <div class="am-stat">
                <span class="am-stat-label">کلید عمومی</span>
                <span class="am-stat-value" id="am-stat-key" dir="ltr">—</span>
            </div>
        </div>

        <div class="am-tabs" role="tablist">
            <button type="button" class="am-tab is-active" data-am-tab="view" role="tab">جزئیات</button>
            <button type="button" class="am-tab" data-am-tab="edit" role="tab">ویرایش</button>
            <button type="button" class="am-tab" data-am-tab="share" role="tab">اشتراک / QR</button>
        </div>

        <div class="am-body" id="am-body">
            <p class="am-empty" id="am-empty">اکانت را انتخاب کنید.</p>

            <div class="am-pane" data-am-pane="view" hidden>
                <div class="am-grid">
                    <div class="am-card">
                        <h3>اطلاعات اکانت</h3>
                        <dl class="am-details">
                            <dt>نام</dt><dd id="am-d-name">—</dd>
                            <dt>IP</dt><dd><code id="am-d-ip">—</code></dd>
                            <dt>وضعیت</dt><dd id="am-d-status">—</dd>
                            <dt>سرعت</dt><dd id="am-d-speed">—</dd>
                            <dt>حجم</dt><dd id="am-d-volume">—</dd>
                            <dt>انقضا</dt><dd id="am-d-expiry">—</dd>
                            <dt>اولین اتصال</dt><dd id="am-d-first">—</dd>
                        </dl>
                    </div>
                    <div class="am-card am-qr">
                        <h3>QR کانفیگ</h3>
                        <img id="am-qr-config" alt="QR کانفیگ" width="180" height="180">
                    </div>
                </div>
            </div>

            <div class="am-pane" data-am-pane="edit" hidden>
                <form id="am-form" class="am-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="id" id="am-form-id" value="">
                    <section>
                        <h3 class="am-section-title">اطلاعات پایه</h3>
                        <label class="am-field">
                            نام اکانت
                            <input type="text" name="name" id="am-f-name" required placeholder="مثلاً user-01">
                        </label>
                    </section>
                    <section>
                        <h3 class="am-section-title">محدودیت‌ها</h3>
                        <div class="am-grid-2">
                            <label class="am-field">
                                محدودیت سرعت (Kbps)
                                <input type="number" name="speed_limit_kbps" id="am-f-speed" min="0" step="1" placeholder="0 = نامحدود">
                            </label>
                            <label class="am-field">
                                محدودیت حجم
                                <input type="text" name="volume_limit" id="am-f-volume" placeholder="0 = نامحدود">
                            </label>
                        </div>
                    </section>
                    <section>
                        <h3 class="am-section-title">انقضا</h3>
                        <div class="am-expiry-tabs">
                            <label class="am-expiry-tab is-active">
                                <input type="radio" name="expiry_mode" value="fixed" checked>
                                <strong>تاریخ ثابت</strong>
                                <span class="muted">تاریخ مشخص</span>
                            </label>
                            <label class="am-expiry-tab">
                                <input type="radio" name="expiry_mode" value="first_connect">
                                <strong>اولین اتصال</strong>
                                <span class="muted">شمارش از handshake</span>
                            </label>
                        </div>
                        <div id="am-expiry-fixed">
                            <label class="am-field">
                                تاریخ انقضا
                                <input type="datetime-local" name="expires_at" id="am-f-expires">
                            </label>
                        </div>
                        <div id="am-expiry-first" hidden>
                            <label class="am-field">
                                مدت اعتبار (روز)
                                <input type="number" name="expiry_duration_days" id="am-f-days" min="1" step="1" value="30">
                            </label>
                            <p class="muted" id="am-first-note"></p>
                        </div>
                    </section>
                    <section>
                        <h3 class="am-section-title">وضعیت</h3>
                        <label class="am-toggle">
                            <input type="checkbox" name="is_active" id="am-f-active" value="1">
                            <span>اکانت فعال</span>
                        </label>
                    </section>
                </form>
            </div>

            <div class="am-pane" data-am-pane="share" hidden>
                <div class="am-grid">
                    <div class="am-card">
                        <h3>لینک پنل کاربر</h3>
                        <div class="am-link-row">
                            <input type="text" id="am-sub-url" readonly dir="ltr">
                            <button type="button" class="btn btn-secondary btn-small" id="am-copy-sub">کپی</button>
                        </div>
                        <p class="muted" style="margin:.7rem 0 0">QR لینک وب</p>
                        <div class="am-qr" style="margin-top:.6rem">
                            <img id="am-qr-panel" alt="QR پنل کاربر" width="160" height="160">
                        </div>
                    </div>
                    <div class="am-card">
                        <h3>متن کانفیگ</h3>
                        <pre class="am-config" id="am-config-text">—</pre>
                    </div>
                </div>
            </div>
        </div>

        <div class="am-footer">
            <a class="btn btn-secondary" id="am-download" href="#">دانلود .conf</a>
            <div class="am-footer-actions">
                <button type="button" class="btn btn-secondary js-am-close">انصراف</button>
                <button type="submit" form="am-form" class="btn btn-primary" id="am-save" hidden>ذخیره تغییرات</button>
            </div>
        </div>
    </div>
</div>
