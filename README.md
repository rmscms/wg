# WireGuard Management Panel

پنل مدیریت WireGuard برای Ubuntu 24، PHP 8.3، Apache و **MariaDB** با قابلیت‌های:

- ایجاد و حذف اکانت
- **QR Code محلی** (بدون API خارجی)
- تاریخ انقضا
- محدودیت سرعت (tc/HTB)
- محدودیت حجم (شمارش ترافیک WireGuard)

## پیش‌نیاز

- Ubuntu 24.04
- دسترسی root
- پورت UDP 51820 باز
- **دو دامنه** با رکورد A به IP سرور:
  - دامنه WireGuard (مثلاً `vpn.example.com`) — endpoint کلاینت‌ها
  - دامنه پنل (مثلاً `panel.example.com`) — UI + subscribe + SSL
- پورت‌های **80** و **443** برای Let's Encrypt

## نصب

```bash
cd wg
sed -i 's/\r$//' install.sh uninstall.sh scripts/*.sh

chmod +x install.sh uninstall.sh scripts/*.sh
sudo bash install.sh
```

در حین نصب از شما پرسیده می‌شود:

| مورد | مثال | کاربرد |
|------|------|--------|
| IP سرور | `80.249.114.10` | تشخیص خودکار + بررسی DNS |
| دامنه WireGuard | `vpn.example.com` | `endpoint` در config کلاینت |
| دامنه پنل | `panel.example.com` | Apache + HTTPS + لینک subscribe |
| ایمیل | `admin@example.com` | گواهی Let's Encrypt |

اسکریپت نصب این موارد را انجام می‌دهد:

