# تنظیمات و استقرار

## ساخت فایل محیطی

`.env.example` را به `.env` کپی کن. `.env` نباید commit شود.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Asia/Tehran
BRIDGE_API_KEY=یک-رشته-تصادفی-بلند

MAHAK_BASE_URL=https://mahakacc.mahaksoft.com/API/v3
MAHAK_USERNAME=...
MAHAK_PASSWORD=...
MAHAK_DATABASE_ID=YOUR_DATABASE_ID
MAHAK_VISITOR_ID=YOUR_VISITOR_ID
MAHAK_PACKAGE_NO=...

MIXIN_BASE_URL=https://your-real-shop.example
MIXIN_API_KEY=...

SYNC_DRY_RUN=true
SYNC_PAGE_SIZE=100
SYNC_PRICE_DIVISOR=10
SYNC_TIMEOUT_SECONDS=30
SYNC_VERIFY_TLS=true
STATE_DB=var/bridge.sqlite
```

## انتخاب واحد قیمت

مستند Mixin قیمت را تومان اعلام می‌کند. اگر قیمت محک در حساب شما ریال است، مقدار `SYNC_PRICE_DIVISOR=10` صحیح است. اگر محک نیز تومان برمی‌گرداند، مقدار را `1` قرار بده. این مورد باید با حداقل سه کالای واقعی در dry-run کنترل شود.

## مجوز فایل‌ها

کاربر PHP فقط به `var/` نیاز به دسترسی نوشتن دارد. سورس و `.env` باید برای کاربر وب فقط خواندنی باشند.

```bash
mkdir -p var/log
chmod 750 var var/log
chmod 640 .env
```

## اجرای Docker

```bash
docker compose up --build -d
curl http://127.0.0.1:8080/health
```

فایل `.env` به کانتینر تزریق می‌شود و SQLite در volume محلی `./var` باقی می‌ماند.

## Apache/Nginx

Document root باید `public/` باشد، نه ریشه ریپو. تمام مسیرها را به `public/index.php` هدایت کن. TLS را در reverse proxy فعال و دسترسی endpointهای مدیریتی را ترجیحاً با allow-list IP محدود کن.

## Production checklist

- `APP_DEBUG=false`
- کلید مستقل و طولانی برای `BRIDGE_API_KEY`
- HTTPS معتبر در هر دو مقصد
- تست دستی `mahak:login` و `mixin:health`
- بررسی dry-run کالاها و تبدیل قیمت
- پشتیبان از فروشگاه و فایل SQLite
- فقط یک Cron فعال
- مانیتور کردن exit code و حجم `var/log/cron.log`
