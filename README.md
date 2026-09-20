# Mahak ↔ Mixin API Bridge

سرویس همگام‌سازی امن و افزایشی بین **Mahak API v3** و **Mixin API v4 (Evya)**، نوشته‌شده با PHP 8.2 و بدون وابستگی اجرایی خارجی.

> وضعیت فعلی: اتصال هر دو API، dry-run واقعی ۴ کالا، چرخه تست Mixin و انتقال کنترل‌شده اولین کالای واقعی محک تأیید شده است. ۸ تست خودکار پاس می‌شوند. همگام‌سازی عمومی production همچنان با `SYNC_DRY_RUN=true` خاموش است تا mapping دسته‌بندی و کنترل‌های عملیاتی تکمیل شوند.

## قابلیت‌ها

- استفاده از endpointهای بدون V2 (`Login`، `GetAllData`، `SaveAllData`) مطابق اعلام پشتیبانی محک
- ارسال توکن محک با `Authorization: Bearer ...`
- احراز هویت Mixin با `Authorization: Api-Key ...`
- خواندن افزایشی کالا، جزئیات و موجودی واسط `VisitorProduct`
- fallback قیمت از VisitorProduct صفر به `ProductDetail.Price1`
- نرمال‌سازی حروف عربی `ي/ى/ك` و فاصله‌های تکراری در نام کالا
- تبدیل قیمت ریال محک به تومان Mixin با ضریب قابل تنظیم
- ایجاد و به‌روزرسانی کالای Mixin
- ذخیره checkpoint و نگاشت شناسه‌ها در SQLite
- اجرای امن `dry-run` و پیش‌نمایش حداکثر ۱۰ تغییر
- API مدیریتی محافظت‌شده و CLI مناسب Cron
- عدم نمایش توکن محک در خروجی فرمان‌های تشخیصی

## نیازمندی‌ها

