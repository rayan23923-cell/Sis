# Iraqi University SIS — نظام معلومات الطلبة الجامعي

نظام معلومات طلبة لجامعة عراقية، مفتوح المصدر ويعمل على خوادم الجامعة. يدعم مسار بولونيا (ECTS) والنظام الفصلي/السنوي التقليدي بالتوازي.

> **الحالة:** المرحلة الأولى (الاستكشاف والتصميم). لا يوجد كود تطبيق بعد.

## البنية المعتمدة
مطابقة لبنية FileNest: Laravel 13 (PHP 8.4)، Inertia v2 + Vue 3 + Tailwind، MariaDB 11.8 LTS، Redis، Docker Compose، GitHub Actions. انظر [ADR-001](docs/ADR.md).

## الوثائق
| الملف | المحتوى |
|---|---|
| [docs/SRS.md](docs/SRS.md) | مواصفات المتطلبات الأولية |
| [docs/ADR.md](docs/ADR.md) | القرارات المعمارية والمخطط المعماري |
| [docs/POC_TEST_PLAN.md](docs/POC_TEST_PLAN.md) | خطة إثبات المفهوم لستة أسابيع |

## البيئة التجريبية
https://sis.azoclinic.duckdns.org — تُنشر تلقائياً من فرع `main` عبر `.github/workflows/deploy-vps.yml` (يحتاج سر `VPS_SSH_KEY`). بيانات وهمية فقط.

## الإصدار الأول (الزيادة 1)

ما يعمل الآن: الهيكل الجامعي والمناهج المرقمة بإصدارات، قبول الطلبة مع فحص التكرار والرقم الجامعي التلقائي،
تغيير حالة الطالب بأمر إداري فقط، كشف الدرجات (إدخال ← احتساب ← رفع للجنة ← مصادقة ← قفل ← الدور الثاني)،
تصحيح النتيجة المقفلة بإصدار جديد مستند إلى أمر إداري، السياسات الأكاديمية المؤرخة (المؤقتة منها معلَّمة «غير رسمية»)،
وسجل تدقيق لا يُعدَّل. الصلاحيات محصورة بنطاق الكلية أو القسم، والتدريسي يرى مواده فقط.

```sh
composer install && npm ci && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate --seed      # سياسات مؤقتة + مدير النظام + بيانات تجريبية (SIS_DEMO_DATA)
php artisan serve
php vendor/bin/phpunit          # اختبارات المحرك وعزل الكليات وسير عمل الدرجات
```

كلمة مرور المدير تُكتب في `storage/app/private/admin-password.txt` (أو من `SIS_ADMIN_PASSWORD`).
حسابات التجربة: `registrar@`، `dean.eng@`، `committee.eng@`، `registrar.eng@`، `teacher1@`… `@sis.local` بكلمة مرور المدير نفسها.
