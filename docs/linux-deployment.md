# استقرار روی سرور لینوکسی

این راهنما برای اجرای کم‌مصرف Bridge با PHP-FPM و Cron روی یک سرور لینوکسی است. نسخه فعلی به Node.js، Redis، MySQL یا پردازش دائمی جداگانه نیاز ندارد.

## ۱. آماده‌سازی سرور

PHP 8.2 یا جدیدتر، Git، وب‌سرور Nginx یا Apache و PHP-FPM را نصب کنید. برای اجرای سرویس یک کاربر لینوکسی مستقل در نظر بگیرید و PHP-FPM و Cron را با همان کاربر اجرا کنید.

مسیر پیشنهادی پروژه:

```text
/var/www/mahak-mixin-bridge
```

## ۲. دریافت پروژه

```bash
cd /var/www
git clone https://github.com/moghadam-pro/mahak-mixin-api.git mahak-mixin-bridge
cd /var/www/mahak-mixin-bridge
cp .env.example .env
```

مالکیت مسیر را متناسب با کاربر PHP-FPM سرور تنظیم کنید. برای نمونه، اگر نام کاربر سرویس `BRIDGE_USER` است:

```bash
sudo chown -R BRIDGE_USER:BRIDGE_USER /var/www/mahak-mixin-bridge
```

## ۳. Document Root

Document Root وب‌سرور باید فقط روی مسیر زیر باشد:

```text
/var/www/mahak-mixin-bridge/public
```

فایل‌های `.env`، سورس PHP، دیتابیس SQLite و logها نباید مستقیماً از وب قابل دسترسی باشند. همه درخواست‌های برنامه به `public/index.php` هدایت شوند و HTTPS فعال باشد.

## ۴. افزونه‌های PHP

```bash
php8.3 -v
php8.3 -m | grep -E 'curl|json|PDO|pdo_sqlite'
```

خروجی باید شامل `curl`، `json`، `PDO` و `pdo_sqlite` باشد.

## ۵. دسترسی فایل‌ها

```bash
cd /var/www/mahak-mixin-bridge
mkdir -p var/log
chmod 600 .env
chmod -R 770 var
```

کاربر PHP-FPM و Cron باید به `var/` دسترسی نوشتن داشته باشد. سورس برنامه و `.env` فقط به اندازه نیاز دسترسی داشته باشند.

## ۶. تنظیم محیط

```dotenv
MAHAK_BASE_URL=https://mahakacc.mahaksoft.com/API/v3
MAHAK_LOGIN_PATH=/Sync/Login
MAHAK_GET_ALL_DATA_PATH=/Sync/GetAllData
MAHAK_SAVE_ALL_DATA_PATH=/Sync/SaveAllData

MIXIN_BASE_URL=https://store.example.com
SYNC_DRY_RUN=true
```

`MIXIN_BASE_URL` فقط origin فروشگاه است و نباید `/api/v4` داشته باشد؛ کلاینت Bridge این بخش را اضافه می‌کند.

برای ساخت کلید مدیریتی:

```bash
openssl rand -hex 32
```

خروجی را در `BRIDGE_API_KEY` قرار دهید و آن را در گفتگو، issue یا Git ثبت نکنید.

## ۷. تست استقرار

```bash
php8.3 tests/run.php
php8.3 bin/console mahak:login
php8.3 bin/console mixin:health
php8.3 bin/console mahak:products:inspect
php8.3 bin/console sync:products:dry-run
curl https://YOUR_DOMAIN/health
```

## ۸. بروزرسانی

```bash
cd /var/www/mahak-mixin-bridge
git pull --ff-only origin main
php8.3 tests/run.php
```

`.env` و `var/` در Git نیستند و با pull جایگزین نمی‌شوند.

## ۹. Cron

پس از تأیید dry-run و فعال‌سازی آگاهانه نوشتن:

```cron
*/10 * * * * cd /var/www/mahak-mixin-bridge && /usr/bin/php8.3 bin/console sync:products >> var/log/cron.log 2>&1
```

فقط یک Cron برای sync تعریف کنید. Bridge فایل lock دارد، اما همچنان استفاده از یک Scheduler توصیه می‌شود.
