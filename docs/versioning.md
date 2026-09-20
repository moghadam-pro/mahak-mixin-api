# نسخه‌گذاری و انتشار

پروژه از Semantic Versioning با قالب `MAJOR.MINOR.PATCH` استفاده می‌کند:

- `PATCH`: رفع باگ سازگار با نسخه فعلی.
- `MINOR`: قابلیت جدید سازگار با نسخه فعلی.
- `MAJOR`: تغییر ناسازگار در قرارداد، تنظیمات یا رفتار سرویس.

فایل ریشه `VERSION` منبع واحد شماره نسخه است. کلاس `MahakMixin\Version` همین فایل را می‌خواند و مقدار آن را در داشبورد، `/health` و `php bin/console --version` ارائه می‌کند. درج دستی شماره نسخه در چند فایل مجاز نیست؛ به‌جز ثبت تاریخچه نسخه در README و CHANGELOG.

## چک‌لیست انتشار

1. شماره نسخه را در `VERSION` تغییر دهید.
2. تغییرات بخش `Unreleased` را در `CHANGELOG.md` زیر عنوان نسخه و تاریخ انتشار منتقل کنید.
3. `composer check` را اجرا کنید.
4. خروجی `php bin/console --version` و `/health` را بررسی کنید.
5. commit انتشار را بسازید.
6. tag امضاشده یا annotated با قالب `vMAJOR.MINOR.PATCH` ایجاد و push کنید.

نمونه:

```bash
git tag -a v0.1.0 -m "Release v0.1.0"
git push origin main --follow-tags
```
