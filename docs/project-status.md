# وضعیت فعلی پروژه

آخرین بروزرسانی: `2026-09-20`

## وضعیت پیاده‌سازی

| بخش | وضعیت | توضیح |
|---|---|---|
| استقرار CloudPanel | تأییدشده | PHP 8.3، مسیر پروژه `/home/sayid-bridge/htdocs/bridge.sayid.ir/app` و Document Root روی `app/public` |
| PHP extensions | تأییدشده | curl، json، PDO و pdo_sqlite |
| Bridge health | تأییدشده | `GET /health` پاسخ سالم می‌دهد |
| Mahak Login | تأییدشده | `/Sync/Login` موفق و token در CLI مخفی می‌شود |
| Mixin health/info | تأییدشده | نسخه `4.0.0`، عنوان `Mixin API v4` و احراز هویت Session/API Key مشاهده شد |
| Mixin write smoke test | تأییدشده | محصول تست `222` با stock صفر و `available=false` ایجاد، خوانده و با marker محافظت‌شده حذف شد |
| Mahak GetAllData | تأییدشده با داده واقعی | ۴ Product، ۲ ProductCategory، ۴ ProductDetail و ۴ VisitorProduct دریافت شد |
| Product dry-run | تأییدشده | ۴ رکورد دریافت و هر ۴ مورد برای create پیش‌نمایش شدند؛ خطا صفر |
| Automated tests | تأییدشده در توسعه | ۸ تست، صفر failure؛ تست SQLite محلی به‌علت نبود driver skip شد و روی سرور دارای pdo_sqlite باید دوباره اجرا شود |
| Product write | تست API تأییدشده، production غیرفعال | نوشتن تستی موفق بود؛ `SYNC_DRY_RUN=true` است و هیچ کالای واقعی محک نوشته نشده |
| Customer sync | برنامه‌ریزی‌شده | قواعد Person و duplicate detection باید نهایی شود |
| Order sync | برنامه‌ریزی‌شده | orderType، settlement، store و payment باید تعیین شوند |
| Dashboard | برنامه‌ریزی‌شده | پنل سبک server-rendered روی PHP |

## تصمیم‌ها و یافته‌های قطعی

1. endpointهای محک بدون V2 استفاده می‌شوند.
2. `WithDataTransfer` فقط مشاهده تشخیصی است؛ مقدار `false` در محیط آزمایش‌شده مانع دریافت داده از `GetAllData` نشد.
3. `MIXIN_BASE_URL` فقط origin است و `/api/v4` ندارد.
4. پاسخ‌های واقعی محک PascalCase هستند و Bridge آن‌ها را می‌پذیرد.
5. شناسه‌های عددی ProductDetail پیش از استفاده در mapping به string نرمال می‌شوند.
6. اگر `VisitorProduct.Price` صفر یا نامعتبر باشد، قیمت از `ProductDetail.Price1` خوانده می‌شود.
7. `DefaultSellPriceLevel=1` در داده آزمایش مشاهده شد و با fallback به `Price1` سازگار است.
8. نام محصول از ابتدا و انتها trim می‌شود؛ یکسان‌سازی فاصله داخلی و حروف عربی/فارسی هنوز تصمیم‌گیری نشده است.
9. مسیر نوشتن Mixin با محصول تست تأیید شد؛ production تا بررسی دستی قیمت، موجودی و اولین کالای واقعی فعال نمی‌شود.
10. PHP ساده، PHP-FPM کم‌مصرف و SQLite برای فاز اول حفظ می‌شوند.
11. داشبورد نباید هنگام باز شدن مستقیماً APIهای خارجی را فراخوانی کند؛ فقط state محلی را نمایش می‌دهد.
12. نوشتن تستی Mixin فقط با `MIXIN_ALLOW_TEST_WRITES=true` و marker اختصاصی مجاز است.
13. پیاده‌سازی واقعی Mixin برخلاف schema ثبت‌شده، `main_category_id` یا `main_category_name` را هنگام ایجاد محصول اجباری می‌کند.

## نتیجه smoke test نوشتن Mixin

