<?php

namespace App\Services\Seo;

use App\Models\City;
use App\Models\CityPageContent;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Support\Seo\QaRows;

/**
 * F3: title / meta description / intro / indexability for a city-scoped public page.
 *
 * Copy: a franchise-edited city_page_contents row wins over the defaults the page passes in.
 * Indexing: a page is indexable only when it is real - the city has at least `seo.min_providers_to_index`
 * approved providers (for a category or service: with that category's skill) - and it is not a QA/demo row.
 * Everything else is noindex (and stays out of the sitemap), but still renders for visitors.
 */
class CityPageSeo
{
    public function __construct(private ProviderCoverage $coverage)
    {
    }

    /**
     * @return array{title: string, metaDescription: ?string, intro: ?string, indexable: bool}
     */
    public function for(City $city, string $subjectType, int $subjectId, string $defaultTitle, ?string $defaultMeta): array
    {
        $content = CityPageContent::query()
            ->where('city_id', $city->id)->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->first();

        return [
            'title' => $this->filled($content?->title) ?? $defaultTitle,
            'metaDescription' => $this->filled($content?->meta_description) ?? $defaultMeta,
            'intro' => $this->filled($content?->intro),
            'indexable' => $this->indexable($city, $subjectType, $subjectId),
        ];
    }

    public function indexable(City $city, string $subjectType, int $subjectId): bool
    {
        if (QaRows::isQaName($city->name)) {
            return false;
        }

        if (! app(CityContext::class)->isLive($city)) {
            return false;
        }

        return match ($subjectType) {
            CityPageContent::SUBJECT_CITY => $this->coverage->cityIsReal($city),
            CityPageContent::SUBJECT_CATEGORY => $this->categoryOk($city, ServiceCategory::find($subjectId)),
            CityPageContent::SUBJECT_SERVICE => $this->serviceOk($city, Service::with('category')->find($subjectId)),
            default => false,
        };
    }

    private function categoryOk(City $city, ?ServiceCategory $category): bool
    {
        return $category !== null
            && ! QaRows::isQaName($category->name)
            && $this->coverage->categoryIsReal($city, $category->id);
    }

    private function serviceOk(City $city, ?Service $service): bool
    {
        return $service !== null
            && ! QaRows::isQaName($service->name)
            && $this->categoryOk($city, $service->category);
    }

    private function filled(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
