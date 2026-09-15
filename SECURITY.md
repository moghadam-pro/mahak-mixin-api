# Security Policy

## اطلاعات محرمانه

هیچ credential، توکن، پاسخ واقعی مشتری یا فایل `.env` نباید commit شود. برای هر محیط کلیدهای جداگانه بسازید. اگر secret در issue، commit یا log قرار گرفت، حذف متن کافی نیست و secret باید فوراً revoke/rotate شود.

## گزارش آسیب‌پذیری

آسیب‌پذیری را در issue عمومی همراه با credential یا داده مشتری منتشر نکنید. از بخش Security Advisories خصوصی همین repository استفاده کنید.

## تنظیمات امن

- `APP_DEBUG=false`
- `SYNC_VERIFY_TLS=true`
- `SYNC_DRY_RUN=true` تا زمان تأیید دستی
- document root روی `public/`
- endpointهای مدیریتی پشت HTTPS و allow-list شبکه
- محدودسازی دسترسی فایل `.env` و SQLite
- تعویض دوره‌ای کلیدهای Mahak، Mixin و Bridge

این پروژه عملیات حذف خودکار ندارد. اضافه‌کردن DELETE یا پردازش webhook باید همراه با احراز امضا، idempotency و audit log باشد.
