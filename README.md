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
