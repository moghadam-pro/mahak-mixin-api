# Runbook عملیاتی

## تست اتصال

```bash
php bin/console mahak:login
php bin/console mixin:health
php bin/console mixin:info
```

خروجی Login توکن را با `[REDACTED]` جایگزین می‌کند.

## اولین همگام‌سازی

1. `SYNC_DRY_RUN=true` را نگه دار.
2. `php bin/console sync:products:dry-run` را اجرا کن.
3. نام، قیمت، barcode و stock نمونه‌ها را با هر دو پنل مقایسه کن.
4. اگر قیمت ده برابر یا یک‌دهم است، `SYNC_PRICE_DIVISOR` را اصلاح کن.
5. از Mixin و `var/bridge.sqlite` پشتیبان بگیر.
6. `SYNC_DRY_RUN=false` و سپس `php bin/console sync:products` را اجرا کن.

## خطاهای متداول

| نشانه | علت محتمل | اقدام |
|---|---|---|
| `Missing required environment variable` | `.env` ناقص است | متغیر نام‌برده را اضافه کن |
| HTTP 401 محک | اطلاعات ورود/DatabaseId یا توکن نامعتبر | `mahak:login` را جداگانه تست کن |
| HTTP 401 Mixin | API key یا قالب Authorization اشتباه | `mixin:health` را تست کن |
| قیمت نادرست | تفاوت ریال و تومان | `SYNC_PRICE_DIVISOR` را بررسی کن |
| کالا ایجاد ولی آپدیت نمی‌شود | SQLite حذف یا mapping گم شده | `entity_mappings` و external_ids را بررسی کن |
| timeout | شبکه یا حجم page زیاد | `SYNC_TIMEOUT_SECONDS` را بیشتر و `SYNC_PAGE_SIZE` را کمتر کن |

## بازیابی

- حذف SQLite باعث می‌شود Bridge نگاشت‌ها و snapshotهای join را فراموش کند و ممکن است محصول تکراری بسازد؛ فایل را بدون برنامه حذف نکن.
- برای انتقال سرور، `.env` و `var/bridge.sqlite` را امن منتقل کن.
- اگر یک اجرای واقعی شکست خورد، checkpoint جلو نمی‌رود؛ پس از رفع علت همان فرمان را دوباره اجرا کن.
- در صورت شک به درز توکن، آن را در هر سرویس revoke و مقدار `.env` را جایگزین کن.

## پایش

Scheduler باید exit code غیرصفر را alert کند. در لاگ production اطلاعات payload مشتری یا credential را ذخیره نکن. معیارهای مفید: زمان اجرا، تعداد received/created/updated/skipped و آخرین زمان موفقیت.
