# پل ارتباطی محک و میکسین

این پروژه یک سرویس سبک و کم‌مصرف برای انتقال افزایشی کالاها از **نرم‌افزار حسابداری محک** به **فروشگاه میکسین (Evya)** است. سرویس با PHP 8.2+ و SQLite پیاده‌سازی شده و برای اجرا روی سرورهای کم‌منبع و CloudPanel به سرویس دائمی Node.js، Redis یا MySQL نیاز ندارد.

## وضعیت فعلی پروژه

- اتصال واقعی به Mahak API v3 و Mixin API v4 برقرار و تأیید شده است.
- چهار کالای واقعی همراه نام، قیمت، موجودی، دسته‌بندی و تصویر به میکسین منتقل شده‌اند.
- تبدیل قیمت حساب محک از ریال به تومان با `SYNC_PRICE_DIVISOR=10` انجام می‌شود.
- همگام‌سازی افزایشی عمومی و به‌روزرسانی کالاهای نگاشت‌شده با موفقیت آزمایش شده است.
- اجرای تکراری از ساخت محصول و تصویر تکراری جلوگیری می‌کند.
- ۱۰ تست خودکار روی سرور اصلی بدون خطا اجرا شده‌اند.
- داشبورد فارسی در `https://bridge.sayid.ir/dashboard` فعال است و از فونت محلی وزیرمتن استفاده می‌کند.
- سرویس در حالت معمول با `SYNC_DRY_RUN=true` ایمن می‌ماند؛ نوشتن عمومی فقط هنگام فعال‌سازی آگاهانه انجام می‌شود.

راهنمای کوتاه قابل ارائه به مشتری: [دانلود فایل PDF](output/pdf/mahak-mixin-customer-guide-fa.pdf)

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

## نصب روی CloudPanel

در CloudPanel یک PHP Site از نوع Generic بسازید و Root Directory را روی مسیر `public` پروژه قرار دهید. نمونه استقرار فعلی:

```text
/home/sayid-bridge/htdocs/bridge.sayid.ir/app/public
```

سپس با کاربر همان سایت وارد SSH شوید:

```bash
sudo -iu sayid-bridge
cd /home/sayid-bridge/htdocs/bridge.sayid.ir
git clone https://github.com/moghadam-pro/mahak-mixin-api.git app
cd app
cp .env.example .env
mkdir -p var/log
chmod 600 .env
chmod -R 770 var
```

فایل `.env` را با اطلاعات واقعی تکمیل کنید. این فایل و دیتابیس SQLite نباید وارد Git شوند.

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
curl https://bridge.sayid.ir/health
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

## زمان‌بندی Cron

بعد از تأیید نهایی قواعد انتقال و فعال‌سازی نوشتن عمومی، فقط یک Cron تعریف کنید:

```cron
*/10 * * * * cd /home/sayid-bridge/htdocs/bridge.sayid.ir/app && /usr/bin/php8.3 bin/console sync:products >> var/log/cron.log 2>&1
```

Bridge با فایل lock از اجرای هم‌زمان جلوگیری می‌کند، اما همچنان تنها یک Scheduler توصیه می‌شود.

## داشبورد مشتری

- نشانی: `https://bridge.sayid.ir/dashboard`
- نمایش حالت سرویس به‌صورت «فعال (فقط پل)».
- نمایش وضعیت ارتباط API محک و میکسین با بروزرسانی دستی و بدون درخواست خودکار هنگام بازشدن صفحه.
- نمایش تعداد محصولات نگاشت‌شده.
- نمایش آخرین اجرای موفق و آمار دریافت، ایجاد، به‌روزرسانی و ردشدن.
- نمایش checkpointهای همگام‌سازی.
- ورود با session امن، password hash و محافظت CSRF برای خروج.
- استفاده از نسخه self-hosted فونت وزیرمتن، بدون CDN خارجی.

داشبورد فعلی فقط برای مشاهده وضعیت است و دکمه اجرای sync یا تغییر تنظیمات ندارد.

## به‌روزرسانی نسخه روی سرور

```bash
cd /home/sayid-bridge/htdocs/bridge.sayid.ir/app
/usr/bin/git pull --ff-only origin main
php8.3 tests/run.php
```

فایل‌های `.env` و `var/` با `git pull` جایگزین نمی‌شوند.

## API داخلی Bridge

| متد | مسیر | احراز هویت | کاربرد |
|---|---|---|---|
| `GET` | `/health` | ندارد | سلامت خود Bridge، بدون تماس با سرویس خارجی |
| `GET` | `/connections` | هدر `X-Bridge-Key` | آزمایش اتصال واقعی محک و میکسین |
| `POST` | `/sync/products` | هدر `X-Bridge-Key` | اجرای sync با رعایت `SYNC_DRY_RUN` |
| `GET` | `/login` | عمومی | فرم ورود داشبورد |
| `GET` | `/dashboard` | session | مشاهده وضعیت محلی انتقال‌ها |
| `POST` | `/logout` | session و CSRF | خروج از داشبورد |

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
- [استقرار روی CloudPanel](docs/cloudpanel-deployment.md)
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
