# Runbook عملیاتی

## تست اتصال

```bash
php bin/console mahak:login
php bin/console mahak:products:inspect
php bin/console mixin:health
php bin/console mixin:info
```

خروجی Login توکن را با `[REDACTED]` جایگزین می‌کند.

## اولین همگام‌سازی

1. روی فروشگاه خالی، `SYNC_DRY_RUN=true` را نگه دار.
2. `php bin/console sync:products:baseline` را فقط یک‌بار اجرا کن؛ این فرمان هیچ نوشتنی در Mixin ندارد.
3. تعداد Product، ProductDetail و VisitorProduct را با فهرست محک مقایسه کن.
4. `SYNC_DRY_RUN=false` را تنظیم و Cron یک‌دقیقه‌ای `sync:products` را فعال کن.
5. یک کالای جدید یا واقعاً ویرایش‌شده را از بازارا ارسال کن و نتیجه Cron را در `var/log/cron.log` ببین.

در baseline تأییدشده پروژه، هر سه شمارنده Product، ProductDetail و VisitorProduct برابر `۱۸۲۸` بود. اندازه صفحه incremental نباید آن‌قدر کوچک شود که رکوردهای دارای RowVersion یکسان بین صفحات جا بیفتند.

## ارسال دستی بازارا و RowVersion

- worker یک webhook نیست و فرمان بازارا مستقیماً Bridge را فراخوانی نمی‌کند؛ Cron تغییرات را از API محک pull می‌کند.
- کالای جدید یا ویرایش‌شده بعد از baseline در اجرای بعدی پردازش می‌شود.
- ارسال دوباره کالای قدیمی بدون تغییر ممکن است RowVersion تازه نسازد و در نتیجه `received=0` باقی بماند.
- برای کالای قدیمیِ ارسال‌شده پیش از baseline، از `sync:product:preview` و سپس `sync:product:apply` با گارد موقت `SYNC_ALLOW_SINGLE_PRODUCT_WRITE=true` استفاده کن.
- پس از پایان انتقال تک‌محصولی، گارد را دوباره `false` کن؛ Cron دائمی باید با `SYNC_DRY_RUN=false` باقی بماند.

## خطاهای متداول

| نشانه | علت محتمل | اقدام |
|---|---|---|
| `Missing required environment variable` | `.env` ناقص است | متغیر نام‌برده را اضافه کن |
| HTTP 401 محک | اطلاعات ورود/DatabaseId یا توکن نامعتبر | `mahak:login` را جداگانه تست کن |
| HTTP 401 Mixin | API key یا قالب Authorization اشتباه | `mixin:health` را تست کن |
| قیمت نادرست | تفاوت ریال و تومان | `SYNC_PRICE_DIVISOR` را بررسی کن |
| کالا ایجاد ولی آپدیت نمی‌شود | SQLite حذف یا mapping گم شده | `entity_mappings` و external_ids را بررسی کن |
| timeout | شبکه یا حجم page زیاد | `SYNC_TIMEOUT_SECONDS` را بیشتر کن؛ page sizeهای full/incremental را فقط با توجه به خطر RowVersion یکسان تغییر بده |
| Cron با `received=0` اجرا می‌شود | پس از checkpoint تغییری با RowVersion جدید نرسیده است | یک کالای واقعاً جدید/ویرایش‌شده را تست کن؛ کالای قدیمی پیش از baseline را یک‌بار با فرمان تک‌محصولی منتقل کن |

## بازیابی

- حذف SQLite باعث می‌شود Bridge نگاشت‌ها و snapshotهای join را فراموش کند و ممکن است محصول تکراری بسازد؛ فایل را بدون برنامه حذف نکن.
- برای انتقال سرور، `.env` و `var/bridge.sqlite` را امن منتقل کن.
- اگر یک اجرای واقعی شکست خورد، checkpoint جلو نمی‌رود؛ پس از رفع علت همان فرمان را دوباره اجرا کن.
- در صورت شک به درز توکن، آن را در هر سرویس revoke و مقدار `.env` را جایگزین کن.

## پایش

Scheduler باید exit code غیرصفر را alert کند. در لاگ production اطلاعات payload مشتری یا credential را ذخیره نکن. معیارهای مفید: زمان اجرا، تعداد received/created/updated/skipped و آخرین زمان موفقیت.
