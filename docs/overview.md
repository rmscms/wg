# 🛡️ بررسی کلی پنل WireGuard

منبع حقیقت پروژه. قبل از تغییر کد، این فایل را بخوان.

---

## 🎯 پنل چیست؟

پنل مدیریت **WireGuard** برای سرور **Ubuntu 24**.

با **PHP 8.3** و **Apache** و **MariaDB** کار می‌کند.

کار اصلی: ساخت و مدیریت اکانت VPN روی اینترفیس `wg0`.

---

## 🧩 چه کارهایی می‌کند؟

- ✅ ساخت / ویرایش / حذف اکانت
- 🔑 تولید کلید WireGuard با `sodium`
- 🌐 تخصیص خودکار IP از ساب‌نت `/19`
- 📥 دانلود فایل `.conf`
- 📷 QR محلی (بدون API خارجی)
- ⏳ محدودیت تاریخ انقضا (`fixed` یا `first_connect`)
- 🚀 محدودیت سرعت با `tc` / HTB
- 📊 محدودیت حجم از شمارش ترافیک WireGuard
- 🟢 وضعیت آنلاین از handshake
- 🔗 لینک Subscribe برای اپ‌ها
- 🌐 پنل وب کاربر با توکن
- 🔌 REST API نسخه ۱ + Swagger
- 💾 بکاپ دیتابیس و کانفیگ wg

---

## 🧱 سرویس‌ها و استک

| ایموجی | سرویس | نقش |
|--------|--------|-----|
| 🔐 | WireGuard (`wg0`) | تونل VPN روی UDP `51820` |
| 🐘 | PHP 8.3 | منطق پنل و API |
| 🌍 | Apache | وب‌سرور + SSL |
| 🐬 | MariaDB | ذخیره اکانت و لاگ ترافیک |
| 📜 | Cron | همگام‌سازی ترافیک و محدودیت |
| 🔒 | Certbot | گواهی Let's Encrypt |
| 🚦 | tc / HTB | سقف سرعت هر peer |
| 📦 | Composer | QR (`chillerlan/php-qrcode`) و تلگرام (`telegram-bot/api`) |

مسیر نصب روی سرور: `/opt/wg-panel`

دو دامنه لازم است:

- 🛰️ دامنه WireGuard → endpoint کلاینت‌ها
- 🖥️ دامنه پنل → UI و Subscribe و SSL

---

## 📁 ساختار پوشه‌ها

```
wg-panel/
├── public/          صفحات وب، API، استایل، فونت
├── src/             کلاس‌های PHP (WgPanel\)
├── scripts/         کرون، sync، peer، بکاپ
├── config/          config.php و نمونه
├── database/        schema و migration
├── docs/            همین مستندات
└── install.sh       نصب روی Ubuntu
```

---

## 🖥️ صفحات ادمین (`public/`)

| ایموجی | فایل | کار |
|--------|------|-----|
| 🏠 | `index.php` | داشبورد اکانت‌ها، جستجو، ریست، سینک |
| ➕ | `create.php` | ساخت اکانت جدید |
| ✏️ | `edit.php` | ویرایش اکانت |
| 👁️ | `view.php` | جزئیات، QR، لینک‌ها |
| ⚙️ | `settings.php` | WG، پنل، ادمین، API، بکاپ، تلگرام |
| 🔑 | `login.php` | ورود (یا مسیر سفارشی) |
| 🚪 | `logout.php` | خروج |
| 📥 | `download.php` | دانلود `.conf` |
| 📷 | `qr.php` | QR کانفیگ |
| 📚 | `api/docs.php` | Swagger UI |
| 💾 | `backup-download.php` | دانلود بکاپ |

منوی بالا: اکانت‌ها · ایجاد · API · تنظیمات · خروج

---

## 👤 پنل کاربر (Subscribe)

هر اکانت یک `subscribe_token` دارد.

| ایموجی | آدرس | کار |
|--------|------|-----|
| 📱 | `/sub/TOKEN` | فید ساب برای Hiddify / Happ / v2rayN |
| 🍏 | لینک iOS | فرمت rawconf برای Streisand |
| 🌐 | `subscribe.php?token=` | پنل وب: حجم، انقضا، QR، دانلود |
| 🔗 | `/s/{short}` | لینک کوتاه (`sub_short`) |
| 📷 | `subscribe-qr.php` | QR لینک ساب |

هدرهای فید:

- `Subscription-Userinfo` → upload / download / total / expire
- `Profile-Title` → نام اکانت

---

## 🔌 REST API (`/api/v1`)

احراز هویت ادمین:

- 🎫 `Authorization: Bearer TOKEN`
- 🍪 سشن بعد از `POST /auth/login`

توکن در `config/config.php` → `api.token`

مستندات زنده: `/api/docs`

### گروه‌ها