در `2026-09-20` محصول تست شماره `222` با ویژگی‌های زیر ایجاد شد:

- دسته‌بندی موجود `مراقبت پوست` با شناسه `6`
- `available=false` و `show_price=false`
- موجودی صفر و `stock_type=out_of_stock`
- شناسه و `external_ids` اختصاصی Bridge

Bridge پیش از حذف، محصول را دوباره خواند و markerها را کنترل کرد. حذف با پیام `Object deleted successfully` انجام شد و دسته‌بندی جدیدی ساخته نشد.

## نتیجه اجرای واقعی خواندن و dry-run

بعد از ارسال اولیه کالا از مسیر زیر در نرم‌افزار محک:

```text
عملیات خاص → بازارا → کالاهای ارسالی → کالای جدید
→ انتخاب کد کالا → اضافه‌کردن به بازارا → ارسال و دریافت اطلاعات
```

فرمان بازرسی چهار رکورد کالا و جزئیات آن را دریافت کرد. سپس:

```bash
php8.3 tests/run.php
php8.3 bin/console sync:products:dry-run
```

نتیجه dry-run:

| شاخص | مقدار |
|---|---:|
| received | 4 |
| created | 4 |
| updated | 0 |
| skipped | 0 |

قیمت‌های پیش‌نمایش‌شده پس از تقسیم بر `SYNC_PRICE_DIVISOR=10` برابر ۳۶۰۰، ۴۶۱۱۰، ۴۹۵۹۰ و ۳۶۰۰۰ بودند. موجودی‌های فعلی از `VisitorProduct.Count1` به‌ترتیب ۵۲۰۰، ۲۰۱۵، ۲۵۶۵ و ۱۸۹۰ خوانده شدند. این مقادیر برای عیب‌یابی ثبت شده‌اند و پیش از production write باید با پنل محک تطبیق دستی شوند.

## اصلاحات انجام‌شده در مسیر آزمایش

- خطای type ناشی از تبدیل کلید عددی آرایه PHP به integer رفع و تست بازگشت اضافه شد.
- fallback قیمت زمانی که `VisitorProduct.Price=0` است به `ProductDetail.Price1` اضافه شد.
- فاصله ابتدا و انتهای نام محصول حذف شد.
- فرمان تشخیصی امن `mahak:products:diagnose` برای دیدن فیلدهای لازم بدون چاپ اطلاعات حساس اضافه شد.

## کارهای لازم پیش از production write

- تأیید دستی واحد قیمت با حداقل سه کالای نمونه.
- تأیید اینکه موجودی کسب‌وکار واقعاً `Count1` است؛ معنای `Count2` هنوز قطعی نیست.
- تصمیم درباره نرمال‌سازی فاصله‌های داخلی و تبدیل `ي/ك` عربی به `ی/ک` فارسی.
- اجرای کنترل‌شده فقط برای یک کالای واقعی محک و کنترل نتیجه در Mixin.
- تأیید رفتار چند ProductDetail به‌عنوان محصول مستقل یا variant.
- تعیین mapping دسته‌بندی.
- افزودن lock برای جلوگیری از اجرای هم‌زمان Cron.
- retry محدود برای 429/5xx با exponential backoff و رعایت Retry-After.
- circuit breaker جدا برای Mahak و Mixin.
- ثبت sync run و خطا بدون payload حساس.
- تهیه backup از SQLite پیش از خروج از dry-run.

## برنامه داشبورد مشتری

نسخه سبک پیشنهادی با PHP server-rendered و بدون Node process دائمی:

- وضعیت اتصال هر سرویس.
- آخرین اجرای موفق و مدت پاسخ.
- تعداد created، updated، skipped و failed.
- صف خطا و retry دستی.
- pause/resume همگام‌سازی.
- نمایش mapping بدون credential.
- تاریخچه اجراها و audit log.
- ورود با password hash و session امن.

برای جلوگیری از فشار به APIها، dashboard فقط دیتابیس محلی را می‌خواند و عملیات خارجی را به worker تک‌نخی واگذار می‌کند.
