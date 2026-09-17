# آماده‌سازی محک و بازارا

## endpointهای مورد تأیید

طبق اعلام پشتیبانی محک برای این اتصال نباید از endpointهای دارای پسوند `V2` استفاده شود:

```text
POST /Sync/Login
POST /Sync/GetAllData
POST /Sync/SaveAllData
```

پیش‌فرض‌های Bridge نیز همین مسیرها هستند. برای کنترل سورس:

```bash
grep -RE "LoginV2|GetAllDataV2|SaveAllDataV2" -n src bin
```

در نسخه صحیح این دستور خروجی ندارد.

## آماده‌سازی اولیه کالا

وجود کالا در نرم‌افزار حسابداری به‌تنهایی باعث بازگشت آن از API نمی‌شود. طبق راهنمای پشتیبانی، کالا باید از داخل بازارا برای سایت ارسال شود:

```text
نرم‌افزار محک
→ عملیات خاص
→ بازارا
→ کالاهای ارسالی
→ کالای جدید
→ انتخاب کد کالا
→ اضافه‌کردن به بازارا
→ ارسال و دریافت اطلاعات
```

برای اولین آزمایش فقط یک کالا با نام، barcode، قیمت و موجودی مشخص ارسال کنید. بعد از موفقیت mapping، تعداد کالاها را افزایش دهید.

## تست بدون افشای داده

```bash
php8.3 bin/console mahak:login
php8.3 bin/console mahak:products:inspect
```

فرمان inspect فقط این موارد را نمایش می‌دهد:

- Visitor و Database برگشتی از Login
- نتیجه و پیام GetAllData
- نام collectionهای پاسخ
- تعداد Products، ProductDetails، ProductCategories و VisitorProducts

توکن و محتوای کالاها نمایش داده نمی‌شود.

## تفسیر خروجی صفر

اگر `Result=true` است ولی چهار collection محصول صفر هستند، transport و احراز هویت سالم‌اند اما سرور محک برای Visitor داده‌ای برنگردانده است. موارد زیر را بررسی کنید:

1. کالا در بخش «کالاهای ارسالی» بازارا اضافه شده باشد.
2. «ارسال و دریافت اطلاعات» داخل نرم‌افزار اجرا و کامل شده باشد.
3. Visitor برگشتی از Login همان سایت تعریف‌شده در بازارا باشد.
4. پشتیبانی محک وضعیت تخصیص کالا به Visitor و سرویس بازارا را بررسی کند.

`WithDataTransfer` فیلدی در پاسخ Login است. Bridge این فیلد را در هیچ request ارسال نمی‌کند و تصمیم انتقال را بر اساس آن نمی‌گیرد.

## متن پیشنهادی برای پشتیبانی

```text
Login با /Sync/Login موفق است. درخواست /Sync/GetAllData بدون V2 و با
currentVisitorId برگشتی از Login و RowVersion صفر، Result=true می‌دهد؛
اما Products، ProductDetails، ProductCategories و VisitorProducts خالی‌اند.
لطفاً ارسال اولیه بازارا و تخصیص کالا به Visitor را بررسی کنید.
```
