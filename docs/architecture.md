# معماری سرویس

## هدف

Bridge مرز بین قراردادهای متفاوت محک و Mixin است. هیچ‌یک از APIها نباید مستقیماً مدل داخلی دیگری را دریافت کند. هر سمت کلاینت مستقل دارد و Mapper مسئول تبدیل قراردادهاست.

```text
Mahak API v3
    │ Login + GetAllData
    ▼
MahakClient ──► ProductSyncService ──► ProductMapper ──► MixinClient
                         │                                  │
                         ▼                                  ▼
                  SQLite State                      Mixin API v4
             checkpoints + mappings
```

## اجزا

- `HttpClient`: JSON over HTTPS، timeout، بررسی status code و TLS.
- `MahakClient`: Login، GetAllData و SaveAllData بدون پسوند V2، مطابق اعلام پشتیبانی محک. همه مسیرها از تنظیمات محیطی قابل تغییرند.
- `MixinClient`: health/info و عملیات اصلی کالا، سفارش و مشتری.
- `ProductMapper`: تبدیل مدل‌های Product، ProductDetail و VisitorProduct محک به ProductCreate/Patch در Mixin.
- `StateStore`: نگهداری `RowVersion` و نگاشت شناسه مبدأ/مقصد.
- `ProductSyncService`: orchestration همگام‌سازی و جلوگیری از جلو رفتن checkpoint در dry-run.
- `ProductSyncService` در حالت دائمی تغییرات بعد از baseline را می‌خواند، دسته fallback را اعمال می‌کند و نخستین تصویر معتبر را از snapshotهای Picture/PhotoGallery منتقل می‌کند.
- `MappedProductReconcileService`: فقط کالاهای دارای mapping را از وضعیت جاری محک می‌خواند، payload را با snapshot موفق قبلی مقایسه و تغییر واقعی را روی همان شناسه Mixin اصلاح می‌کند.

## مدل همگام‌سازی افزایشی

برای هر موجودیت محک، بیشترین `rowVersion` موفق در `sync_checkpoints` ذخیره می‌شود. درخواست بعدی فقط تغییرات بعد از آن نسخه را می‌گیرد. checkpoint بعد از پایان عملیات نوشتنی موفق ذخیره می‌شود؛ بنابراین شکست میانی باعث از دست رفتن داده نمی‌شود و اجرای بعدی قابل تکرار است.

### ورود اولیه کامل

`FullCatalogSyncService` از worker افزایشی جدا است. این سرویس پنج جریان Product، ProductDetail، VisitorProduct، Picture و PhotoGallery را از نسخه صفر و با cursor مستقل صفحه‌بندی می‌کند. هر ProductDetail فعال به یک محصول Mixin تبدیل می‌شود و نخستین تصویر معتبر Product به آن متصل می‌گردد. یک دسته نگهدارنده موقت برای الزام واقعی Mixin استفاده می‌شود. mapping بعد از هر محصول ذخیره می‌شود، بنابراین قطع‌شدن اجرای طولانی با اجرای مجدد قابل بازیابی است؛ mappingهای مقصد حذف‌شده نیز روی 404 بازسازی می‌شوند.

همین سرویس فرمان baseline فقط‌خواندنی را نیز فراهم می‌کند. baseline بدون هیچ درخواست نوشتنی به Mixin، snapshot و checkpoint فعلی را ثبت می‌کند؛ بنابراین worker زمان‌بندی‌شده فقط تغییراتی را می‌بیند که کاربر بعداً از بازارا ارسال کرده است.

نگاشت `productDetailId → Mixin product id` در `entity_mappings` قرار می‌گیرد. وجود mapping به معنای `PATCH` و نبود آن به معنای `POST` است.

چون API محک برای هر موجودیت RowVersion مستقل دارد، ممکن است در یک اجرا فقط `VisitorProduct` تغییر کند و Product/ProductDetail در پاسخ نباشد. آخرین نسخه کامل هر رکورد در `entity_snapshots` cache می‌شود تا تغییر موجودی یا تغییر والد با داده‌های قبلی join شود. dry-run این cache و checkpointها را تغییر نمی‌دهد.

### reconciliation کالاهای نگاشت‌شده

رفتار واقعی بازارا نشان داد که بعضی ویرایش‌های قیمت و موجودی برای کالای موجود، `RowVersion` تازه‌ای در `GetAllData` افزایشی تولید نمی‌کنند. تکیه صرف بر checkpoint در این حالت به `received=0` منجر می‌شود.

فرمان دائمی `sync:products` پس از اجرای مسیر افزایشی، در فاصله تنظیم‌شده (پیش‌فرض پنج دقیقه) `MappedProductReconcileService` را اجرا می‌کند. این سرویس فقط کلیدهای `entity_mappings` نوع `product` را بررسی می‌کند؛ بنابراین واردکردن ناخواسته کل کاتالوگ ممکن نیست. payload فعلی نام، قیمت، موجودی و مشخصات با payload حاصل از snapshot قبلی hash و مقایسه می‌شود. تغییرها با `PATCH` روی target موجود اعمال می‌شوند و تنها در صورت پاسخ 404 همان mapping بازسازی می‌شود. قفل مشترک مانع هم‌زمانی با sync یا purge است.

## تصمیم variant

محک برای یک Product چند ProductDetail دارد. نسخه فعلی هر ProductDetail را یک محصول مستقل در Mixin می‌سازد، چون تبدیل propertyهای رشته‌ای محک به attribute/variant در Mixin بدون نمونه واقعی می‌تواند مخرب باشد. پس از بررسی داده واقعی می‌توان Mapper را به ساخت یک محصول با `variants[]` ارتقا داد.

## جهت‌های پیشنهادی آینده

1. Mahak → Mixin: دسته‌بندی، کالا، variant، قیمت، موجودی و تصویر.
2. Mixin → Mahak: مشتری، آدرس، سفارش، ردیف سفارش و پرداخت.
3. Mixin → Mahak: تغییر وضعیت پرداخت/سفارش فقط با قواعد کسب‌وکار صریح.

حذف خودکار در هیچ جهت پیشنهاد نمی‌شود. رکورد حذف‌شده باید ابتدا غیرفعال یا ناموجود شود و در گزارش بازبینی قرار گیرد.
