# قواعد البيانات في الاختبارات (B-05)

## الإصدارات المستخدمة

| البيئة | الإصدار | المصدر |
|---|---|---|
| خادم Windows دون اتصال | MariaDB 10.11.10 | `.github/workflows/offline-windows.yml` (`MARIADB_VERSION`) |
| staging على الخادم | MariaDB 11.8 | `deploy/vps/docker-compose.yml` |
| CI: اختبارات سريعة | SQLite في الذاكرة | `phpunit.xml` |
| CI: اختبارات تكاملية | MariaDB 10.11.10 **و** 11.8 | `.github/workflows/tests.yml` (job `mariadb`) |

**القرار الحالي:** الإصداران مدعومان، والمجموعة كاملة تعمل على كل منهما في كل PR. لا يُدمج تغيير يفشل على أي منهما.
توحيد Windows على 11.8 ممكن لاحقاً، لكنه ترقية رئيسية لكل تثبيت قائم (تحتاج `mariadb-upgrade` ونسخة قبلها)، فيُعالج مع خطة التحديث لا هنا.

## الفروقات المعروفة والمختبرة

| الفرق | الأثر | أين يُختبر |
|---|---|---|
| متغير مستوى العزل: 10.11 يعرف `tx_isolation` فقط، و11.1+ يضيف `transaction_isolation` | أي كود يقرأ أحدهما بالاسم يفشل على الآخر | `MariaDbEnvironmentTest::test_row_locks_are_available_inside_transactions` |
| SQLite لا يطبق أطوال الأعمدة ولا الوضع الصارم | قيمة أطول من العمود تمر في SQLite وتفشل في الإنتاج | `test_session_runs_in_strict_mode` |
| SQLite لا يدعم أقفال الصفوف (`FOR UPDATE`) ولا الـ Triggers بنفس الصيغة | اختبارات التزامن (B-06، B-07) والـ Trigger (B-09) لا معنى لها إلا على MariaDB | مجموعة `mariadb` |

## المجموعة `mariadb`

اختبارات بالوسم `#[Group('mariadb')]` تتخطى نفسها على SQLite، وتعمل في job الـ MariaDB. الـ job يشغلها مرة ثانية بـ `--group mariadb --fail-on-skipped`، فيفشل إذا تخطّت نفسها هناك، حتى لا يمر فحص لم يعمل.

بعضها يشغل عملية PHP ثانية حقيقية (عامل `queue:work`، أمر artisan) تقرأ نفس القاعدة، فتستخدم بيانات ملتزمة (`migrate:fresh`) لا معاملة `RefreshDatabase`.

## التشغيل محلياً

```bash
# قاعدة فارغة ومستخدم لها (مثال)
mariadb -uroot -e "CREATE DATABASE sis_test; CREATE USER 'sis'@'localhost' IDENTIFIED BY 'sis'; GRANT ALL ON sis_test.* TO 'sis'@'localhost';"

# B-09: قاعدة ثانية وحساب محصور بها، لاستعادة نسخة احتياطية بحساب غير الذي أنشأ الـ Triggers
mariadb -uroot -e "CREATE DATABASE sis_restore; CREATE USER 'sis_restorer'@'localhost' IDENTIFIED BY 'restorer'; GRANT ALL ON sis_restore.* TO 'sis_restorer'@'localhost';"

# G6a: an application account without grants (the tests grant it per table), and GRANT OPTION for the tests' account
mariadb -uroot -e "CREATE USER 'sis_app_test'@'%' IDENTIFIED BY 'app-local'; GRANT ALL ON sis_test.* TO 'sis'@'localhost' WITH GRANT OPTION;"

DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_DATABASE=sis_test DB_USERNAME=sis DB_PASSWORD=sis \
SIS_RESTORE_DB_DATABASE=sis_restore SIS_RESTORE_DB_USERNAME=sis_restorer SIS_RESTORE_DB_PASSWORD=restorer \
SIS_APP_DB_USERNAME=sis_app_test SIS_APP_DB_PASSWORD=app-local \
  php vendor/bin/phpunit
```

يحتاج اختبار النسخ والاستعادة `mariadb-dump` و`mariadb` (حزمة `mariadb-client`). على MariaDB لا يُتخطى إذا غابت متغيرات `SIS_RESTORE_DB_*`، بل يفشل.

متغيرات البيئة تتقدم على قيم `phpunit.xml`، فلا حاجة لتعديله. **لا تُستخدم بيانات حقيقية**: الاختبارات تبني بياناتها وتمسحها (`RefreshDatabase`).

## B-10: إذا وجد الفحص اليومي خللاً في الـ Triggers

`php artisan sis:check-triggers` يعمل كل صباح (06:00 بتوقيت بغداد). إذا غاب Trigger أو لم يرفض التعديل، يكتب الفحص `critical` في السجل، وينبّه حسابات مدير النظام داخل النظام، ويخرج برمز 1. **الفحص لا يصلح شيئاً بنفسه**، لأن الإصلاح التلقائي يخفي أن أحداً حذف الحماية.

1. ابحث أولاً عن سبب الحذف.
2. أعِد الـ Triggers يدوياً بهذا الأمر. هو لا يمس البيانات: `down()` لا يحذف إلا الـ Triggers، و`up()` يعيد إنشاءها.

```bash
php artisan migrate:refresh --path=database/migrations/2026_11_21_000000_protect_locked_final_results.php --force
php artisan sis:check-triggers
```

## G6a: حسابات قاعدة البيانات الثلاثة

| الحساب | يستخدمه | الصلاحيات |
|---|---|---|
| `sis_app` (staging: `DB_USERNAME`) | الموقع والطابور | جدولاً جدولاً: `SELECT, INSERT, UPDATE, DELETE`، و`audit_logs`: `SELECT, INSERT` فقط. بلا DDL ولا `TRIGGER` |
| `sis_maint` | الترحيلات، `db:seed`، `sis:db-grants`، النسخ والاستعادة، الفحص اليومي، المجدول | كل الصلاحيات على قاعدة `sis` وحدها، مع `GRANT OPTION` |
| `root` | الطوارئ فقط | — |

- **أين تُحفظ بيانات الحسابات:**
  - **Windows:** كلمتا مرور `root` و`sis_maint` في `data\sis-accounts.dat`، مشفرتين بـ DPAPI لهذا الجهاز، وتُنسخان مع الإعدادات إلى مجلد النسخ. `sis_app` في `app\.env`.
  - **staging:** `DB_MAINT_USERNAME` و`DB_MAINT_PASSWORD` في `.env`. لا يحتفظ بها إلا المجدول، أما الموقع والطابور فيحذفانها عند التشغيل (`entrypoint.sh`).
- **بعد كل ترحيل:** يُشغَّل `php artisan sis:db-grants` بحساب الصيانة. اللوحة والـ entrypoint يشغّلانه تلقائياً.
- **إذا ضاع ملف الحسابات على Windows:** تُشغَّل MariaDB يدوياً بـ `--skip-grant-tables`، ثم يُعاد تعيين كلمة مرور `root`، ثم يُحذف `sis-accounts.dat`، فتعيد اللوحة إنشاء الحسابات في التشغيل التالي.
- **كل Trigger جديد يُنشأ بترحيل يعمل بحساب الصيانة.** الـ Trigger يعمل بصلاحيات من أنشأه، فإذا أنشأه حساب التطبيق ثم فقد صلاحية `TRIGGER`، تتوقف كل كتابة على الجدول.

