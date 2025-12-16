# معماری کلان و ماژولار سامانه مدیریت مطب/کلینیک

هدف این سند، ارائه‌ی معماری یکپارچه اما ماژولار برای پیاده‌سازی سامانه به‌عنوان پلاگین وردپرس (با سرویس‌های مکمل) است؛ به‌گونه‌ای که هر ماژول قابل فعال/غیرفعال شدن باشد و در عین حال، داده‌ها بدون تخریب حفظ شوند.

## 1. اصول معماری
- **یکپارچه + ماژولار**: هسته‌ی پلاگین شامل Module Registry، Service Container، Event Bus (hooks/filters + custom events) و لایه‌ی امنیت/اعتبارسنجی است. هر ماژول با قرارداد مشترک ثبت و lifecycle مستقل (install/activate/deactivate/uninstall) دارد.
- **لایه‌بندی OOP**: 
  - Presentation (WP Admin pages, REST endpoints, shortcode/blocks)
  - Service (Business rules، workflowها، orchestratorها)
  - Repository (DB access با `$wpdb` + CRUD کلاس‌بندی شده + caching)
- **API-first**: همه‌ی قابلیت‌ها از طریق REST (WP REST API) و Webhook/Events قابل دسترس‌اند؛ JWT/Nonce برای احراز هویت.
- **Configuration over customization**: تنظیمات ماژول‌ها در wp_options (prefix ms_) و جداول تنظیمات اختصاصی، با schema versioning.
- **Performance & Mobile-first**: کوئری‌های ایندکس‌شده، caching (WP Object Cache + page cache)، صف‌بندی async برای وظایف سنگین (wp-cron/Action Scheduler یا سرویسی مثل Redis Queue).

## 2. ماژول‌ها و دامنه‌ها
- Appointment (Core MVP)
- EMR / Medical Records
- E-Prescription & Insurance Bridges
- Finance & Wallet & Reports
- SMS/Automation (Adapters + Rules Engine)
- Settings (Clinic profile, hours, templates, payment defaults)
- Growth/Marketing
- Support/Ticketing
- UI Panels (پزشک، منشی، مدیریت)
- API Gateway & Webhooks

هر ماژول به‌صورت یک زیرپکیج با namespace `MS\<Module>` و فایل bootstrap ثبت می‌شود. Registry وضعیت فعال/غیرفعال بودن ماژول و مسیر migrationها را نگه می‌دارد.

## 3. Module Registry و Lifecycle
- جدول `wp_ms_modules` (slug, version, status, installed_at, updated_at, meta_json)
- Hookهای استاندارد: `register_module`, `activate_module`, `deactivate_module`, `uninstall_module`
- مهاجرت‌ها با schema version ذخیره می‌شود (مثلاً `2024_01_01_0001_create_tables`).
- قابلیت dependency بین ماژول‌ها (مثلاً Finance نیاز به Appointment دارد).

## 4. لایه داده و جداول کلیدی (نمونه)
- `wp_ms_providers` (id, user_id, name, specialties, bio, rating, created_at)
- `wp_ms_branches` (id, provider_id, title, address_json)
- `wp_ms_services` (id, provider_id, title, duration, price, created_at)
- `wp_ms_schedules` (id, provider_id, branch_id, day_of_week, start_time, end_time, is_recurring)
- `wp_ms_time_slots` (id, schedule_id, start_datetime, end_datetime, capacity, status, lock_token, locked_until)
- `wp_ms_appointments` (id, patient_id, provider_id, service_id, slot_id, status, payment_status, created_at, updated_at)
- `wp_ms_patients` (id, user_id NULLABLE, full_name, phone, national_code, otp_hash, meta_json)
- `wp_ms_visits` (id, appointment_id, provider_id, diagnosis, notes, vitals_json, created_at)
- `wp_ms_medical_records` (id, patient_id, visit_id, notes, files_json, created_at)
- `wp_ms_prescriptions` (id, visit_id, items_json, status, created_at)
- `wp_ms_wallets` (id, provider_id, balance, pending_balance)
- `wp_ms_profile_stats` (provider_id, views, bookings, updated_at)
- Indexing: `time_slots.start_datetime` + compound (provider_id, start_datetime), unique `(schedule_id, start_datetime)` برای جلوگیری از duplicate slot.
- Sensitive data می‌تواند با encryption-at-rest در meta_json ذخیره شود.

## 5. جریان‌های کلیدی
### 5.1 رزرو آنلاین (با جلوگیری از double-booking)
1. دریافت درخواست رزرو (OTP یا کاربر ثبت‌نام‌شده) → Validate service/slot.
2. **Locking**: اجرای `SELECT ... FOR UPDATE` روی `wp_ms_time_slots` یا استفاده از `lock_token` و `locked_until` (fallback بدون InnoDB با compare-and-swap). ظرفیت slot کاهش می‌یابد.
3. ایجاد Appointment در تراکنش؛ وضعیت `pending_payment` در صورت نیاز.
4. ارسال پیامک تایید/OTP، و ایجاد Payment Intent.
5. در دریافت webhook پرداخت: تراکنش ثانویه برای به‌روزرسانی `payment_status=paid` و `status=confirmed`، ثبت لاگ.

