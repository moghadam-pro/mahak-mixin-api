# Mahak ↔ Mixin API Bridge

سرویس همگام‌سازی امن و افزایشی بین **Mahak API v3** و **Mixin API v4 (Evya)**، نوشته‌شده با PHP 8.2 و بدون وابستگی اجرایی خارجی.

> وضعیت فعلی: اتصال هر دو API، همگام‌سازی کالا از محک به Mixin، ذخیره نگاشت شناسه‌ها و `RowVersion` پیاده‌سازی شده است. همگام‌سازی نوشتنی به‌صورت پیش‌فرض خاموش و در حالت `dry-run` است.

## قابلیت‌ها

- احراز هویت محک از طریق `/Sync/LoginV2`
- ارسال توکن محک با `Authorization: Bearer ...`
- احراز هویت Mixin با `Authorization: Api-Key ...`
- خواندن افزایشی کالا، جزئیات و موجودی واسط `VisitorProduct`
- تبدیل قیمت ریال محک به تومان Mixin با ضریب قابل تنظیم
- ایجاد و به‌روزرسانی کالای Mixin
- ذخیره checkpoint و نگاشت شناسه‌ها در SQLite
- اجرای امن `dry-run` و پیش‌نمایش حداکثر ۱۰ تغییر
- API مدیریتی محافظت‌شده و CLI مناسب Cron
- عدم نمایش توکن محک در خروجی فرمان تست

## نیازمندی‌ها

- PHP 8.2 یا جدیدتر
- افزونه‌های `curl`, `json`, `pdo`, `pdo_sqlite`
- دسترسی HTTPS به APIهای محک و فروشگاه

## نصب سریع

```bash
git clone https://github.com/moghadam-pro/mahak-mixin-api.git
cd mahak-mixin-api
cp .env.example .env
```

مقادیر `.env` را تکمیل و سپس بررسی کن:

```bash
php bin/console mahak:login
php bin/console mixin:health
php bin/console sync:products:dry-run
```

اگر خروجی dry-run صحیح بود:

```dotenv
SYNC_DRY_RUN=false
```

و اجرای واقعی:

```bash
php bin/console sync:products
```

## تنظیمات ضروری

| متغیر | کاربرد |
|---|---|
| `MAHAK_USERNAME` | نام کاربری API محک |
| `MAHAK_PASSWORD` | رمز API محک |
| `MAHAK_DATABASE_ID` | شناسه دیتابیس محک |
| `MAHAK_VISITOR_ID` | شناسه سایت/ویزیتور برگشتی از Login |
| `MIXIN_BASE_URL` | دامنه فروشگاه، بدون `/api/v4` |
| `MIXIN_API_KEY` | توکن API فروشگاه |
| `BRIDGE_API_KEY` | کلید مستقل برای حفاظت از endpoint مدیریتی Bridge |
| `SYNC_DRY_RUN` | جلوگیری از نوشتن در Mixin؛ پیش‌فرض `true` |
| `SYNC_PRICE_DIVISOR` | تبدیل واحد پول؛ برای ریال به تومان `10` |

فهرست کامل و نکات امنیتی در [راهنمای تنظیمات](docs/configuration.md) آمده است.

## فرمان‌ها

```text
php bin/console mahak:login
php bin/console mixin:health
php bin/console mixin:info
php bin/console sync:products:dry-run
php bin/console sync:products
php tests/run.php
```

`sync:products` نیز مقدار `SYNC_DRY_RUN` را رعایت می‌کند. بنابراین تا زمانی که این مقدار `false` نشده، هیچ کالایی ایجاد یا ویرایش نمی‌شود.

## HTTP API داخلی

اجرای محلی:

```bash
php -S 127.0.0.1:8080 public/index.php
```

| متد | مسیر | احراز هویت | توضیح |
|---|---|---|---|
| `GET` | `/health` | ندارد | سلامت خود Bridge؛ بدون تماس با سرویس خارجی |
| `GET` | `/connections` | `X-Bridge-Key` | تست لاگین محک و health فروشگاه |
| `POST` | `/sync/products` | `X-Bridge-Key` | اجرای sync با رعایت `SYNC_DRY_RUN` |

مثال:

```bash
curl http://127.0.0.1:8080/connections \
  -H 'X-Bridge-Key: YOUR_BRIDGE_KEY'
```

این API را مستقیماً روی اینترنت منتشر نکن؛ آن را پشت HTTPS، فایروال و rate limit قرار بده.

## زمان‌بندی Cron

ابتدا چند اجرای دستی dry-run انجام بده. بعد از تأیید mapping و واحد قیمت:

```cron
*/10 * * * * cd /var/www/mahak-mixin-api && /usr/bin/php bin/console sync:products >> var/log/cron.log 2>&1
```

اجرای هم‌زمان چند Cron هنوز قفل توزیع‌شده ندارد؛ تنها یک scheduler باید این فرمان را اجرا کند.

## مستندات تکمیلی

- [معماری و جریان داده](docs/architecture.md)
- [تنظیم و استقرار](docs/configuration.md)
- [جدول mapping](docs/mapping.md)
- [Runbook عملیاتی](docs/operations.md)
- [قرارداد API داخلی](docs/internal-api.md)
- [سیاست امنیت](SECURITY.md)

## محدودیت‌های نسخه فعلی

- sync دسته‌بندی قبل از کالا هنوز پیاده‌سازی نشده؛ نام و مشخصات اصلی کالا منتقل می‌شود.
- چند `ProductDetail` محک فعلاً به چند محصول مستقل در Mixin تبدیل می‌شوند؛ مدل variant نیازمند تأیید داده واقعی است.
- سفارش و مشتری فقط در کلاینت Mixin قابل خواندن‌اند؛ تبدیل و ثبت آن‌ها در محک پس از تأیید `orderType`، انبار، شخص پیش‌فرض و روش تسویه اضافه می‌شود.
- تصاویر نیازمند strategy جداگانه برای upload و نگاشت فایل هستند.
- checkpoint تنها پس از اجرای واقعی موفق جلو می‌رود؛ dry-run آن را تغییر نمی‌دهد.

## اصول ایمنی

- فایل `.env` در Git نادیده گرفته می‌شود.
- توکن‌ها در پاسخ endpoint سلامت یا خروجی Login نمایش داده نمی‌شوند.
- TLS به‌صورت پیش‌فرض بررسی می‌شود؛ `SYNC_VERIFY_TLS=false` فقط برای عیب‌یابی موقت است.
- عملیات حذف در این نسخه وجود ندارد.
- قبل از اولین اجرای واقعی، از فروشگاه و دیتابیس `var/bridge.sqlite` پشتیبان بگیر.
