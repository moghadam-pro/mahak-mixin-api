# استقرار روی CloudPanel

این راهنما برای اجرای کم‌مصرف Bridge با PHP-FPM و Cron است. برای نسخه فعلی نیازی به Node.js، Redis، MySQL یا process دائمی وجود ندارد.

## ۱. ساخت سایت

در CloudPanel یک `PHP Site` با Application نوع `Generic` و PHP 8.3 بسازید. یک زیردامنه اختصاصی مانند `bridge.example.com` و Site User مستقل انتخاب کنید.

CloudPanel معمولاً مسیر زیر را ایجاد می‌کند:

```text
/home/SITE_USER/htdocs/BRIDGE_DOMAIN
```

## ۲. دریافت پروژه

با Site User وارد SSH شوید، نه root:

```bash
sudo -iu SITE_USER
cd ~/htdocs/BRIDGE_DOMAIN
git clone https://github.com/moghadam-pro/mahak-mixin-api.git app
cd app
cp .env.example .env
```

اگر پروژه قبلاً اشتباهاً در `~/app` clone شده و مسیر مقصد `app` وجود ندارد:

```bash
mv ~/app ~/htdocs/BRIDGE_DOMAIN/app
```

قبل از جابه‌جایی، با `ls -la` مطمئن شوید مسیر مقصد حاوی پروژه دیگری نیست.

## ۳. Document Root

Root Directory سایت را روی مسیر زیر قرار دهید:

```text
/home/SITE_USER/htdocs/BRIDGE_DOMAIN/app/public
```

فقط `public/` باید از وب قابل دسترسی باشد. `.env`، سورس، SQLite و logها نباید زیر Document Root قرار بگیرند.

## ۴. افزونه‌های PHP

```bash
php8.3 -v
php8.3 -m | grep -E 'curl|json|PDO|pdo_sqlite'
```

خروجی باید شامل `curl`، `json`، `PDO` و `pdo_sqlite` باشد. در صورت نبودن افزونه‌ها، نصب آن‌ها باید توسط root و متناسب با repository سیستم‌عامل انجام شود.

## ۵. دسترسی‌ها

```bash
cd ~/htdocs/BRIDGE_DOMAIN/app
mkdir -p var/log
chmod 600 .env
chmod -R 770 var
```

PHP-FPM سایت و Cron باید با همان Site User اجرا شوند تا SQLite و logها مالکیت یکسان داشته باشند.

## ۶. تنظیم محیط

```dotenv
MAHAK_BASE_URL=https://mahakacc.mahaksoft.com/API/v3
MAHAK_LOGIN_PATH=/Sync/Login
MAHAK_GET_ALL_DATA_PATH=/Sync/GetAllData
MAHAK_SAVE_ALL_DATA_PATH=/Sync/SaveAllData

MIXIN_BASE_URL=https://shop.example.com
SYNC_DRY_RUN=true
```

`MIXIN_BASE_URL` فقط origin فروشگاه است و نباید `/api/v4` داشته باشد؛ کلاینت Bridge این بخش را اضافه می‌کند.

برای ساخت کلید مدیریتی:

```bash
openssl rand -hex 32
```

خروجی را به‌عنوان `BRIDGE_API_KEY` در `.env` قرار دهید و آن را در گفتگو، issue یا Git ثبت نکنید.

## ۷. تست استقرار

```bash
php8.3 bin/console mahak:login
php8.3 bin/console mixin:health
php8.3 bin/console mahak:products:inspect
php8.3 bin/console sync:products:dry-run
curl https://BRIDGE_DOMAIN/health
```

## ۸. بروزرسانی

```bash
cd /home/SITE_USER/htdocs/BRIDGE_DOMAIN/app
git pull --ff-only origin main
php8.3 tests/run.php
```

`.env` و `var/` در Git نیستند و با pull جایگزین نمی‌شوند.

## ۹. Cron

پس از تأیید dry-run و فعال‌سازی آگاهانه نوشتن:

```cron
*/10 * * * * cd /home/SITE_USER/htdocs/BRIDGE_DOMAIN/app && /usr/bin/php8.3 bin/console sync:products >> var/log/cron.log 2>&1
```

فعلاً فقط یک Cron برای sync تعریف کنید. اجرای هم‌زمان چند worker پشتیبانی نمی‌شود.