1. نصب WireGuard، PHP 8.3، Apache، **MariaDB**، **Certbot**
2. ایجاد دیتابیس، کاربر و جداول با index
3. کپی پنل به `/opt/wg-panel`
4. راه‌اندازی `wg0` با endpoint دامنه
5. پیکربندی Apache روی دامنه پنل + **SSL (Let's Encrypt)**
6. ست کردن `subscribe_base_url` روی `https://دامنه-پنل`
7. sudo و cron

## حذف (Uninstall)

```bash
cd wg
sed -i 's/\r$//' uninstall.sh scripts/*.sh
chmod +x uninstall.sh
sudo bash uninstall.sh
```

اسکریپت `uninstall.sh` به‌صورت تعاملی این موارد را حذف می‌کند:

| مورد | پیش‌فرض |
|------|---------|
| فایل‌های پنل (`/opt/wg-panel`) | بله |
| دیتابیس MariaDB و کاربر | بله |
| cron و sudoers | بله |
| سایت Apache | بله |
| WireGuard سرور (`wg0`) | خیر |
| پکیج‌های apt | خیر |

تنظیمات DB و interface از `config/config.php` خوانده می‌شود.

> **مهم:** حتماً با `bash install.sh` اجرا کنید، نه `sh install.sh`

اگر SSL در نصب ناموفق بود (DNS هنوز propagate نشده):

```bash
sudo certbot --apache -d panel.example.com -m admin@example.com --redirect
```

سپس در `config/config.php` مقدار `subscribe_base_url` را `https://panel.example.com` بگذارید.

## دیتابیس (MariaDB)

### تنظیمات `config/config.php`

```php
'database' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'wg_panel',
    'username' => 'wg_panel',
    'password' => 'YOUR_PASSWORD',
    'charset' => 'utf8mb4',
],
```

### Indexها

**جدول `accounts`:**

| Index | ستون‌ها | کاربرد |
|-------|---------|--------|
| PRIMARY | id | کلید اصلی |
| uk_accounts_public_key | public_key | جستجو هنگام sync ترافیک |
| uk_accounts_ip_address | ip_address | یکتایی IP |
| idx_accounts_active | is_active | فیلتر اکانت‌های فعال |
| idx_accounts_expires | expires_at | بررسی انقضا |
| idx_accounts_active_expires | is_active, expires_at | cron غیرفعال‌سازی منقضی |
| idx_accounts_active_volume | is_active, volume_limit_bytes, volume_used_bytes | cron محدودیت حجم |
| idx_accounts_name | name | جستجو بر اساس نام |
| idx_accounts_created | created_at | مرتب‌سازی لیست |

**جدول `traffic_logs`:**

| Index | ستون‌ها | کاربرد |
|-------|---------|--------|
| idx_traffic_account | account_id | لاگ هر اکانت |
| idx_traffic_account_recorded | account_id, recorded_at | گزارش زمانی |
| idx_traffic_recorded | recorded_at | پاکسازی/گزارش کلی |

### نصب دستی MariaDB

```bash
sudo apt-get install -y mariadb-server php8.3-mysql
sudo mysql <<'SQL'
CREATE DATABASE wg_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'wg_panel'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON wg_panel.* TO 'wg_panel'@'localhost';
FLUSH PRIVILEGES;
SQL

mysql wg_panel < database/schema.sql
```

## استفاده

1. به آدرس `http://SERVER_IP/` بروید
2. با نام کاربری و رمز admin وارد شوید
3. از «ایجاد اکانت» کاربر جدید بسازید
4. فایل `.conf` را دانلود یا QR را اسکن کنید

## فونت و استایل (محلی)

تمام CSS، فونت **Vazirmatn** و **Swagger UI** از سرور خودتان سرو می‌شود — بدون CDN خارجی.

```
public/assets/
├── style.css
├── fonts.css
├── swagger/
│   ├── swagger-ui.css
│   └── swagger-ui-bundle.js
└── fonts/
    ├── Vazirmatn-Variable.woff2
    ├── Vazirmatn-Regular.woff2
    ├── Vazirmatn-Medium.woff2
    └── Vazirmatn-Bold.woff2
```

در صورت نبود فونت‌ها:

```bash
bash scripts/download-fonts.sh
bash scripts/download-swagger-ui.sh
```

## Subnet (/19)

پنل از CIDR در `config.php` برای تخصیص IP استفاده می‌کند.

**پیش‌فرض `10.66.0.0/19`:**

| مورد | مقدار |
|------|--------|
| رنج | `10.66.0.0` – `10.66.31.255` |
| ظرفیت | ~8190 اکانت |
| سرور | `10.66.0.1` |
| wg0 | `Address = 10.66.0.1/19` |

```php
'subnet' => '10.66.0.0/19',
'server_ip' => '10.66.0.1',
```

## پنل Subscribe (کاربر)

هر اکانت دو لینک دارد:

### 1. لینک Sub (Hiddify / Happ / v2rayN)

برای import در اپ‌های subscription-based. کاربر **مصرف حجم، سقف و انقضا** را داخل اپ می‌بیند.

```
https://SERVER/sub/TOKEN
```

- هدر `Subscription-Userinfo`: upload / download / total / expire
- هدر `Profile-Title`: نام اکانت (plain text، حداکثر ۲۵ کاراکتر)
- بدنه: base64 از `#profile-title` + `#subscription-userinfo` + `wireguard://...`
- QR جداگانه: `/sub-qr.php?token=TOKEN`

**v2rayNG:** لینک `http://` با IP عمومی فقط وقتی کار می‌کند که در تنظیمات subscription گزینه **Allow insecure HTTP** فعال باشد. ترجیحاً HTTPS بگذارید.

### 2. پنل وب (مرورگر)

```
https://SERVER/subscribe.php?token=TOKEN
```

- وضعیت (فعال / منقضی / ...)
- تاریخ انقضا و روز باقی‌مانده
- مصرف حجم + progress bar
- محدودیت سرعت
- دانلود config و QR کانفیگ WireGuard

لینک‌ها از پنل admin → صفحه QR/View

## REST API + Swagger

پنل یک **REST API v1** با مستندات **Swagger UI** دارد.

| آدرس | توضیح |
|------|--------|
| `/api/docs` | Swagger UI (نیاز به login admin) |
| `/api/openapi.yaml` | OpenAPI 3 spec |
| `/api/v1/...` | REST endpoints |

### احراز هویت Admin

```http
Authorization: Bearer YOUR_API_TOKEN
```

Token در `config/config.php`:

```php
'api' => [
    'enabled' => true,
    'token' => '...',
    'pagination' => [
        'default_per_page' => 20,  // وقتی per_page ارسال نشود
        'min_per_page' => 1,
        'max_per_page' => 0,       // 0 = بدون سقف؛ کلاینت هر تعداد بخواهد
    ],
],
```

مثال pagination:

```bash
# ۵۰۰ رکورد در هر صفحه
curl -H "Authorization: Bearer TOKEN" \
  "https://panel.example.com/api/v1/accounts?page=1&per_page=500"

# سقف pagination از /api/v1/system/info
curl -H "Authorization: Bearer TOKEN" \
  "https://panel.example.com/api/v1/system/info"
```

یا با session cookie بعد از `POST /api/v1/auth/login`.

### Endpoints اصلی

| Method | Path | کار |
|--------|------|-----|
| GET | `/api/v1/health` | سلامت سرویس |
| GET | `/api/v1/accounts` | لیست اکانت‌ها (با `?page=&per_page=&q=` صفحه‌بندی؛ `per_page` توسط کلاینت تعیین می‌شود) |
| POST | `/api/v1/accounts` | ایجاد اکانت |
| GET/PATCH/DELETE | `/api/v1/accounts/{id}` | جزئیات / ویرایش / حذف |
| POST | `/api/v1/accounts/{id}/toggle` | فعال/غیرفعال |
| POST | `/api/v1/accounts/{id}/reset-traffic` | ریست حجم |
| POST | `/api/v1/accounts/{id}/reset-expiry` | ریست تاریخ |
| POST | `/api/v1/accounts/{id}/reset-both` | ریست حجم + تاریخ |
| POST | `/api/v1/accounts/{id}/regenerate-subscribe-token` | توکن subscribe جدید |
| GET | `/api/v1/accounts/{id}/config` | دانلود `.conf` |
| GET | `/api/v1/accounts/{id}/wireguard-uri` | لینک `wireguard://` |
| GET | `/api/v1/accounts/{id}/subscription-feed` | بدنه subscription (base64) |
| GET | `/api/v1/accounts/{id}/qr?type=config\|wireguard\|subscribe` | QR (PNG یا `format=json`) |
| GET | `/api/v1/accounts/{id}/traffic-logs` | تاریخچه مصرف |
| GET | `/api/v1/accounts/{id}/transfer` | آمار خام `wg transfer` |
| GET | `/api/v1/accounts/online-status` | وضعیت آنلاین همه (+ `wg_ok`) |
| GET | `/api/v1/accounts/{id}/online-status` | وضعیت آنلاین یک اکانت |
| POST | `/api/v1/traffic/sync` | همگام‌سازی ترافیک + enforce |
| POST | `/api/v1/traffic/sync-data` | فقط sync (با گزارش verbose) |
| POST | `/api/v1/limits/enforce` | enforce محدودیت‌ها |
| POST | `/api/v1/limits/process-first-connection` | فعال‌سازی expiry از اولین اتصال |
| GET | `/api/v1/system/info` | تنظیمات و آمار (بدون secret) |
| POST | `/api/v1/system/purge-peers` | حذف peerهای غیرفعال |
| POST | `/api/v1/system/sync-wireguard` | همگام‌سازی DB ↔ wg0.conf ↔ WireGuard زنده |
| GET | `/api/v1/server` | اطلاعات WireGuard سرور |
| GET | `/api/v1/subscribe/{token}` | داده subscribe (عمومی) |
| GET | `/api/v1/subscribe/{token}/links` | لینک‌ها + `wireguard://` |
| GET | `/api/v1/subscribe/{token}/feed` | بدنه feed (base64) |

### مثال

```bash
curl -s -H "Authorization: Bearer TOKEN" https://panel.example.com/api/v1/accounts
```

## وضعیت آنلاین (Live)

بر اساس `wg show latest-handshakes` — اگر handshake در `online_timeout` ثانیه اخیر باشد → **آنلاین**

- پنل admin: ستون «اتصال» + صفحه View
- پنل subscribe: کارت اتصال زنده
- به‌روزرسانی خودکار هر **۱۰ ثانیه**

```php
'online_timeout' => 180,  // ثانیه
```

API:
- Admin: `/api/v1/...` (Bearer token)
- Subscribe: `/api/v1/subscribe/{token}/...`
- Docs: `/api/docs` (Swagger UI)

## Cron

هر 5 دقیقه (PHP + MariaDB):

```bash
php /opt/wg-panel/scripts/sync-traffic.php
php /opt/wg-panel/scripts/check-limits.php
```

## عیب‌یابی

```bash
# اتصال MariaDB
mysql -u wg_panel -p wg_panel -e "SHOW INDEX FROM accounts;"

# وضعیت WireGuard
sudo wg show wg0

# لاگ cron
tail -f /var/log/wg-panel-sync.log
tail -f /var/log/wg-panel-limits.log

# تست دستی
sudo php /opt/wg-panel/scripts/sync-traffic.php
sudo php /opt/wg-panel/scripts/check-limits.php
```

### همگام‌سازی WireGuard (DB ↔ wg0.conf)

اگر اکانت در دیتابیس فعال است ولی در `wg0.conf` یا WireGuard زنده نیست:

```bash
# فقط گزارش اختلاف
sudo php /opt/wg-panel/scripts/sync-wg.php --dry-run

# فقط WireGuard sync
sudo php /opt/wg-panel/scripts/sync-wg.php
```

`restore-tc.php` به‌صورت پیش‌فرض **هر دو فاز** را اجرا می‌کند:

1. WireGuard sync (DB ↔ conf ↔ runtime)
2. TC restore **فقط برای peerهایی که tc diff دارند**

```bash
sudo php /opt/wg-panel/scripts/restore-tc.php
sudo php /opt/wg-panel/scripts/restore-tc.php --skip-wg-sync   # فقط tc
sudo php /opt/wg-panel/scripts/restore-tc.php --all            # tc برای همه eligible
```

API:

```bash
curl -X POST -H "Authorization: Bearer TOKEN" \
  https://panel.example.com/api/v1/system/sync-wireguard
```

Dry-run API: `{"dry_run": true}` در body.
