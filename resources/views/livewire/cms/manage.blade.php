<div>
    <h1 class="text-2xl font-bold mb-4">Website / CMS</h1>

    @if ($flashMessage)
        <div class="bg-green-50 text-green-700 rounded p-3 mb-4 text-sm">{{ $flashMessage }}</div>
    @endif

    @error('permission') <div class="bg-red-50 text-red-700 rounded p-3 mb-4 text-sm">{{ $message }}</div> @enderror

    <div class="flex items-center gap-1 border-b mb-6">
        <button type="button" wire:click="$set('section', 'pages')"
                @class(['px-4 py-2 text-sm border-b-2 -mb-px', 'border-slate-900 text-slate-900 font-medium' => $section === 'pages', 'border-transparent text-gray-500 hover:text-gray-800' => $section !== 'pages'])>
            Pages ({{ $pages->count() }})
        </button>
        <button type="button" wire:click="$set('section', 'faqs')"
                @class(['px-4 py-2 text-sm border-b-2 -mb-px', 'border-slate-900 text-slate-900 font-medium' => $section === 'faqs', 'border-transparent text-gray-500 hover:text-gray-800' => $section !== 'faqs'])>
            FAQs ({{ $faqs->count() }})
        </button>
    </div>

    @if ($section === 'pages')
        {{-- Add New Page --}}
        <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
            <h2 class="text-sm font-semibold mb-3">Add New Page</h2>
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-48">
                    <label class="block text-xs font-medium mb-1">Slug <span class="text-red-500">*</span></label>
                    <input type="text" wire:model="pageSlug" placeholder="privacy-policy" class="w-full border rounded px-3 py-2 text-sm">
                    @error('pageSlug') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="flex-1 min-w-48">
                    <label class="block text-xs font-medium mb-1">Title <span class="text-red-500">*</span></label>
                    <input type="text" wire:model="pageTitle" placeholder="Privacy Policy" class="w-full border rounded px-3 py-2 text-sm">
                    @error('pageTitle') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="mt-3">
                <label class="block text-xs font-medium mb-1">Content</label>
                <textarea wire:model="pageContent" rows="4" class="w-full border rounded px-3 py-2 text-sm"></textarea>
            </div>
            <label class="inline-flex items-center gap-2 text-sm mt-3">
                <input type="checkbox" wire:model="pageIsActive" class="rounded"> Published (reachable via <code>GET /api/pages/{slug}</code> as soon as saved)
            </label>
            <div class="flex justify-end mt-3">
                <button type="button" wire:click="savePage" class="bg-slate-900 text-white px-6 h-[38px] rounded text-sm font-medium hover:bg-slate-800">+ Add Page</button>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <th class="px-4 py-2">Slug</th>
                        <th class="px-4 py-2">Title</th>
                        <th class="px-4 py-2">Status</th>
                        <th class="px-4 py-2">Updated</th>
                        <th class="px-4 py-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($pages as $page)
                        <tr class="border-t hover:bg-gray-50" wire:key="page-{{ $page->id }}">
                            <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ $page->slug }}</td>
                            <td class="px-4 py-2 font-medium">{{ $page->title }}</td>
                            <td class="px-4 py-2">
                                <button type="button" wire:click="togglePageActive({{ $page->id }})"
                                        @class(['px-2 py-1 rounded text-xs font-medium', 'bg-green-100 text-green-700' => $page->is_active, 'bg-gray-100 text-gray-600' => ! $page->is_active])>
                                    {{ $page->is_active ? 'Published' : 'Draft' }}
                                </button>
                            </td>
                            <td class="px-4 py-2 text-gray-500">{{ $page->updated_at->diffForHumans() }}</td>
                            <td class="px-4 py-2 text-right">
                                <button type="button" wire:click="editPage({{ $page->id }})" class="text-xs text-blue-600 hover:underline mr-3">Edit</button>
                                <button type="button" wire:click="confirmDeletePage({{ $page->id }})" class="text-xs text-red-600 hover:underline">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No pages yet. Add your first one above.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($section === 'faqs')
        {{-- Add New FAQ --}}
        <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
            <h2 class="text-sm font-semibold mb-3">Add New FAQ</h2>
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-48">
                    <label class="block text-xs font-medium mb-1">Category</label>
                    <input type="text" wire:model="faqCategory" placeholder="Booking" class="w-full border rounded px-3 py-2 text-sm">
                </div>
                <div class="flex-1 min-w-48">
                    <label class="block text-xs font-medium mb-1">Question <span class="text-red-500">*</span></label>
                    <input type="text" wire:model="faqQuestion" class="w-full border rounded px-3 py-2 text-sm">
                    @error('faqQuestion') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="mt-3">
                <label class="block text-xs font-medium mb-1">Answer <span class="text-red-500">*</span></label>
                <textarea wire:model="faqAnswer" rows="3" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                @error('faqAnswer') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end mt-3">
                <button type="button" wire:click="saveFaq" class="bg-slate-900 text-white px-6 h-[38px] rounded text-sm font-medium hover:bg-slate-800">+ Add FAQ</button>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <th class="px-4 py-2">Category</th>
                        <th class="px-4 py-2">Question</th>
                        <th class="px-4 py-2">Status</th>
                        <th class="px-4 py-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($faqs as $faq)
                        <tr class="border-t hover:bg-gray-50" wire:key="faq-{{ $faq->id }}">
                            <td class="px-4 py-2 text-gray-500">{{ $faq->category ?? '—' }}</td>
                            <td class="px-4 py-2 font-medium">{{ $faq->question }}</td>
                            <td class="px-4 py-2">
                                <button type="button" wire:click="toggleFaqActive({{ $faq->id }})"
                                        @class(['px-2 py-1 rounded text-xs font-medium', 'bg-green-100 text-green-700' => $faq->is_active, 'bg-gray-100 text-gray-600' => ! $faq->is_active])>
                                    {{ $faq->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <button type="button" wire:click="editFaq({{ $faq->id }})" class="text-xs text-blue-600 hover:underline mr-3">Edit</button>
                                <button type="button" wire:click="confirmDeleteFaq({{ $faq->id }})" class="text-xs text-red-600 hover:underline">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No FAQs yet. Add your first one above.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- Edit Page modal --}}
    @if ($showEditPageModal)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-40 p-4 overflow-y-auto" wire:click.self="closeEditPageModal">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-6 my-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold">Edit Page</h3>
                    <button type="button" wire:click="closeEditPageModal" class="text-gray-400 hover:text-gray-600">✕</button>
                </div>
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium mb-1">Slug</label>
                        <input type="text" wire:model="editPageSlug" class="w-full border rounded px-3 py-2 text-sm">
                        @error('editPageSlug') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Title</label>
                        <input type="text" wire:model="editPageTitle" class="w-full border rounded px-3 py-2 text-sm">
                        @error('editPageTitle') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Content</label>
                        <textarea wire:model="editPageContent" rows="6" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="editPageIsActive" class="rounded"> Published
                    </label>
                </div>
                <div class="flex justify-end gap-2 pt-6">
                    <button type="button" wire:click="closeEditPageModal" class="px-4 py-2 border border-gray-300 rounded text-sm hover:bg-gray-50">Close</button>
                    <button type="button" wire:click="updatePage" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Update</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Edit FAQ modal --}}
    @if ($showEditFaqModal)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-40 p-4 overflow-y-auto" wire:click.self="closeEditFaqModal">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-6 my-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold">Edit FAQ</h3>
                    <button type="button" wire:click="closeEditFaqModal" class="text-gray-400 hover:text-gray-600">✕</button>
                </div>
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium mb-1">Category</label>
                        <input type="text" wire:model="editFaqCategory" class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Question</label>
                        <input type="text" wire:model="editFaqQuestion" class="w-full border rounded px-3 py-2 text-sm">
                        @error('editFaqQuestion') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Answer</label>
                        <textarea wire:model="editFaqAnswer" rows="4" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                        @error('editFaqAnswer') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="editFaqIsActive" class="rounded"> Active
                    </label>
                </div>
                <div class="flex justify-end gap-2 pt-6">
                    <button type="button" wire:click="closeEditFaqModal" class="px-4 py-2 border border-gray-300 rounded text-sm hover:bg-gray-50">Close</button>
                    <button type="button" wire:click="updateFaq" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Update</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Delete Page confirmation --}}
    @if ($confirmingDeletePageId)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-40 p-4" wire:click.self="cancelDeletePage">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-md p-6">
                <h3 class="text-lg font-bold mb-2">Delete page?</h3>
                <p class="text-sm text-gray-600 mb-4">This can't be undone.</p>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelDeletePage" class="px-4 py-2 border border-gray-300 rounded text-sm hover:bg-gray-50">Cancel</button>
                    <button type="button" wire:click="deletePage" class="bg-red-600 text-white px-6 py-2 rounded text-sm font-medium hover:bg-red-700">Delete</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Delete FAQ confirmation --}}
    @if ($confirmingDeleteFaqId)
        <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-40 p-4" wire:click.self="cancelDeleteFaq">
            <div class="bg-white rounded-lg shadow-lg w-full max-w-md p-6">
                <h3 class="text-lg font-bold mb-2">Delete FAQ?</h3>
                <p class="text-sm text-gray-600 mb-4">This can't be undone.</p>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelDeleteFaq" class="px-4 py-2 border border-gray-300 rounded text-sm hover:bg-gray-50">Cancel</button>
                    <button type="button" wire:click="deleteFaq" class="bg-red-600 text-white px-6 py-2 rounded text-sm font-medium hover:bg-red-700">Delete</button>
                </div>
            </div>
        </div>
    @endif
</div>
