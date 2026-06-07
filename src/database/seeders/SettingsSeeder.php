<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Seeds the platform-level settings catalogue with sensible defaults.
 *
 * Idempotent — uses updateOrCreate keyed by the `key` column so
 * re-running this seeder (production deploy, test bootstrap) won't
 * duplicate rows or overwrite admin-edited values for keys that
 * already exist with a non-null value.
 *
 * To add a new setting:
 *   1. Append a row here with a unique `key`, the right `type`, a
 *      `group_key` matching one of the existing tabs (or a new
 *      one — also add a label key to the Settings/Index.vue tabs
 *      array), labels EN+AR, and a default `value`.
 *   2. Document it in the APP_KEY rotation runbook IF the new
 *      setting holds anything sensitive (it shouldn't — sensitive
 *      values stay env-only).
 *   3. Run `php artisan db:seed --class=SettingsSeeder` to install
 *      on existing environments.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalogue() as $row) {
            // existsOrIgnore semantics — never overwrite an existing
            // admin-edited value on re-seed. New rows (added by a
            // later catalogue update) flow in cleanly.
            Setting::query()->updateOrCreate(
                ['key' => $row['key']],
                array_diff_key($row, ['key' => true]),
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function catalogue(): array
    {
        return [
            // ============== GENERAL ==============================
            [
                'key' => 'general.platform_name_en',
                'type' => Setting::TYPE_STRING,
                'group_key' => 'general',
                'label_en' => 'Platform name (English)',
                'label_ar' => 'اسم المنصة (إنجليزي)',
                'help_en' => 'Shown in the header, emails, and browser tab title.',
                'help_ar' => 'يظهر في الترويسة والرسائل وعنوان نافذة المتصفح.',
                'value' => ['v' => 'MITHQAL POS Admin'],
                'display_order' => 10,
            ],
            [
                'key' => 'general.platform_name_ar',
                'type' => Setting::TYPE_STRING,
                'group_key' => 'general',
                'label_en' => 'Platform name (Arabic)',
                'label_ar' => 'اسم المنصة (عربي)',
                'value' => ['v' => 'مِثقال POS Admin'],
                'display_order' => 20,
            ],
            [
                'key' => 'general.support_email',
                'type' => Setting::TYPE_STRING,
                'group_key' => 'general',
                'label_en' => 'Support email',
                'label_ar' => 'البريد الإلكتروني للدعم',
                'help_en' => 'Where merchants are directed to for help. Shown in welcome emails.',
                'help_ar' => 'البريد الذي يتم توجيه التجار إليه للمساعدة. يظهر في رسائل الترحيب.',
                'value' => ['v' => 'support@mithqal.local'],
                'display_order' => 30,
            ],
            [
                'key' => 'general.support_phone',
                'type' => Setting::TYPE_STRING,
                'group_key' => 'general',
                'label_en' => 'Support phone',
                'label_ar' => 'هاتف الدعم',
                'value' => ['v' => '+968 9000 0000'],
                'display_order' => 40,
            ],
            [
                'key' => 'general.timezone',
                'type' => Setting::TYPE_SELECT,
                'group_key' => 'general',
                'label_en' => 'Default timezone',
                'label_ar' => 'المنطقة الزمنية الافتراضية',
                'help_en' => 'Used for displaying dates across the admin UI.',
                'help_ar' => 'يُستخدم لعرض التواريخ في واجهة الإدارة.',
                'options' => [
                    ['value' => 'Asia/Muscat', 'label_en' => 'Asia/Muscat (UTC+4)', 'label_ar' => 'آسيا/مسقط (UTC+4)'],
                    ['value' => 'Asia/Dubai', 'label_en' => 'Asia/Dubai (UTC+4)', 'label_ar' => 'آسيا/دبي (UTC+4)'],
                    ['value' => 'Asia/Riyadh', 'label_en' => 'Asia/Riyadh (UTC+3)', 'label_ar' => 'آسيا/الرياض (UTC+3)'],
                    ['value' => 'Asia/Kuwait', 'label_en' => 'Asia/Kuwait (UTC+3)', 'label_ar' => 'آسيا/الكويت (UTC+3)'],
                    ['value' => 'UTC', 'label_en' => 'UTC', 'label_ar' => 'التوقيت العالمي'],
                ],
                'value' => ['v' => 'Asia/Muscat'],
                'display_order' => 50,
            ],
            [
                'key' => 'general.default_currency',
                'type' => Setting::TYPE_SELECT,
                'group_key' => 'general',
                'label_en' => 'Default currency',
                'label_ar' => 'العملة الافتراضية',
                'help_en' => 'Applied to new merchants unless they override during onboarding.',
                'help_ar' => 'تُطبَّق على التجار الجدد ما لم يتم تجاوزها أثناء الانضمام.',
                'options' => [
                    ['value' => 'OMR', 'label_en' => 'Omani Rial (OMR)', 'label_ar' => 'ريال عُماني (OMR)'],
                    ['value' => 'AED', 'label_en' => 'UAE Dirham (AED)', 'label_ar' => 'درهم إماراتي (AED)'],
                    ['value' => 'SAR', 'label_en' => 'Saudi Riyal (SAR)', 'label_ar' => 'ريال سعودي (SAR)'],
                    ['value' => 'KWD', 'label_en' => 'Kuwaiti Dinar (KWD)', 'label_ar' => 'دينار كويتي (KWD)'],
                    ['value' => 'USD', 'label_en' => 'US Dollar (USD)', 'label_ar' => 'دولار أمريكي (USD)'],
                ],
                'value' => ['v' => 'OMR'],
                'display_order' => 60,
            ],

            // ============== LOCALIZATION =========================
            [
                'key' => 'localization.default_locale',
                'type' => Setting::TYPE_SELECT,
                'group_key' => 'localization',
                'label_en' => 'Default locale',
                'label_ar' => 'اللغة الافتراضية',
                'help_en' => 'New admin sessions start in this language.',
                'help_ar' => 'تبدأ جلسات الإدارة الجديدة بهذه اللغة.',
                'options' => [
                    ['value' => 'en', 'label_en' => 'English', 'label_ar' => 'الإنجليزية'],
                    ['value' => 'ar', 'label_en' => 'Arabic', 'label_ar' => 'العربية'],
                ],
                'value' => ['v' => 'en'],
                'display_order' => 10,
            ],
            [
                'key' => 'localization.supported_locales',
                'type' => Setting::TYPE_MULTISELECT,
                'group_key' => 'localization',
                'label_en' => 'Supported locales',
                'label_ar' => 'اللغات المدعومة',
                'help_en' => 'Languages shown in the language switcher.',
                'help_ar' => 'اللغات التي تظهر في مُبدّل اللغة.',
                'options' => [
                    ['value' => 'en', 'label_en' => 'English', 'label_ar' => 'الإنجليزية'],
                    ['value' => 'ar', 'label_en' => 'Arabic', 'label_ar' => 'العربية'],
                ],
                'value' => ['en', 'ar'],
                'display_order' => 20,
            ],
            [
                'key' => 'localization.week_starts_on',
                'type' => Setting::TYPE_SELECT,
                'group_key' => 'localization',
                'label_en' => 'Week starts on',
                'label_ar' => 'بداية الأسبوع',
                'help_en' => 'Affects calendar views + weekly report binning.',
                'help_ar' => 'يؤثر على عرض التقويم وتجميع التقارير الأسبوعية.',
                'options' => [
                    ['value' => 'sunday', 'label_en' => 'Sunday', 'label_ar' => 'الأحد'],
                    ['value' => 'saturday', 'label_en' => 'Saturday', 'label_ar' => 'السبت'],
                    ['value' => 'monday', 'label_en' => 'Monday', 'label_ar' => 'الإثنين'],
                ],
                'value' => ['v' => 'sunday'],
                'display_order' => 30,
            ],

            // ============== MERCHANT DEFAULTS ====================
            [
                'key' => 'merchant_defaults.geofence_radius_m',
                'type' => Setting::TYPE_INTEGER,
                'group_key' => 'merchant_defaults',
                'label_en' => 'Default geofence radius (metres)',
                'label_ar' => 'نصف قطر الحدود الجغرافية الافتراضي (بالأمتار)',
                'help_en' => 'Applied to new branches. Range 100–2000m per blueprint §4.3.2.',
                'help_ar' => 'يُطبَّق على الفروع الجديدة. النطاق 100–2000 متر.',
                'value' => ['v' => 500],
                'display_order' => 10,
            ],
            [
                'key' => 'merchant_defaults.document_expiry_warning_days',
                'type' => Setting::TYPE_INTEGER,
                'group_key' => 'merchant_defaults',
                'label_en' => 'Document expiry warning (days)',
                'label_ar' => 'تنبيه انتهاء صلاحية المستند (أيام)',
                'help_en' => 'How far in advance to flag CR / VAT / municipality license expiry.',
                'help_ar' => 'كم يومًا قبل انتهاء صلاحية السجل التجاري/الضريبة/الترخيص يتم التنبيه.',
                'value' => ['v' => 30],
                'display_order' => 20,
            ],
            [
                'key' => 'merchant_defaults.require_2fa_for_super_admin',
                'type' => Setting::TYPE_BOOLEAN,
                'group_key' => 'merchant_defaults',
                'label_en' => 'Require 2FA for merchant Super Admins',
                'label_ar' => 'فرض المصادقة الثنائية لمسؤولي التاجر',
                'help_en' => 'When enabled, every Super Admin must set up TOTP after first login.',
                'help_ar' => 'عند التفعيل، يجب على كل مسؤول إعداد المصادقة الثنائية بعد أول دخول.',
                'value' => ['v' => false],
                'display_order' => 30,
            ],

            // ============== NOTIFICATIONS ========================
            [
                'key' => 'notifications.alert_email_recipients',
                'type' => Setting::TYPE_EMAIL_LIST,
                'group_key' => 'notifications',
                'label_en' => 'Alert email recipients',
                'label_ar' => 'مستلمو رسائل التنبيه',
                'help_en' => 'Comma-separated email addresses for critical platform alerts (low-battery, offline devices, expired documents).',
                'help_ar' => 'عناوين بريد إلكتروني مفصولة بفواصل للتنبيهات الحرجة (بطارية منخفضة، أجهزة غير متصلة، مستندات منتهية).',
                'value' => [],
                'display_order' => 10,
            ],
            [
                'key' => 'notifications.low_battery_threshold_pct',
                'type' => Setting::TYPE_INTEGER,
                'group_key' => 'notifications',
                'label_en' => 'Low battery threshold (%)',
                'label_ar' => 'حد البطارية المنخفضة (٪)',
                'help_en' => 'Devices reporting below this fire an alert. 0 disables.',
                'help_ar' => 'الأجهزة التي تُبلِّغ بأقل من هذه القيمة تُطلق تنبيهًا. القيمة 0 تعطيل.',
                'value' => ['v' => 20],
                'display_order' => 20,
            ],
            [
                'key' => 'notifications.offline_threshold_hours',
                'type' => Setting::TYPE_INTEGER,
                'group_key' => 'notifications',
                'label_en' => 'Offline threshold (hours)',
                'label_ar' => 'حد الانقطاع (ساعات)',
                'help_en' => 'Devices with no heartbeat for this long are flagged offline.',
                'help_ar' => 'الأجهزة التي لا ترسل اتصالًا لهذه المدة تُعتبر غير متصلة.',
                'value' => ['v' => 6],
                'display_order' => 30,
            ],
            [
                'key' => 'notifications.reconciliation_stale_hours',
                'type' => Setting::TYPE_INTEGER,
                'group_key' => 'notifications',
                'label_en' => 'Reconciliation queue alert (hours)',
                'label_ar' => 'تنبيه قائمة التسوية (ساعات)',
                'help_en' => 'Bank-reconciliation entries older than this trigger an alert per blueprint §4.8.',
                'help_ar' => 'إدخالات تسوية البنك الأقدم من هذه المدة تُطلق تنبيهًا.',
                'value' => ['v' => 48],
                'display_order' => 40,
            ],

            // ============== MAINTENANCE BANNER ===================
            [
                'key' => 'maintenance.enabled',
                'type' => Setting::TYPE_BOOLEAN,
                'group_key' => 'maintenance',
                'label_en' => 'Show maintenance banner',
                'label_ar' => 'عرض شريط الصيانة',
                'help_en' => 'When on, the message below appears at the top of every admin page.',
                'help_ar' => 'عند التفعيل، تظهر الرسالة أدناه في أعلى كل صفحة إدارة.',
                'value' => ['v' => false],
                'display_order' => 10,
            ],
            [
                'key' => 'maintenance.message_en',
                'type' => Setting::TYPE_TEXTAREA,
                'group_key' => 'maintenance',
                'label_en' => 'Banner message (English)',
                'label_ar' => 'رسالة الشريط (إنجليزي)',
                'value' => ['v' => ''],
                'display_order' => 20,
            ],
            [
                'key' => 'maintenance.message_ar',
                'type' => Setting::TYPE_TEXTAREA,
                'group_key' => 'maintenance',
                'label_en' => 'Banner message (Arabic)',
                'label_ar' => 'رسالة الشريط (عربي)',
                'value' => ['v' => ''],
                'display_order' => 30,
            ],
            [
                'key' => 'maintenance.start_at',
                'type' => Setting::TYPE_DATETIME,
                'group_key' => 'maintenance',
                'label_en' => 'Banner shows from',
                'label_ar' => 'بداية عرض الشريط',
                'help_en' => 'ISO datetime. Leave blank to show immediately when enabled.',
                'help_ar' => 'تاريخ ووقت ISO. اترك الحقل فارغًا للعرض الفوري عند التفعيل.',
                'value' => ['v' => null],
                'display_order' => 40,
            ],
            [
                'key' => 'maintenance.end_at',
                'type' => Setting::TYPE_DATETIME,
                'group_key' => 'maintenance',
                'label_en' => 'Banner hides at',
                'label_ar' => 'نهاية عرض الشريط',
                'help_en' => 'ISO datetime. Leave blank for indefinite display.',
                'help_ar' => 'تاريخ ووقت ISO. اترك الحقل فارغًا للعرض غير المحدود.',
                'value' => ['v' => null],
                'display_order' => 50,
            ],
        ];
    }
}
