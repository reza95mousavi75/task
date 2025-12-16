# طرح فنی پلاگین و ساختار کد

این سند جزئیات پیاده‌سازی پلاگین ماژولار را توصیف می‌کند تا تیم توسعه بتواند MVP را با حداقل ابهام اجرا کند.

## ساختار پوشه‌ها (پیشنهادی)
```
clinic-ms/
├── clinic-ms.php              # bootstrap پلاگین (register_activation_hook, REST init)
├── config/
│   ├── services.php           # تعریف سرویس‌ها، bindingها
│   ├── modules.php            # لیست ماژول‌های پیش‌فرض و وابستگی‌ها
│   └── sms-templates.php
├── src/
│   ├── Core/
│   │   ├── ModuleRegistry.php
│   │   ├── ServiceContainer.php
│   │   ├── MigrationRunner.php
│   │   ├── EventBus.php       # wrap روی do_action/apply_filters
│   │   └── Http/RestServiceProvider.php
│   ├── Appointment/
│   │   ├── Http/Controllers/AppointmentController.php
│   │   ├── Services/BookingService.php
│   │   ├── Repositories/AppointmentRepository.php
│   │   ├── Models/Appointment.php
│   │   └── Database/migrations/*.php
│   ├── EMR/ ...
│   ├── Finance/ ...
│   └── Shared/
│       ├── Support/helpers.php
│       ├── ValueObjects/*.php
│       └── Traits/Transactional.php
├── resources/
│   ├── views/                 # templateهای overrideable
│   └── lang/
├── public/                    # assetهای کامپایل‌شده (js/css)
└── tests/
    ├── Unit/
    ├── Integration/
    └── fixtures/
```

## Bootstrap و Lifecycle
1. **بارگذاری تنظیمات**: خواندن `config/services.php` و ثبت binding در ServiceContainer.
2. **Module Registry**: بارگذاری ماژول‌ها از DB و `config/modules.php`، بررسی dependency و activate/deactivate.
3. **Migration Runner**: اجرای migrationهای ماژول فعال در زمان فعال‌سازی یا ارتقا نسخه.
4. **ثبت REST routes**: هر ماژول یک `RestServiceProvider` دارد که در hook `rest_api_init` ثبت می‌شود.
5. **Asset loading**: بارگذاری JS/CSS فقط در صفحاتی که نیاز است (enqueue با handle اختصاصی، نسخه‌دهی cache-busting).
6. **Uninstall**: اگر allow-full-remove فعال باشد، داده‌های ماژول حذف می‌شود؛ در حالت عادی فقط غیرفعال می‌شود.

## قرارداد Module
```php
interface ModuleContract {
    public function register(ModuleRegistry $registry): void;
    public function activate(): void;     // migration + seed
    public function deactivate(): void;   // stop cron/listeners
    public function uninstall(bool $withData = false): void;
}
```
- هر ماژول فایل bootstrap خود را در `src/<Module>/Module.php` دارد که این قرارداد را پیاده می‌کند.
- متادیتا (نسخه، وابستگی‌ها، مسیر migration) در `config/modules.php` نگهداری می‌شود.

## الگوی Service Container
- Singleton/Scoped binding برای سرویس‌ها (BookingService، PaymentGateway، SmsSender، PdfGenerator).
- در تست، می‌توان binding‌ها را mock کرد (مثلاً PaymentGatewayMock).
- Dependency Injection در Controller/Service/Repository برای کاهش coupling.

## Migration و نسخه‌بندی Schema
- فایل‌های migration با نام‌گذاری زمان‌محور (مثلاً `2025_01_01_0001_create_appointments.php`).
- `MigrationRunner` نسخه جاری را در `wp_options` با کلید `ms_schema_version` ذخیره می‌کند.
- برای rollback ساده، migrationها `down()` دارند اما در محیط production فقط forward اجرا می‌شود.

## API و کنترلرها
- هر کنترلر REST از `WP_REST_Controller` مشتق می‌شود و به Serviceها وابسته است.
- Request Validation با `rest_validate_value_from_schema` و schemaهای JSON برای بدنه‌ها.
- Nonce/JWT بر اساس context (admin vs app)؛ نرخ‌دهی محدود (`rest_pre_dispatch`).

## خطا و لاگ
- `WP_Error` ساختار استاندارد برای پاسخ‌های خطا (کد و message محلی‌سازی‌شده).
- لاگ ساختاریافته (channel `clinic_ms`) با context: user_id, request_id, module, severity.
- Alert روی رویدادهای حیاتی (payment.failed, sms.failed, booking.lock_timeout).

## Performance
- Cache لایه Repository با TTL کوتاه برای slotها و تقویم.
- Query ایمن و index-aware؛ استفاده از `prepare` و محدودیت صفحه‌بندی.
- صف async برای SMS/PDF و sync بیمه (Action Scheduler یا Redis Queue).

## امنیت و مجوزها
- Capabilities: `manage_clinic`, `manage_appointments`, `manage_patients`, `manage_finance`، map به نقش‌ها.
- مالکیت داده: هر provider فقط داده خودش را می‌بیند؛ منشی با دسترسی شعبه محدود.
- فیلدهای حساس (national_code, otp_hash) با encryption-at-rest و دسترسی کنترل‌شده در Repository.

## قابلیت Override UI
- قالب‌ها در `resources/views` با path مثل `appointment/form.php`؛ theme می‌تواند با قرار دادن فایل هم‌نام در `wp-content/themes/<theme>/clinic-ms/` override کند.
- استایل‌ها قابل سفارشی‌سازی با CSS variables و SASS map.

## Build و Dev Experience
- استفاده از Composer برای autoload (`psr-4: {"MS\\": "src/"}`) و npm برای asset build.
- اسکریپت‌های npm: `build`, `dev`, `lint`، و استفاده از Vite یا Webpack light.
- Docker dev-stack: PHP-FPM 8.1، Nginx، MySQL 8، Redis.

## تست
- PHPUnit برای Unit/Integration (با WP test suite)، پوشش BookingService، PaymentWebhookHandler، SmsAdapter.
- Contract test برای REST (snapshot JSON) و load test script (k6) برای مسیر رزرو.
- Static analysis: PHPStan/Psalm level 6+، و ESLint/Prettier برای JS.