### 5.2 پنل منشی/پزشک
- لیست نوبت روز/هفته با فیلتر شعبه/پزشک.
- تغییر وضعیت (reserved, attended, cancelled, no_show).
- افزودن نوبت دستی و بلاک‌کردن بازه زمانی.
- چاپ لیست روزانه و دسترسی سریع به پرونده بیمار.

### 5.3 Mini EMR و نسخه‌نویسی
- پروفایل بیمار با فیلدهای سفارشی (Custom Fields registry).
- ثبت ویزیت (diagnosis, recommendations, meds[]) با Templateهای ذخیره‌شده.
- تولید نسخه PDF استاندارد؛ آماده‌ی ارسال به بیمه از طریق Adapter.

### 5.4 اتوماسیون پیامک
- Adapter Pattern برای درگاه‌ها (MeliPayamak و سایرین).
- Template Engine با Placeholderهای استاندارد ({{patient_name}}, {{appointment_time}}, ...).
- Rule Engine ساده: Event → Condition → Action (delay اختیاری).

## 6. API و وبهوک‌ها
- REST endpoints در namespace `/ms/v1/` (WP REST API):
  - `GET /providers`، `GET /providers/{id}/availability?from=...&to=...`
  - `POST /appointments`، `PATCH /appointments/{id}/status`
  - `POST /patients`، `GET /patients/{id}/records`
  - `POST /payments/webhook`
  - `GET|POST /settings` برای تنظیمات مطب و قالب پیامک
  - `POST /reviews`، `GET /reviews`، `POST /profile-views`، `GET /providers/{id}/growth`
  - `POST /support/tickets`، `GET /support/tickets`، `POST /support/tickets/{id}/messages`, `PATCH /support/tickets/{id}/status`
- احراز هویت: JWT برای اپ‌ها، یا Nonce برای درخواست‌های داخل WP.
- Webhooks برای رخدادها (appointment.created, payment.succeeded, prescription.sent).

## 7. امنیت و حریم خصوصی
- Validation/Sanitization/Escaping در همه لایه‌ها؛ استفاده از prepared statements.
- CSRF (Nonce)، Rate Limiting (per IP/phone)، Brute-force protection برای OTP.
- RBAC بر اساس WP Capabilities (roles: admin, provider, receptionist, patient minimal).
- فایل‌های حساس در مسیر امن و با URLهای امضاشده؛ امکان encryption برای فیلدهای حساس.
- لاگ امنیتی و audit trail برای عملیات حیاتی.

## 8. کارایی و مقیاس‌پذیری
- Caching: Object Cache، page cache برای landing/SEO، prefetch تقویم‌ها.
- Queue/Async: ارسال پیامک، تولید PDF، sync بیمه از صف.
- Observability: لاگ ساختاریافته، متریک‌ها (latency, throughput, failures)، health checks.
- Sharding منطقی: داده‌ی فایل‌ها روی S3/MinIO؛ DB ایندکس‌شده با readiness برای read-replica.

## 9. UI/UX
- Mobile-first, RTL کامل، تم سبک و سازگار با WP admin + front.
- Component Library کوچک (React/Preact برای admin) با hooks سفارشی.
- دسترسی‌پذیری (ARIA) و بارگذاری سریع (<2s داشبورد با کوئری‌های بهینه).
- Theme Override: امکان override templateها با قرار دادن فایل در theme.

## 10. تست و QA
- Unit Test برای Service/Repository (PHPUnit) و snapshot برای Template پیامک.
- Integration Test برای رزرو و پرداخت (WP REST API tests).
- Load Test سناریوی رزرو همزمان (k6/Gatling) با قفل/صف فعال.
- SAST/Dependency Scan (PHPStan/Psalm, composer audit, npm audit اگر نیاز).
- OpenAPI/Swagger برای API؛ Postman collection.

## 11. نقشه‌ی توسعه فازبندی
1. **MVP (اسپرینت‌های 2-3 هفته‌ای)**: Appointment + Mini EMR + Payments + Settings + Receptionist Panel ساده.
2. **فاز 2**: Automation/SMS, Reports, Wallet Settlement, Templates پیشرفته، Growth/SEO pages.
3. **فاز 3**: E-Prescription با اتصال بیمه، اپ موبایل (API-ready)، هوش مصنوعی (OCR, Suggestions).

## 12. پذیرش و استقرار
- Staging با داده‌ی نمونه و تست کانکارنسی برای جلوگیری از double-booking.
- Checklist امنیتی و مانیتورینگ قبل از go-live.
- استقرار ماژولار: امکان غیر‌فعال‌کردن ماژول بدون از دست رفتن داده (migration‌ها idempotent).

### Directory / Provider registry
- ثبت داده‌های پزشک و خدمات روی post types اختصاصی تا با هسته WordPress و meta API منطبق باشد.
- Provider list API به‌عنوان منبع واحد برای ماژول‌های رزرو، رشد و مالی عمل می‌کند؛ meta شامل تخصص‌ها و امتیاز تجمیعی است.
