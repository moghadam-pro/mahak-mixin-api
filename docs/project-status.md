# وضعیت فعلی پروژه

آخرین بروزرسانی: `2026-09-17`

## وضعیت پیاده‌سازی

| بخش | وضعیت | توضیح |
|---|---|---|
| استقرار CloudPanel | تأییدشده | PHP 8.3، Document Root روی `app/public` |
| PHP extensions | تأییدشده | curl، json، PDO و pdo_sqlite |
| Bridge health | تأییدشده | `GET /health` پاسخ سالم می‌دهد |
| Mahak Login | تأییدشده | `/Sync/Login` موفق و token در CLI مخفی می‌شود |
| Mixin health | تأییدشده | `/api/v4/health/` پاسخ نسخه 4 می‌دهد |
| Mahak GetAllData | تأیید transport | `/Sync/GetAllData` بدون V2؛ آماده بررسی بعد از ارسال اولیه بازارا |
| Product dry-run | آماده | تا دریافت اولین ProductDetail خروجی تغییر ندارد |
| Product write | پیاده‌سازی‌شده ولی غیرفعال | `SYNC_DRY_RUN=true` باقی می‌ماند تا mapping تأیید شود |
| Customer sync | برنامه‌ریزی‌شده | قواعد Person و duplicate detection باید نهایی شود |
| Order sync | برنامه‌ریزی‌شده | orderType، settlement، store و payment باید تعیین شوند |
| Dashboard | برنامه‌ریزی‌شده | پنل سبک server-rendered روی PHP |

## تصمیم‌های قطعی

1. endpointهای محک بدون V2 استفاده می‌شوند.
2. `WithDataTransfer` فقط از پاسخ Login مشاهده می‌شود و در request ارسال نمی‌شود.
3. `MIXIN_BASE_URL` فقط origin است و `/api/v4` ندارد.
4. عملیات نوشتن تا بررسی دستی نام، قیمت، stock و barcode در dry-run فعال نمی‌شود.
5. اولین آزمایش فقط با یک کالای مشخص انجام می‌شود.
6. PHP ساده، PHP-FPM کم‌مصرف و SQLite برای فاز اول حفظ می‌شوند.
7. داشبورد نباید هنگام باز شدن مستقیماً APIهای خارجی را فراخوانی کند؛ فقط state محلی را نمایش می‌دهد.

## وضعیت داده محک

در تست اولیه، Login و GetAllData از نظر transport موفق بودند اما collectionهای محصول خالی بودند. پشتیبانی اعلام کرد کالا باید ابتدا از این مسیر در نرم‌افزار محک ارسال شود:

```text
عملیات خاص → بازارا → کالاهای ارسالی → کالای جدید
→ انتخاب کد کالا → اضافه‌کردن به بازارا → ارسال و دریافت اطلاعات
```

پس از این عملیات باید فرمان زیر دوباره اجرا شود:

```bash
php8.3 bin/console mahak:products:inspect
```

اگر Product، ProductDetail و VisitorProduct برگشتند، مرحله بعد:

```bash
php8.3 bin/console sync:products:dry-run
```

## کارهای لازم پیش از production write

- مقایسه حداقل سه قیمت برای تأیید ریال/تومان.
- مقایسه stock با موجودی بازارا.
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
