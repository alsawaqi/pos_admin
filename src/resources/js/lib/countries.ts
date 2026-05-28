/**
 * ISO 3166-1 alpha-2 country catalogue with bilingual display names.
 *
 * Used by the Merchant Create wizard's owner cards (nationality
 * select), the Merchant Show page's owner list (resolve code →
 * display name), and any future form that needs a country picker.
 *
 * Source: UN M49 list + commonly recognised territories. Hard-coded
 * here instead of pulled from `i18n-iso-countries` to avoid a 3 MB
 * dependency for what is a static, slow-moving reference list.
 *
 * Each entry:
 *   - `code`     : uppercase ISO-2, the value persisted on the
 *                  database (e.g. `owner.nationality = 'OM'`).
 *   - `name_en`  : English short name from the UN list.
 *   - `name_ar`  : Arabic name from the UN list — falls back to the
 *                  English name in the rare case a translation is
 *                  missing.
 */

export interface Country {
    code: string;
    name_en: string;
    name_ar: string;
}

/**
 * Full list of countries (250 entries). Order here doesn't matter —
 * the picker sorts alphabetically by display name based on the
 * active locale (see {@link sortedCountries}).
 */
export const COUNTRIES: readonly Country[] = [
    { code: 'AF', name_en: 'Afghanistan', name_ar: 'أفغانستان' },
    { code: 'AX', name_en: 'Åland Islands', name_ar: 'جزر آلاند' },
    { code: 'AL', name_en: 'Albania', name_ar: 'ألبانيا' },
    { code: 'DZ', name_en: 'Algeria', name_ar: 'الجزائر' },
    { code: 'AS', name_en: 'American Samoa', name_ar: 'ساموا الأمريكية' },
    { code: 'AD', name_en: 'Andorra', name_ar: 'أندورا' },
    { code: 'AO', name_en: 'Angola', name_ar: 'أنغولا' },
    { code: 'AI', name_en: 'Anguilla', name_ar: 'أنغويلا' },
    { code: 'AQ', name_en: 'Antarctica', name_ar: 'القارة القطبية الجنوبية' },
    { code: 'AG', name_en: 'Antigua and Barbuda', name_ar: 'أنتيغوا وباربودا' },
    { code: 'AR', name_en: 'Argentina', name_ar: 'الأرجنتين' },
    { code: 'AM', name_en: 'Armenia', name_ar: 'أرمينيا' },
    { code: 'AW', name_en: 'Aruba', name_ar: 'أروبا' },
    { code: 'AU', name_en: 'Australia', name_ar: 'أستراليا' },
    { code: 'AT', name_en: 'Austria', name_ar: 'النمسا' },
    { code: 'AZ', name_en: 'Azerbaijan', name_ar: 'أذربيجان' },
    { code: 'BS', name_en: 'Bahamas', name_ar: 'الباهاما' },
    { code: 'BH', name_en: 'Bahrain', name_ar: 'البحرين' },
    { code: 'BD', name_en: 'Bangladesh', name_ar: 'بنغلاديش' },
    { code: 'BB', name_en: 'Barbados', name_ar: 'باربادوس' },
    { code: 'BY', name_en: 'Belarus', name_ar: 'بيلاروسيا' },
    { code: 'BE', name_en: 'Belgium', name_ar: 'بلجيكا' },
    { code: 'BZ', name_en: 'Belize', name_ar: 'بليز' },
    { code: 'BJ', name_en: 'Benin', name_ar: 'بنين' },
    { code: 'BM', name_en: 'Bermuda', name_ar: 'برمودا' },
    { code: 'BT', name_en: 'Bhutan', name_ar: 'بوتان' },
    { code: 'BO', name_en: 'Bolivia', name_ar: 'بوليفيا' },
    { code: 'BQ', name_en: 'Bonaire, Sint Eustatius and Saba', name_ar: 'بونير وسينت أوستاتيوس وسابا' },
    { code: 'BA', name_en: 'Bosnia and Herzegovina', name_ar: 'البوسنة والهرسك' },
    { code: 'BW', name_en: 'Botswana', name_ar: 'بوتسوانا' },
    { code: 'BV', name_en: 'Bouvet Island', name_ar: 'جزيرة بوفيه' },
    { code: 'BR', name_en: 'Brazil', name_ar: 'البرازيل' },
    { code: 'IO', name_en: 'British Indian Ocean Territory', name_ar: 'إقليم المحيط الهندي البريطاني' },
    { code: 'BN', name_en: 'Brunei Darussalam', name_ar: 'بروناي' },
    { code: 'BG', name_en: 'Bulgaria', name_ar: 'بلغاريا' },
    { code: 'BF', name_en: 'Burkina Faso', name_ar: 'بوركينا فاسو' },
    { code: 'BI', name_en: 'Burundi', name_ar: 'بوروندي' },
    { code: 'CV', name_en: 'Cabo Verde', name_ar: 'الرأس الأخضر' },
    { code: 'KH', name_en: 'Cambodia', name_ar: 'كمبوديا' },
    { code: 'CM', name_en: 'Cameroon', name_ar: 'الكاميرون' },
    { code: 'CA', name_en: 'Canada', name_ar: 'كندا' },
    { code: 'KY', name_en: 'Cayman Islands', name_ar: 'جزر كايمان' },
    { code: 'CF', name_en: 'Central African Republic', name_ar: 'جمهورية أفريقيا الوسطى' },
    { code: 'TD', name_en: 'Chad', name_ar: 'تشاد' },
    { code: 'CL', name_en: 'Chile', name_ar: 'تشيلي' },
    { code: 'CN', name_en: 'China', name_ar: 'الصين' },
    { code: 'CX', name_en: 'Christmas Island', name_ar: 'جزيرة عيد الميلاد' },
    { code: 'CC', name_en: 'Cocos (Keeling) Islands', name_ar: 'جزر كوكوس' },
    { code: 'CO', name_en: 'Colombia', name_ar: 'كولومبيا' },
    { code: 'KM', name_en: 'Comoros', name_ar: 'جزر القمر' },
    { code: 'CG', name_en: 'Congo', name_ar: 'الكونغو' },
    { code: 'CD', name_en: 'Congo (Democratic Republic of the)', name_ar: 'جمهورية الكونغو الديمقراطية' },
    { code: 'CK', name_en: 'Cook Islands', name_ar: 'جزر كوك' },
    { code: 'CR', name_en: 'Costa Rica', name_ar: 'كوستاريكا' },
    { code: 'CI', name_en: "Côte d'Ivoire", name_ar: 'ساحل العاج' },
    { code: 'HR', name_en: 'Croatia', name_ar: 'كرواتيا' },
    { code: 'CU', name_en: 'Cuba', name_ar: 'كوبا' },
    { code: 'CW', name_en: 'Curaçao', name_ar: 'كوراساو' },
    { code: 'CY', name_en: 'Cyprus', name_ar: 'قبرص' },
    { code: 'CZ', name_en: 'Czechia', name_ar: 'التشيك' },
    { code: 'DK', name_en: 'Denmark', name_ar: 'الدنمارك' },
    { code: 'DJ', name_en: 'Djibouti', name_ar: 'جيبوتي' },
    { code: 'DM', name_en: 'Dominica', name_ar: 'دومينيكا' },
    { code: 'DO', name_en: 'Dominican Republic', name_ar: 'جمهورية الدومينيكان' },
    { code: 'EC', name_en: 'Ecuador', name_ar: 'الإكوادور' },
    { code: 'EG', name_en: 'Egypt', name_ar: 'مصر' },
    { code: 'SV', name_en: 'El Salvador', name_ar: 'السلفادور' },
    { code: 'GQ', name_en: 'Equatorial Guinea', name_ar: 'غينيا الاستوائية' },
    { code: 'ER', name_en: 'Eritrea', name_ar: 'إريتريا' },
    { code: 'EE', name_en: 'Estonia', name_ar: 'إستونيا' },
    { code: 'SZ', name_en: 'Eswatini', name_ar: 'إسواتيني' },
    { code: 'ET', name_en: 'Ethiopia', name_ar: 'إثيوبيا' },
    { code: 'FK', name_en: 'Falkland Islands', name_ar: 'جزر فوكلاند' },
    { code: 'FO', name_en: 'Faroe Islands', name_ar: 'جزر فارو' },
    { code: 'FJ', name_en: 'Fiji', name_ar: 'فيجي' },
    { code: 'FI', name_en: 'Finland', name_ar: 'فنلندا' },
    { code: 'FR', name_en: 'France', name_ar: 'فرنسا' },
    { code: 'GF', name_en: 'French Guiana', name_ar: 'غويانا الفرنسية' },
    { code: 'PF', name_en: 'French Polynesia', name_ar: 'بولينزيا الفرنسية' },
    { code: 'TF', name_en: 'French Southern Territories', name_ar: 'أراضي فرنسا الجنوبية والقطبية الجنوبية' },
    { code: 'GA', name_en: 'Gabon', name_ar: 'الغابون' },
    { code: 'GM', name_en: 'Gambia', name_ar: 'غامبيا' },
    { code: 'GE', name_en: 'Georgia', name_ar: 'جورجيا' },
    { code: 'DE', name_en: 'Germany', name_ar: 'ألمانيا' },
    { code: 'GH', name_en: 'Ghana', name_ar: 'غانا' },
    { code: 'GI', name_en: 'Gibraltar', name_ar: 'جبل طارق' },
    { code: 'GR', name_en: 'Greece', name_ar: 'اليونان' },
    { code: 'GL', name_en: 'Greenland', name_ar: 'غرينلاند' },
    { code: 'GD', name_en: 'Grenada', name_ar: 'غرينادا' },
    { code: 'GP', name_en: 'Guadeloupe', name_ar: 'غوادلوب' },
    { code: 'GU', name_en: 'Guam', name_ar: 'غوام' },
    { code: 'GT', name_en: 'Guatemala', name_ar: 'غواتيمالا' },
    { code: 'GG', name_en: 'Guernsey', name_ar: 'غيرنزي' },
    { code: 'GN', name_en: 'Guinea', name_ar: 'غينيا' },
    { code: 'GW', name_en: 'Guinea-Bissau', name_ar: 'غينيا بيساو' },
    { code: 'GY', name_en: 'Guyana', name_ar: 'غيانا' },
    { code: 'HT', name_en: 'Haiti', name_ar: 'هايتي' },
    { code: 'HM', name_en: 'Heard Island and McDonald Islands', name_ar: 'جزيرة هيرد وجزر ماكدونالد' },
    { code: 'VA', name_en: 'Holy See', name_ar: 'الكرسي الرسولي' },
    { code: 'HN', name_en: 'Honduras', name_ar: 'هندوراس' },
    { code: 'HK', name_en: 'Hong Kong', name_ar: 'هونغ كونغ' },
    { code: 'HU', name_en: 'Hungary', name_ar: 'المجر' },
    { code: 'IS', name_en: 'Iceland', name_ar: 'آيسلندا' },
    { code: 'IN', name_en: 'India', name_ar: 'الهند' },
    { code: 'ID', name_en: 'Indonesia', name_ar: 'إندونيسيا' },
    { code: 'IR', name_en: 'Iran', name_ar: 'إيران' },
    { code: 'IQ', name_en: 'Iraq', name_ar: 'العراق' },
    { code: 'IE', name_en: 'Ireland', name_ar: 'أيرلندا' },
    { code: 'IM', name_en: 'Isle of Man', name_ar: 'جزيرة مان' },
    { code: 'IL', name_en: 'Israel', name_ar: 'إسرائيل' },
    { code: 'IT', name_en: 'Italy', name_ar: 'إيطاليا' },
    { code: 'JM', name_en: 'Jamaica', name_ar: 'جامايكا' },
    { code: 'JP', name_en: 'Japan', name_ar: 'اليابان' },
    { code: 'JE', name_en: 'Jersey', name_ar: 'جيرزي' },
    { code: 'JO', name_en: 'Jordan', name_ar: 'الأردن' },
    { code: 'KZ', name_en: 'Kazakhstan', name_ar: 'كازاخستان' },
    { code: 'KE', name_en: 'Kenya', name_ar: 'كينيا' },
    { code: 'KI', name_en: 'Kiribati', name_ar: 'كيريباتي' },
    { code: 'KP', name_en: "Korea (Democratic People's Republic of)", name_ar: 'كوريا الشمالية' },
    { code: 'KR', name_en: 'Korea (Republic of)', name_ar: 'كوريا الجنوبية' },
    { code: 'KW', name_en: 'Kuwait', name_ar: 'الكويت' },
    { code: 'KG', name_en: 'Kyrgyzstan', name_ar: 'قيرغيزستان' },
    { code: 'LA', name_en: "Lao People's Democratic Republic", name_ar: 'لاوس' },
    { code: 'LV', name_en: 'Latvia', name_ar: 'لاتفيا' },
    { code: 'LB', name_en: 'Lebanon', name_ar: 'لبنان' },
    { code: 'LS', name_en: 'Lesotho', name_ar: 'ليسوتو' },
    { code: 'LR', name_en: 'Liberia', name_ar: 'ليبيريا' },
    { code: 'LY', name_en: 'Libya', name_ar: 'ليبيا' },
    { code: 'LI', name_en: 'Liechtenstein', name_ar: 'ليختنشتاين' },
    { code: 'LT', name_en: 'Lithuania', name_ar: 'ليتوانيا' },
    { code: 'LU', name_en: 'Luxembourg', name_ar: 'لوكسمبورغ' },
    { code: 'MO', name_en: 'Macao', name_ar: 'ماكاو' },
    { code: 'MG', name_en: 'Madagascar', name_ar: 'مدغشقر' },
    { code: 'MW', name_en: 'Malawi', name_ar: 'مالاوي' },
    { code: 'MY', name_en: 'Malaysia', name_ar: 'ماليزيا' },
    { code: 'MV', name_en: 'Maldives', name_ar: 'جزر المالديف' },
    { code: 'ML', name_en: 'Mali', name_ar: 'مالي' },
    { code: 'MT', name_en: 'Malta', name_ar: 'مالطا' },
    { code: 'MH', name_en: 'Marshall Islands', name_ar: 'جزر مارشال' },
    { code: 'MQ', name_en: 'Martinique', name_ar: 'مارتينيك' },
    { code: 'MR', name_en: 'Mauritania', name_ar: 'موريتانيا' },
    { code: 'MU', name_en: 'Mauritius', name_ar: 'موريشيوس' },
    { code: 'YT', name_en: 'Mayotte', name_ar: 'مايوت' },
    { code: 'MX', name_en: 'Mexico', name_ar: 'المكسيك' },
    { code: 'FM', name_en: 'Micronesia (Federated States of)', name_ar: 'ولايات ميكرونيسيا المتحدة' },
    { code: 'MD', name_en: 'Moldova', name_ar: 'مولدوفا' },
    { code: 'MC', name_en: 'Monaco', name_ar: 'موناكو' },
    { code: 'MN', name_en: 'Mongolia', name_ar: 'منغوليا' },
    { code: 'ME', name_en: 'Montenegro', name_ar: 'الجبل الأسود' },
    { code: 'MS', name_en: 'Montserrat', name_ar: 'مونتسرات' },
    { code: 'MA', name_en: 'Morocco', name_ar: 'المغرب' },
    { code: 'MZ', name_en: 'Mozambique', name_ar: 'موزمبيق' },
    { code: 'MM', name_en: 'Myanmar', name_ar: 'ميانمار' },
    { code: 'NA', name_en: 'Namibia', name_ar: 'ناميبيا' },
    { code: 'NR', name_en: 'Nauru', name_ar: 'ناورو' },
    { code: 'NP', name_en: 'Nepal', name_ar: 'نيبال' },
    { code: 'NL', name_en: 'Netherlands', name_ar: 'هولندا' },
    { code: 'NC', name_en: 'New Caledonia', name_ar: 'كاليدونيا الجديدة' },
    { code: 'NZ', name_en: 'New Zealand', name_ar: 'نيوزيلندا' },
    { code: 'NI', name_en: 'Nicaragua', name_ar: 'نيكاراغوا' },
    { code: 'NE', name_en: 'Niger', name_ar: 'النيجر' },
    { code: 'NG', name_en: 'Nigeria', name_ar: 'نيجيريا' },
    { code: 'NU', name_en: 'Niue', name_ar: 'نييوي' },
    { code: 'NF', name_en: 'Norfolk Island', name_ar: 'جزيرة نورفولك' },
    { code: 'MK', name_en: 'North Macedonia', name_ar: 'مقدونيا الشمالية' },
    { code: 'MP', name_en: 'Northern Mariana Islands', name_ar: 'جزر ماريانا الشمالية' },
    { code: 'NO', name_en: 'Norway', name_ar: 'النرويج' },
    { code: 'OM', name_en: 'Oman', name_ar: 'عُمان' },
    { code: 'PK', name_en: 'Pakistan', name_ar: 'باكستان' },
    { code: 'PW', name_en: 'Palau', name_ar: 'بالاو' },
    { code: 'PS', name_en: 'Palestine, State of', name_ar: 'فلسطين' },
    { code: 'PA', name_en: 'Panama', name_ar: 'بنما' },
    { code: 'PG', name_en: 'Papua New Guinea', name_ar: 'بابوا غينيا الجديدة' },
    { code: 'PY', name_en: 'Paraguay', name_ar: 'باراغواي' },
    { code: 'PE', name_en: 'Peru', name_ar: 'بيرو' },
    { code: 'PH', name_en: 'Philippines', name_ar: 'الفلبين' },
    { code: 'PN', name_en: 'Pitcairn', name_ar: 'بيتكيرن' },
    { code: 'PL', name_en: 'Poland', name_ar: 'بولندا' },
    { code: 'PT', name_en: 'Portugal', name_ar: 'البرتغال' },
    { code: 'PR', name_en: 'Puerto Rico', name_ar: 'بورتوريكو' },
    { code: 'QA', name_en: 'Qatar', name_ar: 'قطر' },
    { code: 'RE', name_en: 'Réunion', name_ar: 'لا ريونيون' },
    { code: 'RO', name_en: 'Romania', name_ar: 'رومانيا' },
    { code: 'RU', name_en: 'Russian Federation', name_ar: 'روسيا' },
    { code: 'RW', name_en: 'Rwanda', name_ar: 'رواندا' },
    { code: 'BL', name_en: 'Saint Barthélemy', name_ar: 'سان بارتيلمي' },
    { code: 'SH', name_en: 'Saint Helena, Ascension and Tristan da Cunha', name_ar: 'سانت هيلينا وأسينشين وتريستان دا كونا' },
    { code: 'KN', name_en: 'Saint Kitts and Nevis', name_ar: 'سانت كيتس ونيفيس' },
    { code: 'LC', name_en: 'Saint Lucia', name_ar: 'سانت لوسيا' },
    { code: 'MF', name_en: 'Saint Martin (French part)', name_ar: 'سانت مارتن (الجزء الفرنسي)' },
    { code: 'PM', name_en: 'Saint Pierre and Miquelon', name_ar: 'سان بيير وميكلون' },
    { code: 'VC', name_en: 'Saint Vincent and the Grenadines', name_ar: 'سانت فينسنت والغرينادين' },
    { code: 'WS', name_en: 'Samoa', name_ar: 'ساموا' },
    { code: 'SM', name_en: 'San Marino', name_ar: 'سان مارينو' },
    { code: 'ST', name_en: 'Sao Tome and Principe', name_ar: 'ساو تومي وبرينسيب' },
    { code: 'SA', name_en: 'Saudi Arabia', name_ar: 'المملكة العربية السعودية' },
    { code: 'SN', name_en: 'Senegal', name_ar: 'السنغال' },
    { code: 'RS', name_en: 'Serbia', name_ar: 'صربيا' },
    { code: 'SC', name_en: 'Seychelles', name_ar: 'سيشل' },
    { code: 'SL', name_en: 'Sierra Leone', name_ar: 'سيراليون' },
    { code: 'SG', name_en: 'Singapore', name_ar: 'سنغافورة' },
    { code: 'SX', name_en: 'Sint Maarten (Dutch part)', name_ar: 'سينت مارتن (الجزء الهولندي)' },
    { code: 'SK', name_en: 'Slovakia', name_ar: 'سلوفاكيا' },
    { code: 'SI', name_en: 'Slovenia', name_ar: 'سلوفينيا' },
    { code: 'SB', name_en: 'Solomon Islands', name_ar: 'جزر سليمان' },
    { code: 'SO', name_en: 'Somalia', name_ar: 'الصومال' },
    { code: 'ZA', name_en: 'South Africa', name_ar: 'جنوب أفريقيا' },
    { code: 'GS', name_en: 'South Georgia and the South Sandwich Islands', name_ar: 'جورجيا الجنوبية وجزر ساندويتش الجنوبية' },
    { code: 'SS', name_en: 'South Sudan', name_ar: 'جنوب السودان' },
    { code: 'ES', name_en: 'Spain', name_ar: 'إسبانيا' },
    { code: 'LK', name_en: 'Sri Lanka', name_ar: 'سريلانكا' },
    { code: 'SD', name_en: 'Sudan', name_ar: 'السودان' },
    { code: 'SR', name_en: 'Suriname', name_ar: 'سورينام' },
    { code: 'SJ', name_en: 'Svalbard and Jan Mayen', name_ar: 'سفالبارد ويان ماين' },
    { code: 'SE', name_en: 'Sweden', name_ar: 'السويد' },
    { code: 'CH', name_en: 'Switzerland', name_ar: 'سويسرا' },
    { code: 'SY', name_en: 'Syrian Arab Republic', name_ar: 'سوريا' },
    { code: 'TW', name_en: 'Taiwan', name_ar: 'تايوان' },
    { code: 'TJ', name_en: 'Tajikistan', name_ar: 'طاجيكستان' },
    { code: 'TZ', name_en: 'Tanzania, United Republic of', name_ar: 'تنزانيا' },
    { code: 'TH', name_en: 'Thailand', name_ar: 'تايلاند' },
    { code: 'TL', name_en: 'Timor-Leste', name_ar: 'تيمور الشرقية' },
    { code: 'TG', name_en: 'Togo', name_ar: 'توغو' },
    { code: 'TK', name_en: 'Tokelau', name_ar: 'توكيلاو' },
    { code: 'TO', name_en: 'Tonga', name_ar: 'تونغا' },
    { code: 'TT', name_en: 'Trinidad and Tobago', name_ar: 'ترينيداد وتوباغو' },
    { code: 'TN', name_en: 'Tunisia', name_ar: 'تونس' },
    { code: 'TR', name_en: 'Türkiye', name_ar: 'تركيا' },
    { code: 'TM', name_en: 'Turkmenistan', name_ar: 'تركمانستان' },
    { code: 'TC', name_en: 'Turks and Caicos Islands', name_ar: 'جزر توركس وكايكوس' },
    { code: 'TV', name_en: 'Tuvalu', name_ar: 'توفالو' },
    { code: 'UG', name_en: 'Uganda', name_ar: 'أوغندا' },
    { code: 'UA', name_en: 'Ukraine', name_ar: 'أوكرانيا' },
    { code: 'AE', name_en: 'United Arab Emirates', name_ar: 'الإمارات العربية المتحدة' },
    { code: 'GB', name_en: 'United Kingdom', name_ar: 'المملكة المتحدة' },
    { code: 'US', name_en: 'United States of America', name_ar: 'الولايات المتحدة الأمريكية' },
    { code: 'UM', name_en: 'United States Minor Outlying Islands', name_ar: 'جزر الولايات المتحدة الصغيرة النائية' },
    { code: 'UY', name_en: 'Uruguay', name_ar: 'الأوروغواي' },
    { code: 'UZ', name_en: 'Uzbekistan', name_ar: 'أوزبكستان' },
    { code: 'VU', name_en: 'Vanuatu', name_ar: 'فانواتو' },
    { code: 'VE', name_en: 'Venezuela', name_ar: 'فنزويلا' },
    { code: 'VN', name_en: 'Viet Nam', name_ar: 'فيتنام' },
    { code: 'VG', name_en: 'Virgin Islands (British)', name_ar: 'جزر العذراء البريطانية' },
    { code: 'VI', name_en: 'Virgin Islands (U.S.)', name_ar: 'جزر العذراء الأمريكية' },
    { code: 'WF', name_en: 'Wallis and Futuna', name_ar: 'والس وفوتونا' },
    { code: 'EH', name_en: 'Western Sahara', name_ar: 'الصحراء الغربية' },
    { code: 'YE', name_en: 'Yemen', name_ar: 'اليمن' },
    { code: 'ZM', name_en: 'Zambia', name_ar: 'زامبيا' },
    { code: 'ZW', name_en: 'Zimbabwe', name_ar: 'زيمبابوي' },
];

