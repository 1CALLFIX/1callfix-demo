<?php

namespace App\Livewire\Cms;

use App\Models\ContentPage;
use App\Models\Faq;
use Illuminate\Support\Str;
use Livewire\Component;

// Website / CMS — the settings doc's category #18. content_pages and faqs
// both had schema and zero consumers anywhere in the codebase (confirmed
// by audit), so this is a genuinely missing admin screen, not a settings
// tab: pages/FAQs are records to manage, not toggles to flip, so it
// follows the same {Module}\Manage one-screen pattern as Categories/Zones
// rather than living inside Settings\Manage. Two sections in one
// component (Pages, FAQs) since both tables are small and simple enough
// not to need their own separate screens.
class Manage extends Component
{
    public string $section = 'pages'; // pages|faqs

    // --- Pages: add ---
    public string $pageSlug = '';
    public string $pageTitle = '';
    public string $pageContent = '';

    // --- Pages: edit ---
    public bool $showEditPageModal = false;
    public ?int $editPageId = null;
    public string $editPageSlug = '';
    public string $editPageTitle = '';
    public string $editPageContent = '';

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

    // --- Delete confirmation (shared) ---
    public ?int $confirmingDeletePageId = null;
    public ?int $confirmingDeleteFaqId = null;

    public string $flashMessage = '';

    // ============================== Pages ==============================

    public function savePage(): void
    {
        $this->validate([
            'pageSlug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:content_pages,slug'],
            'pageTitle' => ['required', 'string', 'max:255'],
            'pageContent' => ['nullable', 'string'],
        ], [], ['pageSlug' => 'slug', 'pageTitle' => 'title']);

        ContentPage::create([
            'slug' => $this->pageSlug,
            'title' => $this->pageTitle,
            'content' => $this->pageContent ?: null,
        ]);

        $this->reset(['pageSlug', 'pageTitle', 'pageContent']);
        $this->flashMessage = 'Page created.';
    }

    public function editPage(int $pageId): void
    {
        $page = ContentPage::findOrFail($pageId);

        $this->editPageId = $page->id;
        $this->editPageSlug = $page->slug;
        $this->editPageTitle = $page->title;
        $this->editPageContent = $page->content ?? '';

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

        ContentPage::findOrFail($this->editPageId)->update([
            'slug' => $this->editPageSlug,
            'title' => $this->editPageTitle,
            'content' => $this->editPageContent ?: null,
        ]);

        $this->showEditPageModal = false;
        $this->flashMessage = 'Page updated.';
    }

    public function closeEditPageModal(): void
    {
        $this->showEditPageModal = false;
        $this->resetValidation();
    }

    public function confirmDeletePage(int $pageId): void { $this->confirmingDeletePageId = $pageId; }
    public function cancelDeletePage(): void { $this->confirmingDeletePageId = null; }

    public function deletePage(): void
    {
        if (! $this->confirmingDeletePageId) {
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

        Faq::findOrFail($this->confirmingDeleteFaqId)->delete();
        $this->confirmingDeleteFaqId = null;
        $this->flashMessage = 'FAQ deleted.';
    }

    public function render()
    {
        return view('livewire.cms.manage', [
            'pages' => ContentPage::orderBy('title')->get(),
            'faqs' => Faq::orderBy('sort_order')->orderBy('id')->get(),
        ])->layout('layouts.admin', ['title' => 'Website / CMS']);
    }
}
