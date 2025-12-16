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

## نمونه پیاده‌سازی اولیه در مخزن
- مسیر: `plugin/clinic-manager/`
- فایل‌های کلیدی:
  - `clinic-manager.php`: bootstrap پلاگین، بارگذاری ServiceContainer و ModuleRegistry و ثبت ماژول‌ها.
  - `includes/Core/ModuleInterface.php`: قرارداد ماژول‌ها.
  - `includes/Core/ModuleRegistry.php`: مدیریت enable/disable و boot ماژول‌های ثبت‌شده با نگهداری state در `wp_options`.
  - `includes/Core/Plugin.php`: اتصال lifecycle وردپرس (activate/deactivate) به ماژول‌ها و register کردن منوی Admin برای مدیریت ماژول‌ها.
  - `includes/Admin/ModulesPage.php`: صفحه‌ی داشبورد برای فعال/غیرفعال کردن ماژول‌ها (state در `wp_options` ذخیره می‌شود).
  - `includes/Modules/Appointment/AppointmentModule.php`: ماژول نوبت‌دهی اولیه با ثبت post type، ثبت statusهای سفارشی (reserved/confirmed/cancelled/noshow)، endpoint رزرو و بروزرسانی وضعیت، endpoint لیست/نمایش نوبت‌ها برای داشبورد، و ایجاد جداول `ms_appointments` و `ms_time_slots`.
  - `includes/Modules/EMR/EMRModule.php`: ماژول پرونده الکترونیک اولیه با post typeهای Patient و Visit و endpointهای ساخت/بازیابی بیمار، جستجو/لیست بیماران، ثبت و لیست ویزیت به‌همراه تاریخ و متادیتا.
  - `includes/Modules/Finance/FinanceModule.php`: ماژول مالی اولیه با جداول `ms_payments` و `ms_wallets`، endpoint ثبت پرداخت، webhook برای تغییر وضعیت و شارژ کیف پول، و endpoint خواندن مانده کیف پول پزشک.
  - `includes/Modules/Settings/SettingsModule.php`: ماژول تنظیمات هسته برای نگهداری اطلاعات مطب (نام، آدرس، ساعت کاری، timezone)، قالب پیامک و تنظیمات پایه پرداخت از طریق wp_options و endpoint‌های REST محافظت‌شده.
  - `includes/Modules/SMS/SMSModule.php`: ماژول پیامک/اتوماسیون با endpoint OTP و verify، جدول `ms_otps` برای مدیریت کدها و جدول `ms_rules` برای قوانین اتوماسیون، به همراه Sender داخلی برای لاگ پیامک و فیلتر `ms_sms_validate_otp` برای مصرف OTP در ماژول‌ها.
  - `includes/Modules/Growth/GrowthModule.php`: ماژول رشد/مارکتینگ با post type نقد و بررسی، endpoint ثبت و لیست بررسی‌ها، شمارنده بازدید پروفایل پزشک و خروجی آماری (بازدید، تعداد رزرو/بازخورد و میانگین امتیاز).
  - `includes/Modules/API/ApiGatewayModule.php`: ماژول API Gateway/Webhooks با جدول `ms_webhooks`، endpoint مدیریت و تست وبهوک‌ها، امضای HMAC در هدر، و dispatch خودکار رویدادهای `appointment.booked` و `appointment.status_changed`.
  - `includes/Modules/Support/SupportModule.php`: ماژول پشتیبانی با post type `ms_ticket` و endpointهای ایجاد تیکت، لیست/جزئیات، افزودن پیام و بروزرسانی status (open|pending|resolved|closed) برای پنل منشی/پزشک.

### نحوه تست دستی در WordPress محلی
1. پوشه‌ی `plugin/clinic-manager` را داخل `wp-content/plugins` کپی کنید.
2. پلاگین “Clinic Manager” را در داشبورد فعال کنید تا جداول اولیه ساخته شود.
3. در منوی “Clinic Manager” می‌توانید ماژول‌ها را فعال/غیرفعال کنید و نتیجه را در لیست ببینید (state در گزینه‌ی `ms_modules`).
4. با `POST /wp-json/ms/v1/appointments` و بدنه‌ی `{ "patient_name": "Ali", "phone": "09...", "service_id": 1, "provider_id": 7, "slot_time": "2024-06-01 10:00" }` یک رزرو نمونه ثبت کنید. اگر برای همان provider و همان زمان رزرو فعال وجود داشته باشد، پاسخ 409 دریافت می‌کنید.
5. با `GET /wp-json/ms/v1/appointments?provider_id=7&date_from=2024-06-01` نوبت‌های آینده را لیست کنید و با `GET /wp-json/ms/v1/appointments/{id}` جزئیات نوبت را ببینید.
6. وضعیت نوبت را با `PATCH /wp-json/ms/v1/appointments/{id}/status` و بدنه‌ی `{ "status": "confirmed" }` بروزرسانی کنید؛ post_status به صورت خودکار به status مرتبط (`ms_confirmed`) تغییر می‌کند.
7. در Admin، post typeهای `Appointments`، `Patients` و `Visits` برای مشاهده رکوردها ظاهر می‌شوند؛ همچنین می‌توانید با `GET /wp-json/ms/v1/patients?search=09...` یا `GET /wp-json/ms/v1/visits?patient_id=991` جستجو و لیست را از طریق API انجام دهید.
8. تست مالی: با `POST /wp-json/ms/v1/payments` و بدنه‌ی `{ "provider_id": 7, "amount": 120000, "method": "online" }` پرداختی ایجاد کنید (status اولیه `pending`). سپس با تنظیم `ms_payment_webhook_secret` و ارسال درخواست به `/wp-json/ms/v1/payments/webhook` با بدنه‌ی `{ "payment_id": <id>, "status": "paid" }`، مانده کیف پول پزشک را از `/wp-json/ms/v1/wallets/7` مشاهده کنید.
9. تست پشتیبانی: با `POST /wp-json/ms/v1/support/tickets` یک تیکت بسازید، سپس با `POST /wp-json/ms/v1/support/tickets/{id}/messages` پاسخ منشی را ثبت و با `PATCH /wp-json/ms/v1/support/tickets/{id}/status` وضعیت را به `resolved` تغییر دهید.

### Directory module
- Post types: `ms_provider`, `ms_service` (show_ui true, non-public, REST enabled).
- REST: list/get providers, create provider (admin), create service (admin), list services by provider.
- Activation: only flushes rewrite rules; data حفظ می‌شود. ورودی‌ها sanitize می‌شوند (`sanitize_text_field`, `wp_kses_post`, `absint`, `floatval`).
- خروجی provider شامل خدمات مرتبط است تا داشبورد/فرانت‌اند بتواند کارت پزشک و خدمات را با یک درخواست واکشی کند.
