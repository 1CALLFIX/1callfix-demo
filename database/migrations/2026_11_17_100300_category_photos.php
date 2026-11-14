<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New HD photos for categories and subcategories (owner 2026-11-14, picked on the photo check page): 31 of 48 get an
 * Unsplash photo, 17 marked "None fit" keep theirs. Each row changes only while it still has the photo it had when
 * the owner picked (an admin's own change since then is kept); down() puts the old photo back the same way.
 * Columns hold either a storage path or a full URL; the models already show both.
 */
return new class extends Migration
{
    /** [table, id, old image, new image] */
    private const PHOTOS = [
        ['service_categories', 1, 'categories/1NAs42PMmXhmaDSY48YKY6lduK2dInKxqW0VyFMa.png', 'https://images.unsplash.com/photo-1792799888905-8696bd32a11a?w=1200&q=80&auto=format&fit=crop'], // Appliance | AC Repair
        ['service_categories', 2, 'categories/Ru74tMP1vF71Fetap2b0SCFZwX7Ta4Rx5L4g6Fly.png', 'https://images.unsplash.com/photo-1731168273756-e02cae42265b?w=1200&q=80&auto=format&fit=crop'], // Home Repairs
        ['service_categories', 3, 'categories/K0ztpkMsEQNqOIT65GxLHB4QJBLwdxjYqYMEFP0Q.png', 'https://images.unsplash.com/photo-1721333089073-215a56fd710c?w=1200&q=80&auto=format&fit=crop'], // Laptop | CCTV | IT Services
        ['service_categories', 4, 'categories/3sBJPoLgr0If0yb9sypoqT0mSexsK4YlHnWRjgr1.png', 'https://images.unsplash.com/photo-1615461066159-fea0960485d5?w=1200&q=80&auto=format&fit=crop'], // Blood Test
        ['service_categories', 5, 'categories/0S6jJNaN6h0UyLC4g3gqoiC3VTBtS3QBTgMlEH3h.png', 'https://images.unsplash.com/photo-1580618672591-eb180b1a973f?w=1200&q=80&auto=format&fit=crop'], // Beauty Care
        ['service_categories', 6, 'categories/4344n3YVXlps8B8mxm6SQqOsRCiszmqAu8hpclII.png', 'https://images.unsplash.com/photo-1587271636175-90d58cdad458?w=1200&q=80&auto=format&fit=crop'], // Events
        ['service_subcategories', 1, 'subcategories/q0g2uOPLaYG1RSH3zpCZ98ukm8foqZE1aGuoPfyk.png', 'https://images.unsplash.com/photo-1762341123870-d706f257a12e?w=1200&q=80&auto=format&fit=crop'], // Split AC
        ['service_subcategories', 4, 'subcategories/po9hLmtPGIrFLS950HNjHphbzXd8QhOallAMpmnX.png', 'https://images.unsplash.com/photo-1626806787461-102c1bfaaea1?w=1200&q=80&auto=format&fit=crop'], // Washing Machine
        ['service_subcategories', 5, 'subcategories/xXNihfZnc78w9uE9NK0lB8CkvGoiZIX1s8S4XJBt.png', 'https://images.unsplash.com/photo-1484154218962-a197022b5858?w=1200&q=80&auto=format&fit=crop'], // Refrigerator / Fridge
        ['service_subcategories', 6, 'subcategories/bvYFBveQw4KmH725AuDZT4UkNA87TSwsfdMedpFJ.png', 'https://images.unsplash.com/photo-1593784991251-92ded75ea290?w=1200&q=80&auto=format&fit=crop'], // TV Repair
        ['service_subcategories', 8, 'subcategories/bVGp6cmrYceul3IjoPqagXcwgsJQw11NrTeX8gxs.png', 'https://images.unsplash.com/photo-1676337167629-d896b3ed5724?w=1200&q=80&auto=format&fit=crop'], // Inverter Repair
        ['service_subcategories', 9, 'subcategories/1S6ddInyYwXF2ULeKJn0oR4YnT7zMdLeHS2VMW7c.png', 'https://images.unsplash.com/photo-1570222094114-d054a817e56b?w=1200&q=80&auto=format&fit=crop'], // Grinder / Mixie Repair
        ['service_subcategories', 14, 'subcategories/wOEcpmozwYn93YJosq3TLeNSLBBXys7NfqPkzKD1.png', 'https://images.unsplash.com/photo-1758101755915-462eddc23f57?w=1200&q=80&auto=format&fit=crop'], // Electrician
        ['service_subcategories', 15, 'subcategories/itfBX4a1tlfkE0DbBNcwSWkCM88GnTrEOlRywJgL.png', 'https://images.unsplash.com/photo-1779031242515-205111711b23?w=1200&q=80&auto=format&fit=crop'], // Carpenter
        ['service_subcategories', 16, 'subcategories/KHl8dYd5G5IL12Yu2FParswckCPzjOPpIdmLPX6S.png', 'https://images.unsplash.com/photo-1773425350177-af4d2249d641?w=1200&q=80&auto=format&fit=crop'], // Lock Smith
        ['service_subcategories', 21, 'subcategories/w1AxmjCH4UsLC0XgABK0nZPeQAQCAPTm7trTX8JP.png', 'https://images.unsplash.com/photo-1591238372338-22d30c883a86?w=1200&q=80&auto=format&fit=crop'], // Desktop Repair
        ['service_subcategories', 22, 'subcategories/yQaagvH9ngjNCsh4JAWVi6qAMvC49pzh5iTbI7bX.png', 'https://images.unsplash.com/photo-1721333089073-215a56fd710c?w=1200&q=80&auto=format&fit=crop'], // Laptop Repair
        ['service_subcategories', 23, 'subcategories/U1ZLYueLDansCWfjfxx0VFnJxtBpXhvp3lkhoPzY.png', 'https://images.unsplash.com/photo-1612815154858-60aa4c59eaa6?w=1200&q=80&auto=format&fit=crop'], // Printer Service
        ['service_subcategories', 24, 'subcategories/dIeuFRxCaZunRcNo4L5ALXTHmeZPhJ1x3y7LZHB1.png', 'https://images.unsplash.com/photo-1589935447067-5531094415d1?w=1200&q=80&auto=format&fit=crop'], // CCTV Service
        ['service_subcategories', 25, 'subcategories/pRC9IkHCQuozUsP8kTjfofql45wZeGmkP1VQVxFn.png', 'https://images.unsplash.com/photo-1698668975271-2ba9a323be6b?w=1200&q=80&auto=format&fit=crop'], // Networking
        ['service_subcategories', 26, 'subcategories/Wwfx1oeAa91pTpXL5daiMNeKjqTZ89qwtqdUIqIz.png', 'https://images.unsplash.com/photo-1606904825846-647eb07f5be2?w=1200&q=80&auto=format&fit=crop'], // Wi-Fi Setup
        ['service_subcategories', 27, 'subcategories/yU5aeRMkcPHUxo2YOF7Z9ONloCSbnUo9z7SUTUk4.png', 'https://images.unsplash.com/photo-1626863905121-3b0c0ed7b94c?w=1200&q=80&auto=format&fit=crop'], // OnCall Support
        ['service_subcategories', 30, 'subcategories/qkj7gSOT97QAD1YaMxPwjrLiYct1VE2UfO0mauzr.png', 'https://images.unsplash.com/photo-1579154341184-22069e4614d2?w=1200&q=80&auto=format&fit=crop'], // Popular Tests
        ['service_subcategories', 35, 'subcategories/93nXvmNfsVU5mLnbwHrn3xXNb660PasI0X1cm0I3.png', 'https://images.unsplash.com/photo-1525135850648-b42365991054?w=1200&q=80&auto=format&fit=crop'], // Mehandi Design
        ['service_subcategories', 36, 'subcategories/93nXvmNfsVU5mLnbwHrn3xXNb660PasI0X1cm0I3.png', 'https://images.unsplash.com/photo-1505932794465-147d1f1b2c97?w=1200&q=80&auto=format&fit=crop'], // Bridal Mehandi
        ['service_subcategories', 37, 'subcategories/9Cv9qhncYWL1OVVQHvEcrcD8zewE877iy1clGABX.png', 'https://images.unsplash.com/photo-1555244162-803834f70033?w=1200&q=80&auto=format&fit=crop'], // Catering
        ['service_subcategories', 38, 'subcategories/ty7rEkNXDjy1wZqBvvPIASiPJg6VMCpqKoPS3E9t.png', 'https://images.unsplash.com/photo-1625398407796-82650a8c135f?w=1200&q=80&auto=format&fit=crop'], // Cooking
        ['service_subcategories', 39, 'subcategories/003SreAfpA8f1Vw2upSMrNqO75aLThGHItgLWTz1.png', 'https://images.unsplash.com/photo-1544785349-c4a5301826fd?w=1200&q=80&auto=format&fit=crop'], // DJ | Lighting
        ['service_subcategories', 40, 'subcategories/pbYUn3Ib1SVgYq4T6XMk29Qwa1UFx3BoAx5cp3O7.png', 'https://images.unsplash.com/photo-1511795409834-ef04bbd61622?w=1200&q=80&auto=format&fit=crop'], // Event Planner
        ['service_subcategories', 41, 'subcategories/5JsZLYfyxGzgo17epbRcvwiBGCqhnUVzJoNWyO8b.png', 'https://images.unsplash.com/photo-1587271407850-8d438ca9fdf2?w=1200&q=80&auto=format&fit=crop'], // Wedding Planner
        ['service_subcategories', 42, 'subcategories/R36dXuzD3u4LQS5EdboLK4eJthSh5WK1lvyR0mCf.png', 'https://images.unsplash.com/photo-1630526720753-aa4e71acf67d?w=1200&q=80&auto=format&fit=crop'], // Photographer
    ];

    public function up(): void
    {
        foreach (self::PHOTOS as [$table, $id, $old, $new]) {
            DB::table($table)->where('id', $id)->where('image', $old)->update(['image' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::PHOTOS as [$table, $id, $old, $new]) {
            DB::table($table)->where('id', $id)->where('image', $new)->update(['image' => $old]);
        }
    }
};
