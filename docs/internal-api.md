# API داخلی Bridge

## `GET /health`

برای liveness probe است و سرویس خارجی را صدا نمی‌زند.

```json
{"status":"ok","service":"mahak-mixin-bridge"}
```

## احراز هویت مدیریتی

تمام endpointها به‌جز `/health` به هدر زیر نیاز دارند:

```http
X-Bridge-Key: value-of-BRIDGE_API_KEY
```

## `GET /connections`

Login محک و health Mixin را بررسی می‌کند. توکن محک در پاسخ redact می‌شود. این endpoint را زیاد فراخوانی نکن چون Login خارجی انجام می‌دهد.

## `POST /sync/products`

همگام‌سازی کالا را اجرا می‌کند. رفتار نوشتن فقط از `SYNC_DRY_RUN` پیروی می‌کند؛ درخواست HTTP نمی‌تواند dry-run را دور بزند.

Bridge مطابق اعلام پشتیبانی محک از endpointهای بدون پسوند V2 استفاده می‌کند.

## داشبورد مشتری

مسیر `/dashboard` یک گزارش داخلی RTL و mobile-first است و بدون لاگین نمایش داده می‌شود. داشبورد فقط SQLite محلی را می‌خواند و هنگام بازشدن هیچ API خارجی را فراخوانی نمی‌کند. صفحه، آمار تجمیعی، نرخ موفقیت، نمای کنسولی فعالیت، جهت و موجودیت هر اجرا، زمان شروع و پایان، مدت، خطا و checkpointها را نمایش می‌دهد. صفحه با متاتگ و هدر `noindex` از فهرست موتورهای جست‌وجو خارج شده است.

مسیر محافظت‌شده `POST /dashboard/refresh-status` فقط با درخواست صریح کاربر و CSRF معتبر، اتصال Mahak و Mixin را جداگانه بررسی می‌کند. نتیجه در session نگهداری می‌شود و هیچ credential یا متن خام خطای خارجی در داشبورد نمایش داده نمی‌شود.

نمونه پاسخ dry-run:

```json
{
  "dry_run": true,
  "received": 2,
  "created": 2,
  "updated": 0,
  "skipped": 0,
  "preview": [
    {
      "action": "create",
      "source_id": "123",
      "target_id": null,
      "payload": {"name": "نمونه", "price": 10000, "stock": 3}
    }
  ]
}
```

در production، اجرای sync با CLI/Cron بر endpoint وب ترجیح دارد.
