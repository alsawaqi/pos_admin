<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BusinessActivityCategory;
use App\Models\BusinessActivity;
use Illuminate\Database\Seeder;

class BusinessActivitySeeder extends Seeder
{
    public function run(): void
    {
        $activities = [
            // Food & Beverage
            ['code' => 'FNB-RESTAURANT', 'name_en' => 'Restaurant', 'name_ar' => 'مطعم', 'category' => BusinessActivityCategory::FoodAndBeverage, 'isic_code' => '5610', 'order' => 10],
            ['code' => 'FNB-CAFE', 'name_en' => 'Café / Coffee shop', 'name_ar' => 'مقهى', 'category' => BusinessActivityCategory::FoodAndBeverage, 'isic_code' => '5630', 'order' => 11],
            ['code' => 'FNB-BAKERY', 'name_en' => 'Bakery', 'name_ar' => 'مخبز', 'category' => BusinessActivityCategory::FoodAndBeverage, 'isic_code' => '1071', 'order' => 12],
            ['code' => 'FNB-DESSERT', 'name_en' => 'Desserts and ice cream', 'name_ar' => 'حلويات ومثلجات', 'category' => BusinessActivityCategory::FoodAndBeverage, 'isic_code' => '1073', 'order' => 13],
            ['code' => 'FNB-FOOD-TRUCK', 'name_en' => 'Food truck', 'name_ar' => 'عربة طعام', 'category' => BusinessActivityCategory::FoodAndBeverage, 'isic_code' => '5610', 'order' => 14],
            ['code' => 'FNB-CATERING', 'name_en' => 'Catering services', 'name_ar' => 'خدمات تموين', 'category' => BusinessActivityCategory::FoodAndBeverage, 'isic_code' => '5621', 'order' => 15],
            ['code' => 'FNB-DARK-KITCHEN', 'name_en' => 'Cloud / dark kitchen', 'name_ar' => 'مطبخ سحابي', 'category' => BusinessActivityCategory::FoodAndBeverage, 'isic_code' => '5610', 'order' => 16],

            // Retail
            ['code' => 'RTL-GROCERY', 'name_en' => 'Grocery store', 'name_ar' => 'بقالة', 'category' => BusinessActivityCategory::Retail, 'isic_code' => '4711', 'order' => 20],
            ['code' => 'RTL-SUPERMARKET', 'name_en' => 'Supermarket / hypermarket', 'name_ar' => 'سوبر ماركت', 'category' => BusinessActivityCategory::Retail, 'isic_code' => '4711', 'order' => 21],
            ['code' => 'RTL-CONVENIENCE', 'name_en' => 'Convenience store', 'name_ar' => 'متجر صغير', 'category' => BusinessActivityCategory::Retail, 'isic_code' => '4711', 'order' => 22],
            ['code' => 'RTL-CLOTHING', 'name_en' => 'Clothing and apparel', 'name_ar' => 'ملابس', 'category' => BusinessActivityCategory::Retail, 'isic_code' => '4771', 'order' => 23],
            ['code' => 'RTL-ELECTRONICS', 'name_en' => 'Electronics retail', 'name_ar' => 'إلكترونيات', 'category' => BusinessActivityCategory::Retail, 'isic_code' => '4742', 'order' => 24],
            ['code' => 'RTL-PHARMACY', 'name_en' => 'Pharmacy', 'name_ar' => 'صيدلية', 'category' => BusinessActivityCategory::Retail, 'isic_code' => '4772', 'order' => 25],
            ['code' => 'RTL-BOOKSTORE', 'name_en' => 'Bookstore and stationery', 'name_ar' => 'مكتبة وقرطاسية', 'category' => BusinessActivityCategory::Retail, 'isic_code' => '4761', 'order' => 26],

            // Services
            ['code' => 'SRV-SALON', 'name_en' => 'Hair and beauty salon', 'name_ar' => 'صالون تجميل', 'category' => BusinessActivityCategory::Services, 'isic_code' => '9602', 'order' => 30],
            ['code' => 'SRV-LAUNDRY', 'name_en' => 'Laundry and dry cleaning', 'name_ar' => 'مغسلة', 'category' => BusinessActivityCategory::Services, 'isic_code' => '9601', 'order' => 31],
            ['code' => 'SRV-CARWASH', 'name_en' => 'Car wash and detailing', 'name_ar' => 'غسيل سيارات', 'category' => BusinessActivityCategory::Services, 'isic_code' => '4520', 'order' => 32],
            ['code' => 'SRV-REPAIR', 'name_en' => 'Repair and maintenance', 'name_ar' => 'إصلاح وصيانة', 'category' => BusinessActivityCategory::Services, 'isic_code' => '9521', 'order' => 33],

            // Hospitality
            ['code' => 'HOS-HOTEL', 'name_en' => 'Hotel', 'name_ar' => 'فندق', 'category' => BusinessActivityCategory::Hospitality, 'isic_code' => '5510', 'order' => 40],
            ['code' => 'HOS-GUESTHOUSE', 'name_en' => 'Guesthouse / lodge', 'name_ar' => 'بيت ضيافة', 'category' => BusinessActivityCategory::Hospitality, 'isic_code' => '5510', 'order' => 41],

            // Healthcare
            ['code' => 'HLT-CLINIC', 'name_en' => 'Medical clinic', 'name_ar' => 'عيادة طبية', 'category' => BusinessActivityCategory::Healthcare, 'isic_code' => '8620', 'order' => 50],
            ['code' => 'HLT-DENTAL', 'name_en' => 'Dental clinic', 'name_ar' => 'عيادة أسنان', 'category' => BusinessActivityCategory::Healthcare, 'isic_code' => '8623', 'order' => 51],

            // Education
            ['code' => 'EDU-TRAINING', 'name_en' => 'Training center', 'name_ar' => 'مركز تدريب', 'category' => BusinessActivityCategory::Education, 'isic_code' => '8550', 'order' => 60],

            // Other
            ['code' => 'OTH-OTHER', 'name_en' => 'Other', 'name_ar' => 'أخرى', 'category' => BusinessActivityCategory::Other, 'isic_code' => null, 'order' => 99],
        ];

        foreach ($activities as $activity) {
            BusinessActivity::query()->updateOrCreate(
                ['code' => $activity['code']],
                [
                    'name_en' => $activity['name_en'],
                    'name_ar' => $activity['name_ar'],
                    'category' => $activity['category'],
                    'isic_code' => $activity['isic_code'],
                    'is_active' => true,
                    'display_order' => $activity['order'],
                ],
            );
        }
    }
}
