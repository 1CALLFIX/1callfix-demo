<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\ContentPage;
use App\Models\Faq;
use Illuminate\Http\Request;

/**
 * Public read API for CMS/content (mission Phase 12 — CMS/content audit).
 * `content_pages`, `faqs`, and `banners` were all fully admin-manageable
 * (Cms\Manage, Banners\Manage) but had ZERO consumer anywhere in the
 * codebase — write-only from the rest of the application's perspective,
 * confirmed by a full-codebase read-site grep before building this.
 * Unauthenticated by design: Terms/Privacy/FAQs/banners are pre-login
 * content on every real app this one is benchmarked against (Glover,
 * 6amMart) — the same reasoning that already makes /auth/otp/* public.
 */
class ContentController extends Controller
{
    /** GET /api/pages/{slug} — 404s for a draft (is_active=false) page exactly like a missing one, never leaking its existence. */
    public function page(string $slug)
    {
        $page = ContentPage::where('slug', $slug)->where('is_active', true)->first();

        if (! $page) {
            return response()->json(['message' => 'Page not found.'], 404);
        }

        return response()->json([
            'slug' => $page->slug,
            'title' => $page->title,
            'content' => $page->content,
            'updated_at' => $page->updated_at,
        ]);
    }

    /** GET /api/faqs — active only, optionally narrowed to one category. Same ordering the admin screen itself uses (sort_order then id). */
    public function faqs(Request $request)
    {
        $faqs = Faq::where('is_active', true)
            ->when($request->query('category'), fn ($q, $category) => $q->where('category', $category))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'category', 'question', 'answer']);

        return response()->json(['faqs' => $faqs]);
    }

    /**
     * GET /api/banners?placement=top&franchise_id=&zone_id=&module=&category_id=
     * Delegates entirely to Banner::scopeForSlot() — the model's own
     * docblock names "customer app API" as one of its intended callers, so
     * this reuses it rather than hand-rolling a second targeting query
     * (the exact duplication that scope's own docblock warns against).
     */
    public function banners(Request $request)
    {
        $validated = $request->validate([
            'placement' => ['required', 'in:'.implode(',', array_keys(Banner::PLACEMENTS))],
            'franchise_id' => ['nullable', 'integer'],
            'zone_id' => ['nullable', 'integer'],
            'module' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer'],
        ]);

        $context = array_filter([
            'franchise_id' => $validated['franchise_id'] ?? null,
            'zone_id' => $validated['zone_id'] ?? null,
            'module' => $validated['module'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
        ], fn ($v) => $v !== null);

        $banners = Banner::forSlot($validated['placement'], $context)
            ->get(['id', 'title', 'image', 'link', 'placement']);

        return response()->json([
            'banners' => $banners->map(fn (Banner $b) => [
                'id' => $b->id, 'title' => $b->title, 'image_url' => $b->image_url, 'link' => $b->link,
            ]),
        ]);
    }
}
