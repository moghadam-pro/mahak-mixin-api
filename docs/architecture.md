# معماری سرویس

## هدف

Bridge مرز بین قراردادهای متفاوت محک و Mixin است. هیچ‌یک از APIها نباید مستقیماً مدل داخلی دیگری را دریافت کند. هر سمت کلاینت مستقل دارد و Mapper مسئول تبدیل قراردادهاست.

```text
Mahak API v3
    │ Login + GetAllDataV2
    ▼
MahakClient ──► ProductSyncService ──► ProductMapper ──► MixinClient
                         │                                  │
                         ▼                                  ▼
                  SQLite State                      Mixin API v4
             checkpoints + mappings
```

## اجزا

- `HttpClient`: JSON over HTTPS، timeout، بررسی status code و TLS.
- `MahakClient`: Login، GetAllDataV2 و SaveAllDataV2. مسیر Login از تنظیمات محیطی قابل تغییر است.
- `MixinClient`: health/info و عملیات اصلی کالا، سفارش و مشتری.
- `ProductMapper`: تبدیل مدل‌های Product، ProductDetail و VisitorProduct محک به ProductCreate/Patch در Mixin.
- `StateStore`: نگهداری `RowVersion` و نگاشت شناسه مبدأ/مقصد.
- `ProductSyncService`: orchestration همگام‌سازی و جلوگیری از جلو رفتن checkpoint در dry-run.

## مدل همگام‌سازی افزایشی

برای هر موجودیت محک، بیشترین `rowVersion` موفق در `sync_checkpoints` ذخیره می‌شود. درخواست بعدی فقط تغییرات بعد از آن نسخه را می‌گیرد. checkpoint بعد از پایان عملیات نوشتنی موفق ذخیره می‌شود؛ بنابراین شکست میانی باعث از دست رفتن داده نمی‌شود و اجرای بعدی قابل تکرار است.

نگاشت `productDetailId → Mixin product id` در `entity_mappings` قرار می‌گیرد. وجود mapping به معنای `PATCH` و نبود آن به معنای `POST` است.

چون API محک برای هر موجودیت RowVersion مستقل دارد، ممکن است در یک اجرا فقط `VisitorProduct` تغییر کند و Product/ProductDetail در پاسخ نباشد. آخرین نسخه کامل هر رکورد در `entity_snapshots` cache می‌شود تا تغییر موجودی یا تغییر والد با داده‌های قبلی join شود. dry-run این cache و checkpointها را تغییر نمی‌دهد.

## تصمیم variant

محک برای یک Product چند ProductDetail دارد. نسخه فعلی هر ProductDetail را یک محصول مستقل در Mixin می‌سازد، چون تبدیل propertyهای رشته‌ای محک به attribute/variant در Mixin بدون نمونه واقعی می‌تواند مخرب باشد. پس از بررسی داده واقعی می‌توان Mapper را به ساخت یک محصول با `variants[]` ارتقا داد.

## جهت‌های پیشنهادی آینده

1. Mahak → Mixin: دسته‌بندی، کالا، variant، قیمت، موجودی و تصویر.
2. Mixin → Mahak: مشتری، آدرس، سفارش، ردیف سفارش و پرداخت.
3. Mixin → Mahak: تغییر وضعیت پرداخت/سفارش فقط با قواعد کسب‌وکار صریح.

حذف خودکار در هیچ جهت پیشنهاد نمی‌شود. رکورد حذف‌شده باید ابتدا غیرفعال یا ناموجود شود و در گزارش بازبینی قرار گیرد.