- 💚 `health` — سلامت سرویس
- 🔐 `auth` — login / logout / me
- 👥 `accounts` — CRUD، toggle، ریست، QR، کانفیگ، آنلاین (لیست بدون صفحه همه اکانت‌ها را می‌دهد)
- 📈 `traffic` — سینک ترافیک
- ⛔ `limits` — اعمال سقف حجم و انقضا
- 🖥️ `server` — اطلاعات سرور WG
- 🧰 `system` — info، purge، sync-wireguard
- 📡 `subscribe/{token}` — داده عمومی کاربر

---

## ⚙️ کلاس‌های هسته (`src/`)

| ایموجی | کلاس | کار |
|--------|------|-----|
| 🧠 | `WireGuardManager` | قلب پنل: اکانت، ترافیک، محدودیت، سینک |
| 🗄️ | `Database` | اتصال PDO به MariaDB |
| 🛠️ | `Helpers` | فرمت حجم، سرعت، CSRF، فلش |
| 📅 | `Jalali` | تاریخ شمسی در UI |
| 📷 | `QrGenerator` | QR محلی |
| ✍️ | `ConfigWriter` | ذخیره تنظیمات در `config.php` |
| 💾 | `BackupManager` | آرشیو بکاپ |
| 📲 | `TelegramBridge` | ارسال پیام و فایل بکاپ به تلگرام |
| 🛡️ | `LoginThrottle` | قفل ورود بعد از تلاش زیاد |
| 🚪 | `AdminPath` | مسیر ورود سفارشی |
| 🐚 | `Shell` | اجرای دستور با sudo |
| 🌐 | `SubnetHelper` | تخصیص IP از CIDR |
| 🔀 | `Api\Router` | مسیرهای REST |
| 📦 | `Api\AccountResource` | شکل JSON اکانت |
| 🔑 | `Api\ApiAuth` | Bearer / سشن |
| 📤 | `Api\Http` | پاسخ JSON |

---

## 🕒 کرون و اسکریپت‌ها (`scripts/`)

هر ۵ دقیقه:

```bash
php /opt/wg-panel/scripts/sync-traffic.php
php /opt/wg-panel/scripts/check-limits.php
```

| ایموجی | اسکریپت | کار |
|--------|---------|-----|
| 📊 | `sync-traffic.php` | خواندن ترافیک از `wg` و ذخیره در DB |
| ⛔ | `check-limits.php` | قطع اکانت منقضی یا پر حجم |
| 🔄 | `sync-wg.php` | همگام DB ↔ `wg0.conf` ↔ runtime |
| 🚦 | `restore-tc.php` | بازگردانی قوانین سرعت |
| 💾 | `backup.php` | بکاپ زمان‌بندی‌شده + ارسال اختیاری به تلگرام |
| ➕ | `apply-peer.sh` | افزودن peer زنده |
| ➖ | `remove-peer.sh` | حذف peer زنده |
| 🧪 | `debug-traffic.php` | عیب‌یابی ترافیک |

---

## 🗄️ دیتابیس

دو جدول اصلی به علاوه تنظیمات پنل:

### `accounts`

نام، کلید، IP، سقف سرعت، سقف حجم، مصرف، توکن ساب، لینک کوتاه (`sub_short`)، انقضا، حالت انقضا، فعال/غیرفعال.

### `traffic_logs`

تاریخچه rx/tx هر اکانت.

### `panel_settings`

تنظیمات قابل‌تغییر پنل (JSON هر گروه).

### `schema_migrations`

مایگریشن‌های اجراشده.

ساب‌نت پیش‌فرض: `10.66.0.0/19` (~۸۱۹۰ اکانت)

سرور: `10.66.0.1`

---

## 🔐 امنیت

- 🔑 هش رمز ادمین با bcrypt
- 🛡️ CSRF روی فرم‌های POST
- ⏳ قفل ورود (`LoginThrottle`)
- 🚪 مسیر ورود سفارشی (`admin.login_path`)
- 🎫 API با Bearer token
- 🔒 HTTPS با Let's Encrypt
- 🚫 فونت و Swagger محلی (بدون CDN)

---

## ⚙️ تنظیمات کلیدی

از روی `config/config.example.php` ساخته می‌شود.

`config.php` (mode 640) اتصال DB و مسیرهای سیستم را نگه می‌دارد.

تنظیمات قابل‌تغییر پنل (endpoint، subscribe، ادمین، API، بک‌آپ) در جدول `panel_settings` است.

خواندن: فایل + overlay دیتابیس. ذخیره: همیشه DB. مایگریشن با `SchemaMigrator` هنگام وصل DB.

آنلاین بودن: handshake در `online_timeout` ثانیه اخیر.

---

## 📦 نصب و حذف

```bash
sudo bash install.sh
sudo bash uninstall.sh
```

نصب می‌کند: WireGuard، PHP، Apache، MariaDB، Certbot، دیتابیس، Apache vhost، SSL، cron، sudoers.
