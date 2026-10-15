{{-- The homepage `top` banner slot. Included above or below the category
     opener per Admin → Homepage & sign-up → "Category icons first". --}}
@if ($heroBanners->isNotEmpty())
    <section class="mx-auto max-w-7xl px-4 pt-3 sm:px-6 sm:pt-5 lg:px-8">
        <x-customer.banner-carousel :banners="$heroBanners"
                                    id="hero-banners"
                                    label="Featured promotions"
                                    variant="hero"
                                    :interval="config('banners.hero_rotation_ms')" />
    </section>
@endif
