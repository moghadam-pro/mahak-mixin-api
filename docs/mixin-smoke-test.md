# تست کنترل‌شده Mixin

این تست برای زمانی است که دسترسی به سیستم محک وجود ندارد ولی Mixin API در دسترس است. رکورد آزمایشی به‌صورت ناموجود و بدون موجودی ساخته می‌شود تا قابل فروش نباشد.

## ۱. تست‌های فقط‌خواندنی

```bash
php8.3 bin/console mixin:health
php8.3 bin/console mixin:info
php8.3 bin/console mixin:test-product:preview
```

فرمان preview هیچ request نوشتنی ارسال نمی‌کند و payload پیشنهادی را نمایش می‌دهد.

## ۲. فعال‌سازی موقت نوشتن تستی

در `.env`:

```dotenv
MIXIN_ALLOW_TEST_WRITES=true
```

این فلگ مستقل از `SYNC_DRY_RUN` است و فقط فرمان‌های test-product را فعال می‌کند.

## ۳. ایجاد محصول تست

```bash
php8.3 bin/console mixin:test-product:create
```

ویژگی‌های ایمنی:

- نام با `[Bridge Test]` شروع می‌شود.
- `available=false`
- `stock=0`
- `stock_type=out_of_stock`
- شناسه با `bridge-test-` شروع می‌شود.
- `external_ids` شامل marker اختصاصی Bridge است.

شناسه محصول برگشتی را یادداشت و وجود آن را در پنل Mixin بررسی کنید.

## ۴. حذف امن رکورد تست

```bash
php8.3 bin/console mixin:test-product:delete PRODUCT_ID
```

قبل از DELETE، Bridge محصول را دوباره می‌خواند و نام، شناسه و `external_ids` را کنترل می‌کند. اگر marker کامل وجود نداشته باشد، حذف رد می‌شود؛ بنابراین این فرمان نباید محصول واقعی را حذف کند.

## ۵. بستن مجوز نوشتن

بلافاصله پس از تست:

```dotenv
MIXIN_ALLOW_TEST_WRITES=false
```

سپس اجرای create باید با پیام `Test writes are disabled` متوقف شود.

## نکات

- شناسه یا پاسخ دارای داده حساس را در issue عمومی قرار ندهید.
- برای اولین تست category، customer یا order نسازید؛ محصول ناموجود کم‌ریسک‌ترین موجودیت است.
- اگر create موفق و delete ناموفق شد، فلگ را false کنید و رکورد `[Bridge Test]` را از پنل Mixin حذف کنید.