- PHP 8.2 یا جدیدتر
- افزونه‌های `curl`، `json`، `pdo` و `pdo_sqlite`
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
php bin/console mahak:products:inspect
php bin/console mahak:products:diagnose
php bin/console mahak:products:candidates
php bin/console mahak:images:diagnose
php bin/console mixin:health
php bin/console mixin:info
php tests/run.php
php bin/console sync:products:dry-run
```

قبل از تغییر `SYNC_DRY_RUN` به `false`، قیمت حداقل سه کالا، معنای `Count1` و نتیجه یک اجرای کنترل‌شده تک‌محصولی را بررسی کن. اجرای واقعی:

```dotenv
SYNC_DRY_RUN=false
```

```bash
php bin/console sync:products
```

## تنظیمات ضروری

| متغیر | کاربرد |
|---|---|
| `MAHAK_USERNAME` | نام کاربری API محک |
| `MAHAK_PASSWORD` | رمز API محک |
| `MAHAK_LOGIN_PATH` | مسیر ورود؛ پیش‌فرض `/Sync/Login` |
| `MAHAK_GET_ALL_DATA_PATH` | مسیر دریافت؛ پیش‌فرض `/Sync/GetAllData` |
| `MAHAK_SAVE_ALL_DATA_PATH` | مسیر ثبت؛ پیش‌فرض `/Sync/SaveAllData` |
| `MAHAK_DATABASE_ID` | شناسه دیتابیس محک |
| `MAHAK_VISITOR_ID` | شناسه سایت/ویزیتور برگشتی از Login |
| `MIXIN_BASE_URL` | دامنه فروشگاه، بدون `/api/v4` |
| `MIXIN_API_KEY` | توکن API فروشگاه |
| `MIXIN_ALLOW_TEST_WRITES` | مجوز موقت ایجاد/حذف رکورد تست؛ پیش‌فرض `false` |
| `MIXIN_TEST_CATEGORY_ID` | شناسه یک دسته‌بندی موجود Mixin برای محصول تست |
| `BRIDGE_API_KEY` | کلید مستقل برای حفاظت از endpoint مدیریتی Bridge |
| `SYNC_DRY_RUN` | جلوگیری از نوشتن در Mixin؛ پیش‌فرض `true` |
| `SYNC_ALLOW_SINGLE_PRODUCT_WRITE` | مجوز موقت apply/rollback فقط یک ProductDetail؛ پیش‌فرض `false` |
| `SYNC_PRICE_DIVISOR` | تبدیل واحد پول؛ برای ریال به تومان `10` |

فهرست کامل و نکات امنیتی در [راهنمای تنظیمات](docs/configuration.md) آمده است.

## فرمان‌ها

```text
php bin/console mahak:login
php bin/console mahak:products:inspect
php bin/console mahak:products:diagnose
php bin/console mixin:health
php bin/console mixin:info
php bin/console mixin:categories:inspect
php bin/console mixin:test-product:preview
php bin/console mixin:test-product:create
php bin/console mixin:test-product:delete PRODUCT_ID
php bin/console sync:product:preview PRODUCT_DETAIL_ID MIXIN_CATEGORY_ID
php bin/console sync:product:apply PRODUCT_DETAIL_ID MIXIN_CATEGORY_ID
php bin/console sync:product:rollback PRODUCT_DETAIL_ID
php bin/console sync:products:dry-run
php bin/console sync:products
php tests/run.php
```

`sync:products` نیز مقدار `SYNC_DRY_RUN` را رعایت می‌کند. بنابراین تا زمانی که این مقدار `false` نشده، هیچ کالایی ایجاد یا ویرایش نمی‌شود.

فرمان‌های `mahak:products:inspect` و `mahak:products:diagnose` برای بررسی ساختار، تعداد و فیلدهای لازم طراحی شده‌اند و credential یا توکن را چاپ نمی‌کنند.

## نتیجه آخرین آزمایش واقعی

در `2026-09-20`:

- Mahak GetAllData چهار کالا و چهار ProductDetail/VisitorProduct برگرداند.
- Mixin health و info نسخه `4.0.0` را تأیید کردند.
- هر ۸ تست خودکار روی سرور پاس شد.
- dry-run نتیجه `received=4`، `created=4`، `updated=0` و `skipped=0` داشت.
- به‌دلیل صفر بودن VisitorProduct.Price، قیمت از ProductDetail.Price1 خوانده شد.
- محصول تست غیرفعال شماره `222` با stock صفر در دسته‌بندی موجود شماره `6` ایجاد، خوانده و با کنترل marker حذف شد.
- پد الکلی محک (`ProductDetailId=11629315`) به محصول Mixin شماره `223` نگاشت شد و نمایش صحیح قیمت `۳۶۰۰`، موجودی `۵۲۰۰`، وضعیت فعال و دسته‌بندی آن در پنل تأیید شد.
- سه کالای دیگر هنوز منتقل نشده‌اند و sync عمومی در حالت dry-run باقی مانده است.

جزئیات و موارد باز در [وضعیت فعلی پروژه](docs/project-status.md) و [یافته‌های سازگاری](docs/api-compatibility.md) ثبت شده‌اند.

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
- [استقرار روی CloudPanel](docs/cloudpanel-deployment.md)
- [آماده‌سازی بازارا و API محک](docs/mahak-setup.md)
- [یافته‌های سازگاری APIها](docs/api-compatibility.md)
- [نسخه کامل OpenAPI هر دو سرویس](docs/reference/README.md)
- [ماتریس قابل‌انتقال داده‌ها](docs/transfer-matrix.md)
- [تست کنترل‌شده Mixin](docs/mixin-smoke-test.md)
- [وضعیت فعلی و ادامه پروژه](docs/project-status.md)
- [جدول mapping](docs/mapping.md)
- [Runbook عملیاتی](docs/operations.md)
- [قرارداد API داخلی](docs/internal-api.md)
- [سیاست امنیت](SECURITY.md)
- [تاریخچه تغییرات](CHANGELOG.md)

## محدودیت‌های نسخه فعلی

- sync دسته‌بندی قبل از کالا هنوز پیاده‌سازی نشده؛ نام و مشخصات اصلی کالا منتقل می‌شود.
- چند `ProductDetail` محک فعلاً به چند محصول مستقل در Mixin تبدیل می‌شوند؛ مدل variant نیازمند تأیید داده واقعی است.
- معنای کسب‌وکاری `Count1` در برابر `Count2` هنوز باید دستی تأیید شود.
- نرمال‌سازی فاصله‌های داخلی و حروف عربی/فارسی نام محصول هنوز انجام نمی‌شود.
- سفارش و مشتری فقط در کلاینت Mixin قابل خواندن‌اند؛ تبدیل و ثبت آن‌ها در محک پس از تأیید `orderType`، انبار، شخص پیش‌فرض و روش تسویه اضافه می‌شود.
- تصاویر نیازمند strategy جداگانه برای upload و نگاشت فایل هستند.
- checkpoint تنها پس از اجرای واقعی موفق جلو می‌رود؛ dry-run آن را تغییر نمی‌دهد.

## اصول ایمنی

- فایل `.env` در Git نادیده گرفته می‌شود.
- توکن‌ها در پاسخ endpoint سلامت یا خروجی Login نمایش داده نمی‌شوند.
- TLS به‌صورت پیش‌فرض بررسی می‌شود؛ `SYNC_VERIFY_TLS=false` فقط برای عیب‌یابی موقت است.
- عملیات حذف در این نسخه وجود ندارد.
- قبل از اولین اجرای واقعی، از فروشگاه و دیتابیس `var/bridge.sqlite` پشتیبان بگیر.
