# پل ارتباطی محک و میکسین

نسخه فعلی: **0.1.0**

این پروژه یک سرویس سبک و کم‌مصرف برای انتقال افزایشی کالاها از **نرم‌افزار حسابداری محک** به **فروشگاه میکسین** است. سرویس با PHP 8.2+ و SQLite پیاده‌سازی شده و برای اجرا روی یک سرور لینوکسی کم‌منبع به سرویس دائمی Node.js، Redis یا MySQL نیاز ندارد.

## وضعیت فعلی پروژه

- اتصال واقعی به Mahak API v3 و Mixin API v4 برقرار و تأیید شده است.
- چهار کالای واقعی همراه نام، قیمت، موجودی، دسته‌بندی و تصویر به میکسین منتقل شده‌اند.
- تبدیل قیمت حساب محک از ریال به تومان با `SYNC_PRICE_DIVISOR=10` انجام می‌شود.
- همگام‌سازی افزایشی عمومی و به‌روزرسانی کالاهای نگاشت‌شده با موفقیت آزمایش شده است.
- اجرای تکراری از ساخت محصول و تصویر تکراری جلوگیری می‌کند.
- ۱۰ تست خودکار روی سرور اصلی بدون خطا اجرا شده‌اند.
- داشبورد فارسی فعال است و از فونت محلی وزیرمتن استفاده می‌کند.
- سرویس در حالت معمول با `SYNC_DRY_RUN=true` ایمن می‌ماند؛ نوشتن عمومی فقط هنگام فعال‌سازی آگاهانه انجام می‌شود.

