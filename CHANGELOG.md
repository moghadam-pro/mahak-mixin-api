# Changelog

## Unreleased

- تأیید موفق چرخه ایجاد، خواندن و حذف محافظت‌شده محصول تست Mixin شماره `222` در دسته‌بندی موجود `6`.
- ثبت الزام واقعی دسته‌بندی هنگام ایجاد محصول، با وجود optional بودن آن در OpenAPI.
- نمایش بدنه خطاهای HTTP در CLI برای تشخیص validation API با حذف خودکار credentialها.
- افزودن بازرسی فقط‌خواندنی دسته‌بندی‌های Mixin و الزام دسته‌بندی موجود برای محصول تست.
- پنل مشتری، صف کارها، retry/backoff، circuit breaker و قفل worker در roadmap هستند.
- snapshot کامل OpenAPI محک و Mixin به منابع پروژه اضافه شد.
- وضعیت استقرار، تصمیم‌های قطعی و چک‌لیست ادامه پروژه مستند شد.
- ماتریس انتقال داده و smoke test کنترل‌شده محصول Mixin اضافه شد.

## 2026-09-19

- تأیید خواندن واقعی ۴ Product، ۲ ProductCategory، ۴ ProductDetail و ۴ VisitorProduct از محک.
- تأیید اتصال Mixin API v4 و پاسخ health نسخه `4.0.0`.
- رفع خطای type ناشی از تبدیل شناسه عددی ProductDetail به integer در کلیدهای آرایه PHP.
- اضافه‌شدن fallback قیمت از `VisitorProduct.Price` صفر به `ProductDetail.Price1`.
- trim شدن فاصله ابتدا و انتهای نام محصول.
- اضافه‌شدن فرمان امن `mahak:products:diagnose`.
- افزایش تست‌ها به ۶ مورد؛ صفر failure و صفر skipped.
- اجرای موفق dry-run با ۴ create، صفر update و صفر skip؛ بدون نوشتن production.
- ثبت نیاز به تأیید دستی واحد قیمت، `Count1` در برابر `Count2` و اولین اجرای تک‌محصولی.

## 2026-09-17

- تغییر همه endpointهای Mahak به نسخه‌های بدون V2 مطابق اعلام پشتیبانی.
- اضافه‌شدن فرمان امن `mahak:products:inspect`.
- ثبت فرایند ارسال اولیه کالا از بازارا.
- تکمیل راهنمای استقرار CloudPanel و عیب‌یابی مسیر پروژه.
- مستندسازی تفاوت `MIXIN_BASE_URL` با مسیر `/api/v4`.

## 2026-09-15

- ایجاد ساختار اولیه Bridge با PHP 8.2+.
- اضافه‌شدن کلاینت‌های Mahak و Mixin.
- همگام‌سازی افزایشی محصول با checkpoint، mapping و snapshot در SQLite.
- اضافه‌شدن dry-run، HTTP health endpoint، Docker، CI و تست‌های mapper.