/**
 * Map from ISO-2 code → Country entry. Built lazily once on first
 * lookup and cached so subsequent calls are O(1). Useful for the
 * Show page where we have a code and need the display name.
 */
let codeIndex: Map<string, Country> | null = null;

function buildIndex(): Map<string, Country> {
    if (codeIndex === null) {
        codeIndex = new Map();
        for (const country of COUNTRIES) {
            codeIndex.set(country.code, country);
        }
    }

    return codeIndex;
}

/**
 * Look up a country by its ISO-2 code. Returns undefined for an
 * unknown code (treat that as "show the raw code" in UI).
 */
export function findCountry(code: string | null | undefined): Country | undefined {
    if (! code) {
        return undefined;
    }

    return buildIndex().get(code.toUpperCase());
}

/**
 * Resolve a country code → display name in the chosen locale.
 * Falls back to the raw code when the country isn't found (so the
 * UI degrades gracefully if a CR record holds an obscure or legacy
 * code we haven't catalogued).
 */
export function countryNameForLocale(code: string | null | undefined, locale: string): string {
    if (! code) {
        return '—';
    }
    const country = findCountry(code);
    if (! country) {
        return code;
    }

    return locale === 'ar' ? country.name_ar : country.name_en;
}

/**
 * Returns the catalogue sorted alphabetically by the display name
 * for the active locale. Used by the wizard's `<select>` so the
 * options appear in the order the user expects in their language.
 *
 * Sorting uses Intl.Collator so accented characters and Arabic
 * letters collate the way native readers expect (e.g. "Åland"
 * sorts with A, not at the end of the Latin block).
 */
export function sortedCountries(locale: string): readonly Country[] {
    const collator = new Intl.Collator(locale, { sensitivity: 'base' });
    const key: keyof Country = locale === 'ar' ? 'name_ar' : 'name_en';

    return [...COUNTRIES].sort((a, b) => collator.compare(a[key], b[key]));
}