راهنمای کوتاه قابل ارائه به مشتری: [دانلود فایل PDF](https://github.com/moghadam-pro/mahak-mixin-api/blob/main/output/pdf/mahak-mixin-customer-guide-fa.pdf)

## روند کار برای مشتری

1. کالا در نرم‌افزار محک تعریف یا ویرایش می‌شود.
2. کالا از مسیر `عملیات خاص ← بازارا ← کالاهای ارسالی` برای سایت ارسال می‌شود.
3. Bridge در اجرای زمان‌بندی‌شده، تغییرات جدید را از محک دریافت می‌کند.
4. نام، قیمت، موجودی، دسته‌بندی و تصویر به قالب میکسین تبدیل می‌شوند.
5. اگر کالا قبلاً منتقل شده باشد، همان محصول به‌روزرسانی می‌شود و محصول تکراری ساخته نمی‌شود.
6. مشتری نتیجه اجراها را در داشبورد مشاهده می‌کند.

داشبورد فقط اطلاعات ثبت‌شده در دیتابیس محلی را نمایش می‌دهد و با هر بار بازشدن به APIهای خارجی فشار وارد نمی‌کند.

## اطلاعات منتقل‌شونده

| اطلاعات | مبدأ | مقصد | وضعیت |
|---|---|---|---|
| نام کالا | `Product.Name` | `name` | فعال |
| قیمت فروش | `VisitorProduct.Price` یا `ProductDetail.Price1` | `price` | فعال، با تبدیل ریال به تومان |
| موجودی | `VisitorProduct.Count1` | `stock` | فعال |
| وضعیت موجود بودن | موجودی و وضعیت حذف | `available` و `stock_type` | فعال |
| بارکد | `ProductDetail.Barcode` | `barcode` | در صورت وجود |
| وزن و ابعاد | Product | فیلدهای متناظر | در صورت وجود |
| دسته‌بندی | نگاشت تنظیم‌شده | `main_category_id` | فعال |
| تصویر اصلی | PhotoGallery و Picture | تصویر محصول | فعال |
| شناسه مرجع محک | Product و ProductDetail | `external_ids` | فعال |

انتقال مشتری و سفارش هنوز فعال نیست و پس از تعیین قواعد مالی، انبار، تسویه و تشخیص مشتری تکراری پیاده‌سازی می‌شود.

## معماری ساده

```text
Mahak API v3
     │  Login + GetAllData
     ▼
MahakClient → ProductSyncService → ProductMapper → MixinClient
                      │                              │
                      ▼                              ▼
               SQLite محلی                    Mixin API v4
       checkpoint + mapping + history
```

- `MahakClient`: ورود و دریافت اطلاعات محک با endpointهای بدون V2.
- `ProductMapper`: تبدیل نام، قیمت، موجودی و مشخصات کالا.
- `MixinClient`: ایجاد و به‌روزرسانی محصول و تصویر در میکسین.
- `StateStore`: نگهداری checkpoint، نگاشت شناسه‌ها، snapshot و تاریخچه اجرا در SQLite.
- `ProductSyncService`: اجرای ایمن فرایند، قفل هم‌زمانی و جلوگیری از ثبت checkpoint در dry-run.

## نیازمندی‌ها

- PHP 8.2 یا جدیدتر؛ نسخه استفاده‌شده روی سرور PHP 8.3 است.
- افزونه‌های `curl`، `json`، `PDO` و `pdo_sqlite`.
- دسترسی HTTPS به API محک و API فروشگاه.
- Document Root وب‌سایت روی پوشه `public/`.
- یک کاربر مستقل سیستم‌عامل برای PHP-FPM و Cron.

## نصب روی سرور لینوکسی

پروژه را با یک کاربر سرویس مستقل روی سرور لینوکسی دریافت کنید. مسیر پیشنهادی عمومی:

```text
/var/www/mahak-mixin-bridge
```

دستورهای نصب:

```bash
cd /var/www
git clone https://github.com/moghadam-pro/mahak-mixin-api.git mahak-mixin-bridge
cd /var/www/mahak-mixin-bridge
cp .env.example .env
mkdir -p var/log
chmod 600 .env
chmod -R 770 var
```

Document Root وب‌سرور باید روی `/var/www/mahak-mixin-bridge/public` قرار بگیرد. فایل `.env` را با اطلاعات واقعی تکمیل کنید؛ این فایل و دیتابیس SQLite نباید وارد Git شوند. PHP-FPM و Cron باید با یک کاربر لینوکسی یکسان اجرا شوند.

## تنظیمات مهم

| متغیر | کاربرد |
|---|---|
| `APP_ENV` | محیط اجرا؛ در سرور `production` |
| `APP_DEBUG` | نمایش جزئیات خطا؛ در production برابر `false` |
| `BRIDGE_API_KEY` | کلید مستقل API مدیریتی Bridge |
| `DASHBOARD_USERNAME` | نام کاربری داشبورد مشتری |
| `DASHBOARD_PASSWORD_HASH` | hash رمز داشبورد؛ رمز خام ذخیره نمی‌شود |
| `MAHAK_BASE_URL` | نشانی پایه Mahak API v3 |
| `MAHAK_USERNAME` و `MAHAK_PASSWORD` | اطلاعات ورود API محک |
| `MAHAK_DATABASE_ID` | شناسه دیتابیس برگشتی از Login |
| `MAHAK_VISITOR_ID` | شناسه سایت یا ویزیتور برگشتی از Login |
| `MAHAK_PACKAGE_NO` | شماره پکیج حساب محک |
| `MIXIN_BASE_URL` | دامنه فروشگاه، بدون `/api/v4` |
| `MIXIN_API_KEY` | کلید API میکسین |
| `SYNC_DRY_RUN` | اگر `true` باشد، هیچ تغییر عمومی در میکسین نوشته نمی‌شود |
| `SYNC_ALLOW_FULL_CATALOG_WRITE` | گارد مستقل برای اجازه موقت ورود کامل کاتالوگ |
| `SYNC_FALLBACK_CATEGORY_ID` | شناسه دسته موقت میکسین برای کالاهای هنوز دسته‌بندی‌نشده |
| `SYNC_CATEGORY_MAP_JSON` | نگاشت دسته‌های محک به دسته‌های میکسین |
| `SYNC_PRODUCT_CATEGORY_MAP_JSON` | استثنای دسته‌بندی بر اساس ProductDetail |
| `SYNC_PRICE_DIVISOR` | ضریب تبدیل واحد پول؛ ریال به تومان برابر `10` |
| `SYNC_HTTP_MAX_RETRIES` | تعداد تلاش مجدد محدود برای خطاهای موقت امن |
| `STATE_DB` | مسیر دیتابیس محلی SQLite |

نمونه کامل همه متغیرها در [.env.example](.env.example) و توضیحات بیشتر در [راهنمای تنظیمات](docs/configuration.md) قرار دارد.

## ساخت رمز داشبورد

رمز خام در `.env` ذخیره نمی‌شود. روی سرور اجرا کنید:

```bash
read -rsp "Dashboard password: " DASH_PASS; echo
DASH_PASS="$DASH_PASS" php8.3 -r 'echo password_hash(getenv("DASH_PASS"), PASSWORD_DEFAULT), PHP_EOL;'
unset DASH_PASS
```

خروجی را در `DASHBOARD_PASSWORD_HASH` قرار دهید. نام کاربری نیز در `DASHBOARD_USERNAME` تنظیم می‌شود.

## تست اتصال و سلامت

```bash
php8.3 tests/run.php
php8.3 bin/console mahak:login
php8.3 bin/console mahak:products:inspect
php8.3 bin/console mixin:health
php8.3 bin/console mixin:info
curl https://YOUR_DOMAIN/health
```

خروجی Login توکن را با `[REDACTED]` مخفی می‌کند. دستورهای تشخیصی نیز نباید credentialها را چاپ کنند.

## اجرای ایمن همگام‌سازی

### پیش‌نمایش بدون نوشتن

```bash
php8.3 bin/console sync:products:dry-run
```

این فرمان تغییرات را دریافت و تبدیل می‌کند، اما محصول یا checkpoint جدیدی ثبت نمی‌کند.

### اجرای واقعی عمومی

ابتدا از دیتابیس محلی نسخه پشتیبان بگیرید:

```bash
cp -p var/bridge.sqlite var/bridge.sqlite.backup-$(date +%Y%m%d-%H%M%S)
```

سپس فقط بعد از بررسی preview، مقدار زیر را موقتاً تنظیم کنید:

```dotenv
SYNC_DRY_RUN=false
```

و اجرا کنید:

```bash
php8.3 bin/console sync:products
```

پس از عملیات کنترل‌شده، برای جلوگیری از نوشتن ناخواسته می‌توان `SYNC_DRY_RUN=true` را دوباره فعال کرد.

### انتقال کنترل‌شده یک کالا

```bash
php8.3 bin/console sync:product:preview PRODUCT_DETAIL_ID MIXIN_CATEGORY_ID
php8.3 bin/console sync:product:apply PRODUCT_DETAIL_ID MIXIN_CATEGORY_ID
php8.3 bin/console sync:product:image:preview PRODUCT_DETAIL_ID
php8.3 bin/console sync:product:image:apply PRODUCT_DETAIL_ID
```

نوشتن تک‌محصولی فقط وقتی مجاز است که `SYNC_ALLOW_SINGLE_PRODUCT_WRITE=true` باشد. این مجوز باید پس از آزمایش دوباره `false` شود.

### ورود کامل کاتالوگ همراه تصاویر

Mixin ایجاد محصول بدون دسته را نمی‌پذیرد. تا زمان نهایی‌شدن دسته‌بندی‌ها، یک دسته ریشه موقت با نام «بدون دسته‌بندی» ساخته می‌شود و همه کالاها به آن می‌روند؛ بعداً می‌توان محصولات را بدون تغییر شناسه یا mapping به دسته‌های نهایی منتقل کرد.

ابتدا فقط برای ساخت یا یافتن این دسته، `SYNC_ALLOW_FULL_CATALOG_WRITE=true` و `SYNC_FALLBACK_CATEGORY_NAME=بدون دسته‌بندی` را موقتاً تنظیم و اجرا کنید:

```bash
php8.3 bin/console mixin:fallback-category:ensure
```

مقدار `category_id` خروجی را در `SYNC_FALLBACK_CATEGORY_ID` قرار دهید، گارد را دوباره `false` کنید و پیش‌نمایش کامل بگیرید:

```bash
php8.3 bin/console sync:catalog:full:dry-run
```

این dry-run همه صفحات Product، ProductDetail، VisitorProduct، Picture و PhotoGallery را از ابتدا می‌خواند، تعداد واقعی کالا و تصویر را گزارش می‌کند و چیزی در مقصد نمی‌نویسد. پس از بررسی خروجی و تهیه backup از SQLite، برای اجرای واقعی فقط در همان بازه عملیات `SYNC_DRY_RUN=false` و `SYNC_ALLOW_FULL_CATALOG_WRITE=true` را تنظیم کنید:

```bash
php8.3 bin/console sync:catalog:full
```

اجرا قابل تکرار است: mappingهای معتبر update می‌شوند، mapping محصولاتی که از Mixin حذف شده‌اند پس از پاسخ 404 بازسازی می‌شوند و تصویر موجود دوباره بارگذاری نمی‌شود. خطای یک کالا مانع ادامه بقیه نیست و در `errors` ثبت می‌شود. checkpointها فقط در اجرای کاملاً موفق جلو می‌روند. در پایان `SYNC_DRY_RUN=true` و `SYNC_ALLOW_FULL_CATALOG_WRITE=false` را برگردانید.

در حالت کاتالوگ کامل، وضعیت حذف خود Product محک مرجع است؛ فلگ‌های `Deleted` در ProductDetail و VisitorProduct به‌دلیل رفتار مشاهده‌شده API نادیده گرفته می‌شوند. موجودی ابتدا از VisitorProduct و در نبود آن از ProductDetail خوانده می‌شود.

## زمان‌بندی Cron

بعد از تأیید نهایی قواعد انتقال و فعال‌سازی نوشتن عمومی، فقط یک Cron تعریف کنید:

```cron
*/10 * * * * cd /var/www/mahak-mixin-bridge && /usr/bin/php8.3 bin/console sync:products >> var/log/cron.log 2>&1
```

Bridge با فایل lock از اجرای هم‌زمان جلوگیری می‌کند، اما همچنان تنها یک Scheduler توصیه می‌شود.

## داشبورد مشتری

- نمایش حالت سرویس به‌صورت «فعال (فقط پل)».
- نمایش وضعیت ارتباط API محک و میکسین با بروزرسانی دستی و بدون درخواست خودکار هنگام بازشدن صفحه.
- نمایش تعداد محصولات نگاشت‌شده.
- نمایش آخرین اجرای موفق، نرخ موفقیت اجراهای اخیر و آمار تجمیعی دریافت، ایجاد، به‌روزرسانی و ردشدن.
- نمایش جزئیات حداکثر ۱۰۰ اجرای اخیر شامل جهت، موجودیت، زمان شروع، زمان پایان، مدت اجرا و متن خطای ثبت‌شده.
- نمایش خلاصه ۶ اجرای آخر در نمای کنسولی و کارت‌های خوانا برای موبایل.
- نمایش checkpointهای همگام‌سازی.
- داشبورد گزارش داخلی بدون لاگین، با متاتگ و هدر `noindex` و کنترل تم روشن/تاریک.
- استفاده از نسخه self-hosted فونت وزیرمتن، بدون CDN خارجی.
- طراحی mobile-first و ریسپانسیو، بدون Tailwind، Node.js یا وابستگی frontend خارجی.

داشبورد فقط برای مشاهده گزارش و لاگ است و دکمه اجرای sync، retry، pause یا تغییر تنظیمات ندارد.

## به‌روزرسانی نسخه روی سرور

```bash
cd /var/www/mahak-mixin-bridge
/usr/bin/git pull --ff-only origin main
php8.3 tests/run.php
```

فایل‌های `.env` و `var/` با `git pull` جایگزین نمی‌شوند.

## نسخه‌گذاری و انتشار

- شماره نسخه از فایل [`VERSION`](VERSION) خوانده می‌شود و در داشبورد، endpoint سلامت و CLI نمایش داده می‌شود.
- الگوی نسخه‌ها [Semantic Versioning](https://semver.org/) با قالب `MAJOR.MINOR.PATCH` است.
- تغییرات هر نسخه در [`CHANGELOG.md`](CHANGELOG.md) ثبت می‌شوند.
- دستور مشاهده نسخه: `php bin/console --version`
- فرایند انتشار و چک‌لیست tag در [`docs/versioning.md`](docs/versioning.md) مستند شده است.

## API داخلی Bridge

| متد | مسیر | احراز هویت | کاربرد |
|---|---|---|---|
| `GET` | `/health` | ندارد | سلامت و نسخه Bridge، بدون تماس با سرویس خارجی |
| `GET` | `/connections` | هدر `X-Bridge-Key` | آزمایش اتصال واقعی محک و میکسین |
| `POST` | `/sync/products` | هدر `X-Bridge-Key` | اجرای sync با رعایت `SYNC_DRY_RUN` |
| `GET` | `/dashboard` | ندارد | مشاهده وضعیت محلی انتقال‌ها |

Endpointهای مدیریتی را فقط پشت HTTPS، فایروال و rate limit منتشر کنید. اجرای production با CLI و Cron بر اجرای HTTP ترجیح دارد.

## پشتیبان‌گیری و بازیابی

دو فایل برای بازیابی سرویس حیاتی هستند:

- `.env`: تنظیمات و credentialهای دو سرویس.
- `var/bridge.sqlite`: checkpointها، نگاشت شناسه‌ها، snapshotها و تاریخچه اجرا.

حذف یا جایگزینی SQLite بدون برنامه می‌تواند باعث فراموش‌شدن نگاشت‌ها و ایجاد محصول تکراری شود. نسخه‌های پشتیبان را خارج از Document Root و با دسترسی محدود نگه دارید.

## خطاهای متداول

| پیام یا نشانه | علت محتمل | اقدام پیشنهادی |
|---|---|---|
| `Missing required environment variable` | مقدار لازم در `.env` وجود ندارد | متغیر اعلام‌شده را تکمیل کنید |
| HTTP 401 از محک | نام کاربری، رمز، DatabaseId یا VisitorId اشتباه است | `mahak:login` را جداگانه اجرا کنید |
| HTTP 401 از میکسین | API Key اشتباه یا منقضی است | `mixin:health` را اجرا کنید |
| HTTP 400 هنگام ایجاد کالا | دسته‌بندی ارسال نشده یا نامعتبر است | mapping دسته‌ها را بررسی کنید |
| قیمت ده برابر یا یک‌دهم | تفاوت ریال و تومان | `SYNC_PRICE_DIVISOR` را بررسی کنید |
| اجرای دوم داده‌ای دریافت نمی‌کند | checkpoint تغییری جدید پیدا نکرده است | این رفتار در sync افزایشی طبیعی است |
| محصول ایجاد می‌شود ولی update نمی‌شود | mapping در SQLite گم شده است | backup و جدول نگاشت‌ها را بررسی کنید |
| timeout | کندی شبکه یا API خارجی | timeout و page size را با احتیاط تنظیم کنید |

## امنیت

- `.env` و SQLite داخل Git قرار نمی‌گیرند.
- توکن‌ها در خروجی‌های عمومی مخفی می‌شوند.
- بررسی TLS به‌صورت پیش‌فرض فعال است.
- مجوزهای تست و نوشتن تک‌محصولی در حالت معمول باید `false` باشند.
- `APP_DEBUG` در production باید `false` باشد.
- Document Root فقط روی `public/` تنظیم می‌شود.
- فایل SQLite و لاگ‌ها نباید از وب قابل دانلود باشند.
- در صورت افشای credential، کلید مربوطه در همان سرویس لغو و جایگزین شود.

جزئیات سیاست امنیتی در [SECURITY.md](SECURITY.md) ثبت شده است.

## محدودیت‌ها و ادامه مسیر

- همگام‌سازی خودکار دسته‌بندی‌ها هنوز انجام نمی‌شود و mapping باید تنظیم شود.
- هر `ProductDetail` فعلاً یک محصول مستقل در میکسین است؛ تبدیل به variant نیازمند تصمیم کسب‌وکار است.
- انتقال مشتری، آدرس، سفارش و پرداخت هنوز فعال نیست.
- حذف خودکار محصول انجام نمی‌شود؛ غیرفعال‌سازی و گزارش‌گیری امن‌تر است.
- برای انتقال سفارش باید `orderType`، انبار، روش تسویه، وضعیت پرداخت و قواعد تشخیص مشتری تکراری تعیین شوند.
- داشبورد فعلی فقط خواندنی است؛ کنترل pause/resume و retry دستی در نسخه بعدی قابل اضافه‌شدن است.

## مستندات پروژه

- [معماری و جریان داده](docs/architecture.md)
- [تنظیمات و استقرار](docs/configuration.md)
- [استقرار روی سرور لینوکسی](docs/linux-deployment.md)
- [آماده‌سازی بازارا و API محک](docs/mahak-setup.md)
- [سازگاری APIها](docs/api-compatibility.md)
- [ماتریس انتقال اطلاعات](docs/transfer-matrix.md)
- [قرارداد نگاشت داده](docs/mapping.md)
- [راهنمای عملیات و بازیابی](docs/operations.md)
- [قرارداد API داخلی](docs/internal-api.md)
- [وضعیت فعلی پروژه](docs/project-status.md)
- [نسخه OpenAPI هر دو سرویس](docs/reference/README.md)
- [تاریخچه تغییرات](CHANGELOG.md)

## مجوز فونت

داشبورد و فایل راهنمای مشتری از فونت وزیرمتن استفاده می‌کنند. فایل فونت و مجوز OFL در `public/assets/fonts/` قرار دارند.
