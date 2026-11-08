<div>
    <x-customer.get-app page />
    <div class="mx-auto flex max-w-7xl justify-center px-4 pb-12 sm:px-6 lg:px-8">
        <x-customer.share-button :title="\App\Support\SiteText::get('get_app.page_title', ['brand' => \App\Services\Seo\SeoSettings::siteName()])" />
    </div>
</div>
