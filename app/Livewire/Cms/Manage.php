<?php

namespace App\Livewire\Cms;

use App\Models\ContentPage;
use App\Models\Faq;
use App\Models\PartnerBenefit;
use Illuminate\Support\Str;
use Livewire\Component;

// Website / CMS — the settings doc's category #18. content_pages and faqs
// both had schema and zero consumers anywhere in the codebase when this
// screen was originally built, so this is a genuinely missing admin
// screen, not a settings tab: pages/FAQs are records to manage, not
// toggles to flip, so it follows the same {Module}\Manage one-screen
// pattern as Categories/Zones rather than living inside Settings\Manage.
// Two sections in one component (Pages, FAQs) since both tables are small
// and simple enough not to need their own separate screens.
//
// Phase 12 (CMS/content audit) added a real consumer for the first time —
// GET /api/pages/{slug} and GET /api/faqs (App\Http\Controllers\API\
// ContentController) — and content_pages gained an is_active column
// (matching faqs, which already had one) so a page can be drafted here
// without immediately being publicly reachable.
class Manage extends Component
{
    /**
     * No view-level check existed at all — only write actions checked
     * cms.manage (Phase 11 audit finding, same bug class addendum #1
     * already fixed on 15 other screens). No separate cms.view permission
     * was ever seeded, so this reuses cms.manage.
     */
    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionAnywhere('cms.manage'), 403, 'You do not have permission to view CMS content.');
    }

    public string $section = 'pages'; // pages|faqs|benefits

    // --- Pages: add ---
    public string $pageSlug = '';
    public string $pageTitle = '';
    public string $pageContent = '';
    public bool $pageIsActive = true;

    // --- Pages: edit ---
    public bool $showEditPageModal = false;
    public ?int $editPageId = null;
    public string $editPageSlug = '';
    public string $editPageTitle = '';
    public string $editPageContent = '';
    public bool $editPageIsActive = true;

    // --- FAQs: add ---
    public string $faqCategory = '';
    public string $faqQuestion = '';
    public string $faqAnswer = '';

    // --- FAQs: edit ---
    public bool $showEditFaqModal = false;
    public ?int $editFaqId = null;
    public string $editFaqCategory = '';
    public string $editFaqQuestion = '';
    public string $editFaqAnswer = '';
    public bool $editFaqIsActive = true;

    // --- Partner benefits: add ---
    public string $benefitIcon = PartnerBenefit::DEFAULT_ICON;
    public string $benefitTitle = '';
    public string $benefitDescription = '';

    // --- Partner benefits: edit ---
    public bool $showEditBenefitModal = false;
    public ?int $editBenefitId = null;
    public string $editBenefitIcon = '';
    public string $editBenefitTitle = '';
    public string $editBenefitDescription = '';
    public bool $editBenefitIsActive = true;

    // --- Delete confirmation (shared) ---
    public ?int $confirmingDeletePageId = null;
    public ?int $confirmingDeleteFaqId = null;
    public ?int $confirmingDeleteBenefitId = null;

    public string $flashMessage = '';

    // ============================== Pages ==============================

    public function savePage(): void
    {
        $this->validate([
            'pageSlug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:content_pages,slug'],
            'pageTitle' => ['required', 'string', 'max:255'],
            'pageContent' => ['nullable', 'string'],
        ], [], ['pageSlug' => 'slug', 'pageTitle' => 'title']);

        // content_pages/faqs carry no franchise column — platform-wide
        // content, same global-only scope as geography.manage.
        if (! auth()->user()->hasPermission('cms.manage')) {
            $this->addError('permission', 'You do not have permission to manage CMS content.');
            return;
        }

        ContentPage::create([
            'slug' => $this->pageSlug,
            'title' => $this->pageTitle,
            'content' => $this->pageContent ?: null,
            'is_active' => $this->pageIsActive,
        ]);

        $this->reset(['pageSlug', 'pageTitle', 'pageContent']);
        $this->pageIsActive = true;
        $this->flashMessage = 'Page created.';
    }

    public function editPage(int $pageId): void
    {
        $page = ContentPage::findOrFail($pageId);

        $this->editPageId = $page->id;
        $this->editPageSlug = $page->slug;
        $this->editPageTitle = $page->title;
        $this->editPageContent = $page->content ?? '';
        $this->editPageIsActive = $page->is_active;

        $this->resetValidation();
        $this->showEditPageModal = true;
    }

    public function updatePage(): void
    {
        $this->validate([
            'editPageSlug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:content_pages,slug,'.$this->editPageId],
            'editPageTitle' => ['required', 'string', 'max:255'],
            'editPageContent' => ['nullable', 'string'],
        ], [], ['editPageSlug' => 'slug', 'editPageTitle' => 'title']);

        if (! auth()->user()->hasPermission('cms.manage')) {
            $this->addError('permission', 'You do not have permission to manage CMS content.');
            return;
        }

        ContentPage::findOrFail($this->editPageId)->update([
            'slug' => $this->editPageSlug,
            'title' => $this->editPageTitle,
            'content' => $this->editPageContent ?: null,
            'is_active' => $this->editPageIsActive,
        ]);

        $this->showEditPageModal = false;
        $this->flashMessage = 'Page updated.';
    }

    public function closeEditPageModal(): void
    {
        $this->showEditPageModal = false;
        $this->resetValidation();
    }

    /** Same pattern as toggleFaqActive() — quick publish/unpublish from the list, no modal round-trip needed. */
    public function togglePageActive(int $pageId): void
    {
        if (! auth()->user()->hasPermission('cms.manage')) {
            $this->addError('permission', 'You do not have permission to manage CMS content.');
            return;
        }

        $page = ContentPage::findOrFail($pageId);
        $page->update(['is_active' => ! $page->is_active]);
    }

    public function confirmDeletePage(int $pageId): void { $this->confirmingDeletePageId = $pageId; }
    public function cancelDeletePage(): void { $this->confirmingDeletePageId = null; }

    public function deletePage(): void
    {
        if (! $this->confirmingDeletePageId) {
            return;
        }

        if (! auth()->user()->hasPermission('cms.manage')) {
            $this->addError('permission', 'You do not have permission to manage CMS content.');
            $this->confirmingDeletePageId = null;
            return;
        }

        ContentPage::findOrFail($this->confirmingDeletePageId)->delete();
        $this->confirmingDeletePageId = null;
        $this->flashMessage = 'Page deleted.';
    }

    // ============================== FAQs ==============================

    public function saveFaq(): void
    {
        $this->validate([
            'faqCategory' => ['nullable', 'string', 'max:255'],
            'faqQuestion' => ['required', 'string', 'max:255'],
            'faqAnswer' => ['required', 'string'],
        ], [], ['faqQuestion' => 'question', 'faqAnswer' => 'answer']);

        if (! auth()->user()->hasPermission('cms.manage')) {
            $this->addError('permission', 'You do not have permission to manage CMS content.');
            return;
        }

        Faq::create([
            'category' => $this->faqCategory ?: null,
            'question' => $this->faqQuestion,
            'answer' => $this->faqAnswer,
            'sort_order' => (int) Faq::max('sort_order') + 1,
            'is_active' => true,
        ]);

        $this->reset(['faqCategory', 'faqQuestion', 'faqAnswer']);
        $this->flashMessage = 'FAQ created.';
    }

    public function editFaq(int $faqId): void
    {
        $faq = Faq::findOrFail($faqId);

        $this->editFaqId = $faq->id;
        $this->editFaqCategory = $faq->category ?? '';
        $this->editFaqQuestion = $faq->question;
        $this->editFaqAnswer = $faq->answer;
        $this->editFaqIsActive = $faq->is_active;

        $this->resetValidation();
        $this->showEditFaqModal = true;
    }

    public function updateFaq(): void
    {
        $this->validate([
            'editFaqCategory' => ['nullable', 'string', 'max:255'],
            'editFaqQuestion' => ['required', 'string', 'max:255'],
            'editFaqAnswer' => ['required', 'string'],
        ], [], ['editFaqQuestion' => 'question', 'editFaqAnswer' => 'answer']);

        if (! auth()->user()->hasPermission('cms.manage')) {
            $this->addError('permission', 'You do not have permission to manage CMS content.');
            return;
        }

        Faq::findOrFail($this->editFaqId)->update([
            'category' => $this->editFaqCategory ?: null,
            'question' => $this->editFaqQuestion,
            'answer' => $this->editFaqAnswer,
            'is_active' => $this->editFaqIsActive,
        ]);

        $this->showEditFaqModal = false;
        $this->flashMessage = 'FAQ updated.';
    }

    public function closeEditFaqModal(): void
    {
        $this->showEditFaqModal = false;
        $this->resetValidation();
    }

    public function toggleFaqActive(int $faqId): void
    {
        if (! auth()->user()->hasPermission('cms.manage')) {
            $this->addError('permission', 'You do not have permission to manage CMS content.');
            return;
        }

        $faq = Faq::findOrFail($faqId);
        $faq->update(['is_active' => ! $faq->is_active]);
    }

    public function confirmDeleteFaq(int $faqId): void { $this->confirmingDeleteFaqId = $faqId; }
    public function cancelDeleteFaq(): void { $this->confirmingDeleteFaqId = null; }

    public function deleteFaq(): void
    {
        if (! $this->confirmingDeleteFaqId) {
            return;
        }

        if (! auth()->user()->hasPermission('cms.manage')) {
            $this->addError('permission', 'You do not have permission to manage CMS content.');
            $this->confirmingDeleteFaqId = null;
            return;
        }

        Faq::findOrFail($this->confirmingDeleteFaqId)->delete();
        $this->confirmingDeleteFaqId = null;
        $this->flashMessage = 'FAQ deleted.';
    }

    // ========================= Partner benefits =========================
    // The provider-facing "why partner with us" list rendered on the public
    // /coming-soon/partners landing page. Same add/edit/toggle/delete shape
    // as the FAQ section above; the only extras are a fixed icon <select>
    // (PartnerBenefit::ICONS) and move-up/down reordering.

    private function benefitIconRule(): string
    {
        return 'in:'.implode(',', array_keys(PartnerBenefit::ICONS));
    }

    public function saveBenefit(): void
    {
        $this->validate([
            'benefitIcon' => ['required', 'string', $this->benefitIconRule()],
            'benefitTitle' => ['required', 'string', 'max:255'],
            'benefitDescription' => ['required', 'string', 'max:500'],
        ], [], [
            'benefitIcon' => 'icon',
            'benefitTitle' => 'title',
            'benefitDescription' => 'description',
        ]);

        if (! auth()->user()->hasPermission('cms.manage')) {
            $this->addError('permission', 'You do not have permission to manage CMS content.');
            return;
        }

        PartnerBenefit::create([
            'icon' => $this->benefitIcon,
            'title' => $this->benefitTitle,
            'description' => $this->benefitDescription,
            'sort_order' => (int) PartnerBenefit::max('sort_order') + 1,
            'is_active' => true,
        ]);

        $this->reset(['benefitTitle', 'benefitDescription']);
        $this->benefitIcon = PartnerBenefit::DEFAULT_ICON;
        $this->flashMessage = 'Partner benefit created.';
    }

    public function editBenefit(int $benefitId): void
    {
        $benefit = PartnerBenefit::findOrFail($benefitId);

        $this->editBenefitId = $benefit->id;
        $this->editBenefitIcon = $benefit->icon;
        $this->editBenefitTitle = $benefit->title;
        $this->editBenefitDescription = $benefit->description;
        $this->editBenefitIsActive = $benefit->is_active;

        $this->resetValidation();
        $this->showEditBenefitModal = true;
    }

    public function updateBenefit(): void
    {
        $this->validate([
            'editBenefitIcon' => ['required', 'string', $this->benefitIconRule()],
            'editBenefitTitle' => ['required', 'string', 'max:255'],
            'editBenefitDescription' => ['required', 'string', 'max:500'],
        ], [], [
            'editBenefitIcon' => 'icon',
            'editBenefitTitle' => 'title',
            'editBenefitDescription' => 'description',
        ]);

        if (! auth()->user()->hasPermission('cms.manage')) {
            $this->addError('permission', 'You do not have permission to manage CMS content.');
            return;
        }

        PartnerBenefit::findOrFail($this->editBenefitId)->update([
            'icon' => $this->editBenefitIcon,
            'title' => $this->editBenefitTitle,
            'description' => $this->editBenefitDescription,
            'is_active' => $this->editBenefitIsActive,
        ]);

        $this->showEditBenefitModal = false;
        $this->flashMessage = 'Partner benefit updated.';
    }

    public function closeEditBenefitModal(): void
    {
        $this->showEditBenefitModal = false;
        $this->resetValidation();
    }

    public function toggleBenefitActive(int $benefitId): void
    {
        if (! auth()->user()->hasPermission('cms.manage')) {
            $this->addError('permission', 'You do not have permission to manage CMS content.');
            return;
        }

        $benefit = PartnerBenefit::findOrFail($benefitId);
        $benefit->update(['is_active' => ! $benefit->is_active]);
    }

    /** Swap sort_order with the adjacent row so the list can be reordered without a drag library. */
    public function moveBenefit(int $benefitId, string $direction): void
    {
        if (! auth()->user()->hasPermission('cms.manage')) {
            $this->addError('permission', 'You do not have permission to manage CMS content.');
            return;
        }

        $benefit = PartnerBenefit::findOrFail($benefitId);

        $neighbour = PartnerBenefit::query()
            ->when($direction === 'up',
                fn ($q) => $q->where('sort_order', '<', $benefit->sort_order)->orderByDesc('sort_order'),
                fn ($q) => $q->where('sort_order', '>', $benefit->sort_order)->orderBy('sort_order'),
            )
            ->first();

        if ($neighbour === null) {
            return; // already at the end in that direction
        }

        $benefitOrder = $benefit->sort_order;
        $benefit->update(['sort_order' => $neighbour->sort_order]);
        $neighbour->update(['sort_order' => $benefitOrder]);
    }

    public function confirmDeleteBenefit(int $benefitId): void { $this->confirmingDeleteBenefitId = $benefitId; }
    public function cancelDeleteBenefit(): void { $this->confirmingDeleteBenefitId = null; }

    public function deleteBenefit(): void
    {
        if (! $this->confirmingDeleteBenefitId) {
            return;
        }

        if (! auth()->user()->hasPermission('cms.manage')) {
            $this->addError('permission', 'You do not have permission to manage CMS content.');
            $this->confirmingDeleteBenefitId = null;
            return;
        }

        PartnerBenefit::findOrFail($this->confirmingDeleteBenefitId)->delete();
        $this->confirmingDeleteBenefitId = null;
        $this->flashMessage = 'Partner benefit deleted.';
    }

    public function render()
    {
        return view('livewire.cms.manage', [
            'pages' => ContentPage::orderBy('title')->get(),
            'faqs' => Faq::orderBy('sort_order')->orderBy('id')->get(),
            'partnerBenefits' => PartnerBenefit::orderBy('sort_order')->orderBy('id')->get(),
            'benefitIcons' => PartnerBenefit::ICONS,
        ])->layout('layouts.admin', ['title' => 'Website / CMS']);
    }
}
