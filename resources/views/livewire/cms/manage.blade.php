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
        <button type="button" wire:click="$set('section', 'benefits')"
                @class(['px-4 py-2 text-sm border-b-2 -mb-px', 'border-slate-900 text-slate-900 font-medium' => $section === 'benefits', 'border-transparent text-gray-500 hover:text-gray-800' => $section !== 'benefits'])>
            Partner benefits ({{ $partnerBenefits->count() }})
        </button>
    </div>

    @if ($section === 'pages')
        {{-- Add New Page --}}
        <x-ui.card class="mb-6">
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
                <x-ui.button class="h-[38px]" wire:click="savePage">+ Add Page</x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.table>
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
                            <button type="button" wire:click="togglePageActive({{ $page->id }})">
                                <x-ui.badge :color="$page->is_active ? 'green' : 'gray'">{{ $page->is_active ? 'Published' : 'Draft' }}</x-ui.badge>
                            </button>
                        </td>
                        <td class="px-4 py-2 text-gray-500">{{ $page->updated_at->diffForHumans() }}</td>
                        <td class="px-4 py-2 text-right">
                            <x-ui.button variant="ghost" class="mr-3" wire:click="editPage({{ $page->id }})">Edit</x-ui.button>
                            <x-ui.button variant="ghost" color="red" wire:click="confirmDeletePage({{ $page->id }})">Delete</x-ui.button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No pages yet. Add your first one above.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    @endif

    @if ($section === 'faqs')
        {{-- Add New FAQ --}}
        <x-ui.card class="mb-6">
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
                <x-ui.button class="h-[38px]" wire:click="saveFaq">+ Add FAQ</x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.table>
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
                            <button type="button" wire:click="toggleFaqActive({{ $faq->id }})">
                                <x-ui.badge :color="$faq->is_active ? 'green' : 'gray'">{{ $faq->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                            </button>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <x-ui.button variant="ghost" class="mr-3" wire:click="editFaq({{ $faq->id }})">Edit</x-ui.button>
                            <x-ui.button variant="ghost" color="red" wire:click="confirmDeleteFaq({{ $faq->id }})">Delete</x-ui.button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No FAQs yet. Add your first one above.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    @endif

    @if ($section === 'benefits')
        <p class="text-sm text-gray-500 mb-4">
            Shown on the public <a href="{{ route('customer.partners') }}" target="_blank" class="text-blue-600 hover:underline">Join as a Partner</a> page,
            in this order. Inactive rows are hidden from the page but kept here.
        </p>

        {{-- Add New Benefit --}}
        <x-ui.card class="mb-6">
            <h2 class="text-sm font-semibold mb-3">Add Partner Benefit</h2>
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-56">
                    <label class="block text-xs font-medium mb-1">Icon <span class="text-red-500">*</span></label>
                    <select wire:model="benefitIcon" class="w-full border rounded px-3 py-2 text-sm">
                        @foreach ($benefitIcons as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('benefitIcon') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="flex-1 min-w-48">
                    <label class="block text-xs font-medium mb-1">Title <span class="text-red-500">*</span></label>
                    <input type="text" wire:model="benefitTitle" placeholder="Steady, well-paid work" class="w-full border rounded px-3 py-2 text-sm">
                    @error('benefitTitle') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="mt-3">
                <label class="block text-xs font-medium mb-1">Description <span class="text-red-500">*</span></label>
                <textarea wire:model="benefitDescription" rows="2" maxlength="500" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                @error('benefitDescription') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end mt-3">
                <x-ui.button class="h-[38px]" wire:click="saveBenefit">+ Add Benefit</x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.table>
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2">Order</th>
                    <th class="px-4 py-2">Icon</th>
                    <th class="px-4 py-2">Title</th>
                    <th class="px-4 py-2">Description</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($partnerBenefits as $benefit)
                    <tr class="border-t hover:bg-gray-50 align-top" wire:key="benefit-{{ $benefit->id }}">
                        <td class="px-4 py-2 whitespace-nowrap">
                            <span class="text-gray-500 mr-1">{{ $benefit->sort_order }}</span>
                            <button type="button" wire:click="moveBenefit({{ $benefit->id }}, 'up')" class="text-gray-400 hover:text-gray-700" title="Move up" @disabled($loop->first)>&uarr;</button>
                            <button type="button" wire:click="moveBenefit({{ $benefit->id }}, 'down')" class="text-gray-400 hover:text-gray-700" title="Move down" @disabled($loop->last)>&darr;</button>
                        </td>
                        <td class="px-4 py-2 text-gray-600"><x-icon :name="$benefit->icon" class="h-5 w-5" /></td>
                        <td class="px-4 py-2 font-medium">{{ $benefit->title }}</td>
                        <td class="px-4 py-2 text-gray-500 max-w-md">{{ $benefit->description }}</td>
                        <td class="px-4 py-2">
                            <button type="button" wire:click="toggleBenefitActive({{ $benefit->id }})">
                                <x-ui.badge :color="$benefit->is_active ? 'green' : 'gray'">{{ $benefit->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                            </button>
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <x-ui.button variant="ghost" class="mr-3" wire:click="editBenefit({{ $benefit->id }})">Edit</x-ui.button>
                            <x-ui.button variant="ghost" color="red" wire:click="confirmDeleteBenefit({{ $benefit->id }})">Delete</x-ui.button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No partner benefits yet. Add your first one above.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    @endif

    {{--
        Phase 21 item TECH-6 (third increment). Cms\Manage previously
        hand-rolled all four of its own dialogs with markup nearly
        identical to x-ui.modal itself (bg-black/40 backdrop, bg-white
        rounded-lg shadow-lg card, X close button, footer buttons) -- a
        genuinely natural fit, not a forced conversion. Same Livewire
        method/property names throughout (showEditPageModal/
        closeEditPageModal, confirmingDeletePageId/cancelDeletePage, ...),
        so no test or backend behavior changes.
    --}}
    <x-ui.modal :show="$showEditPageModal" title="Edit Page" onClose="closeEditPageModal" maxWidth="lg">
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

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="closeEditPageModal">Close</x-ui.button>
            <x-ui.button wire:click="updatePage">Update</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal :show="$showEditFaqModal" title="Edit FAQ" onClose="closeEditFaqModal" maxWidth="lg">
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

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="closeEditFaqModal">Close</x-ui.button>
            <x-ui.button wire:click="updateFaq">Update</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal :show="$confirmingDeletePageId !== null" title="Delete page?" onClose="cancelDeletePage">
        <p class="text-sm text-gray-600">This can't be undone.</p>

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="cancelDeletePage">Cancel</x-ui.button>
            <x-ui.button variant="danger" wire:click="deletePage">Delete</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal :show="$confirmingDeleteFaqId !== null" title="Delete FAQ?" onClose="cancelDeleteFaq">
        <p class="text-sm text-gray-600">This can't be undone.</p>

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="cancelDeleteFaq">Cancel</x-ui.button>
            <x-ui.button variant="danger" wire:click="deleteFaq">Delete</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal :show="$showEditBenefitModal" title="Edit Partner Benefit" onClose="closeEditBenefitModal" maxWidth="lg">
        <div class="space-y-3">
            <div>
                <label class="block text-sm font-medium mb-1">Icon</label>
                <select wire:model="editBenefitIcon" class="w-full border rounded px-3 py-2 text-sm">
                    @foreach ($benefitIcons as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('editBenefitIcon') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Title</label>
                <input type="text" wire:model="editBenefitTitle" class="w-full border rounded px-3 py-2 text-sm">
                @error('editBenefitTitle') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Description</label>
                <textarea wire:model="editBenefitDescription" rows="3" maxlength="500" class="w-full border rounded px-3 py-2 text-sm"></textarea>
                @error('editBenefitDescription') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="editBenefitIsActive" class="rounded"> Active
            </label>
        </div>

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="closeEditBenefitModal">Close</x-ui.button>
            <x-ui.button wire:click="updateBenefit">Update</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal :show="$confirmingDeleteBenefitId !== null" title="Delete partner benefit?" onClose="cancelDeleteBenefit">
        <p class="text-sm text-gray-600">This can't be undone.</p>

        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="cancelDeleteBenefit">Cancel</x-ui.button>
            <x-ui.button variant="danger" wire:click="deleteBenefit">Delete</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
