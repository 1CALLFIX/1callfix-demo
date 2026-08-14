<div>
    <h1 class="text-2xl font-bold mb-1">Notification Center</h1>
    <p class="text-sm text-gray-500 mb-4">Compose a campaign or meeting announcement — sent through the same channels/adapters as booking, payment and payout notifications. Every send is logged in Notification Logs.</p>

    @if ($flashMessage)
        <div @class(['rounded p-3 mb-4 text-sm', 'bg-green-50 text-green-700' => $flashType === 'success', 'bg-red-50 text-red-700' => $flashType === 'error'])>
            {{ $flashMessage }}
        </div>
    @endif

    <div class="flex items-center gap-1 border-b mb-6">
        @foreach (['compose' => 'Compose', 'campaigns' => 'Campaigns', 'meetings' => 'Meetings', 'templates' => 'Templates', 'logs' => 'Delivery Logs', 'status' => 'Provider Status'] as $key => $label)
            <button type="button" wire:click="$set('section', '{{ $key }}')"
                    @class(['px-4 py-2 text-sm border-b-2 -mb-px', 'border-slate-900 text-slate-900 font-medium' => $section === $key, 'border-transparent text-gray-500 hover:text-gray-800' => $section !== $key])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($section === 'compose')
        <div class="bg-white rounded-lg shadow-sm p-4 mb-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div>
                    <label class="block text-xs font-medium mb-1">Category</label>
                    <select wire:model="category" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="business">Business</option>
                        <option value="system">System</option>
                        <option value="operations">Operations</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Type</label>
                    <input type="text" wire:model="type" placeholder="promotion, app_update, maintenance..." class="w-full border rounded px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Module (optional)</label>
                    <select wire:model="module" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="">Any</option>
                        @foreach ($moduleOptions as $slug => $label) <option value="{{ $slug }}">{{ $label }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Priority</label>
                    <select wire:model="priority" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="normal">Normal</option>
                        <option value="high">High</option>
                    </select>
                </div>
            </div>

            @if ($templates->isNotEmpty())
                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <label class="block text-xs font-medium mb-1">Start from a template (optional)</label>
                        <select wire:model="templateId" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="">None</option>
                            @foreach ($templates as $t) <option value="{{ $t->id }}">{{ $t->name }}</option> @endforeach
                        </select>
                    </div>
                    <button type="button" wire:click="applyTemplate" class="border rounded px-4 h-[38px] text-sm hover:bg-gray-50">Apply</button>
                </div>
            @endif

            <div>
                <label class="block text-xs font-medium mb-1">Title <span class="text-red-500">*</span></label>
                <input type="text" wire:model="title" placeholder="Get ₹200 OFF your next AC service" class="w-full border rounded px-3 py-2 text-sm">
                @error('title') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Message <span class="text-red-500">*</span></label>
                <textarea wire:model="message" rows="3" placeholder="Hi @{{name}}, book AC service this week and save ₹200." class="w-full border rounded px-3 py-2 text-sm"></textarea>
                @error('message') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                <p class="text-xs text-gray-400 mt-1">Supports @{{name}} / @{{phone}} — substituted per recipient.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-medium mb-1">Image URL (optional)</label>
                    <input type="text" wire:model="imageUrl" class="w-full border rounded px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Action / deep link (optional)</label>
                    <input type="text" wire:model="actionUrl" class="w-full border rounded px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Coupon (optional)</label>
                    <select wire:model="couponId" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="">None</option>
                        @foreach ($coupons as $c) <option value="{{ $c->id }}">{{ $c->code }}</option> @endforeach
                    </select>
                </div>
            </div>

            <div class="border-t pt-4">
                <label class="block text-sm font-medium mb-2">Audience</label>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs font-medium mb-1">Recipient type</label>
                        <select wire:model.live="recipientType" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="everyone">Everyone</option>
                            <option value="customers">Customers</option>
                            <option value="providers">Providers / Riders</option>
                            <option value="staff">Staff / Admins</option>
                            <option value="specific_user">Specific person (direct message)</option>
                        </select>
                    </div>

                    @if ($recipientType === 'specific_user')
                        <div class="relative md:col-span-3">
                            <label class="block text-xs font-medium mb-1">Search name or phone</label>
                            <input type="text" wire:model.live="specificUserSearch" class="w-full border rounded px-3 py-2 text-sm" autocomplete="off">
                            @if ($specificUserSearch && $this->matchingUsers->isNotEmpty() && ! $specificUserId)
                                <div class="absolute z-10 bg-white border rounded shadow-sm mt-1 w-full max-h-48 overflow-y-auto">
                                    @foreach ($this->matchingUsers as $u)
                                        <button type="button" wire:click="selectSpecificUser({{ $u->id }})" class="block w-full text-left px-3 py-2 text-sm hover:bg-gray-50">{{ $u->name }} — {{ $u->phone }}</button>
                                    @endforeach
                                </div>
                            @endif
                            @if ($specificUserId) <p class="text-xs text-green-700 mt-1">Selected: {{ $specificUserSearch }}</p> @endif
                        </div>
                    @else
                        <div>
                            <label class="block text-xs font-medium mb-1">Scope</label>
                            <select wire:model.live="scopeType" class="w-full border rounded px-3 py-2 text-sm">
                                <option value="global">Global</option>
                                <option value="country">Country</option>
                                <option value="city">City</option>
                                <option value="franchise">Franchise</option>
                                <option value="zone">Zone</option>
                            </select>
                        </div>
                        @if ($scopeType === 'country' || $scopeType === 'city')
                            <div>
                                <label class="block text-xs font-medium mb-1">Country</label>
                                <select wire:model.live="scopeCountryId" class="w-full border rounded px-3 py-2 text-sm">
                                    <option value="">Select...</option>
                                    @foreach ($countries as $c) <option value="{{ $c->id }}">{{ $c->name }}</option> @endforeach
                                </select>
                            </div>
                        @endif
                        @if ($scopeType === 'city')
                            <div>
                                <label class="block text-xs font-medium mb-1">City</label>
                                <select wire:model="scopeCityId" class="w-full border rounded px-3 py-2 text-sm">
                                    <option value="">Select...</option>
                                    @foreach ($cities as $c) <option value="{{ $c->id }}">{{ $c->name }}</option> @endforeach
                                </select>
                            </div>
                        @endif
                        @if ($scopeType === 'franchise' || $scopeType === 'zone')
                            <div>
                                <label class="block text-xs font-medium mb-1">Franchise</label>
                                <select wire:model.live="scopeFranchiseId" class="w-full border rounded px-3 py-2 text-sm">
                                    <option value="">Select...</option>
                                    @foreach ($franchises as $f) <option value="{{ $f->id }}">{{ $f->name }}</option> @endforeach
                                </select>
                            </div>
                        @endif
                        @if ($scopeType === 'zone')
                            <div>
                                <label class="block text-xs font-medium mb-1">Zone</label>
                                <select wire:model="scopeZoneId" class="w-full border rounded px-3 py-2 text-sm">
                                    <option value="">Select...</option>
                                    @foreach ($zones as $z) <option value="{{ $z->id }}">{{ $z->name }}</option> @endforeach
                                </select>
                            </div>
                        @endif
                    @endif
                </div>

                @if ($recipientType !== 'specific_user')
                    <div class="flex flex-wrap gap-4 mt-3">
                        <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" wire:model="filterActiveOnly" class="rounded"> Active only</label>
                        @if ($recipientType === 'customers' || $recipientType === 'everyone')
                            <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" wire:model="filterPrimeOnly" class="rounded"> Prime only</label>
                        @endif
                        @if ($recipientType === 'providers')
                            <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" wire:model="filterOnlineOnly" class="rounded"> Online only</label>
                            <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" wire:model="filterSubscriptionActive" class="rounded"> Active subscription only</label>
                        @endif
                    </div>
                @endif

                <p class="text-sm mt-3 font-medium">Estimated recipients: <span class="text-slate-900">{{ $this->previewCount }}</span></p>
            </div>

            <div class="border-t pt-4">
                <label class="block text-sm font-medium mb-2">Channels</label>
                <div class="flex flex-wrap gap-4">
                    <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" wire:model="chMail" class="rounded"> Mail</label>
                    <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" wire:model="chSms" class="rounded"> SMS</label>
                    <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" wire:model="chPush" class="rounded"> Push</label>
                    <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" wire:model="chInApp" class="rounded"> In-app</label>
                </div>
            </div>

            <div class="border-t pt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium mb-1">Schedule for (optional — leave blank to send now)</label>
                    <input type="datetime-local" wire:model="scheduledAt" class="w-full border rounded px-3 py-2 text-sm">
                    @error('scheduledAt') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Expires at (optional)</label>
                    <input type="datetime-local" wire:model="expiresAt" class="w-full border rounded px-3 py-2 text-sm">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                @if ($scheduledAt)
                    <button type="button" wire:click="scheduleSend" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Schedule</button>
                @else
                    <button type="button" wire:click="sendNow" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Send Now</button>
                @endif
            </div>
        </div>
    @endif

    @if ($section === 'campaigns')
        <div class="bg-white rounded-lg shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <th class="px-4 py-2">Title</th>
                        <th class="px-4 py-2">Type</th>
                        <th class="px-4 py-2">Audience</th>
                        <th class="px-4 py-2">Channels</th>
                        <th class="px-4 py-2">Status</th>
                        <th class="px-4 py-2">Recipients</th>
                        <th class="px-4 py-2">When</th>
                        <th class="px-4 py-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($campaigns as $c)
                        <tr class="border-t hover:bg-gray-50" wire:key="campaign-{{ $c->id }}">
                            <td class="px-4 py-2 font-medium">{{ $c->title }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $c->type }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $c->recipient_type }} / {{ $c->scope_type }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $c->channels }}</td>
                            <td class="px-4 py-2">
                                <span @class([
                                    'px-2 py-0.5 rounded text-xs',
                                    'bg-gray-100 text-gray-700' => in_array($c->status, ['draft']),
                                    'bg-blue-100 text-blue-700' => in_array($c->status, ['scheduled', 'sending']),
                                    'bg-green-100 text-green-700' => $c->status === 'sent',
                                    'bg-red-100 text-red-700' => in_array($c->status, ['failed', 'cancelled']),
                                ])>{{ $c->status }}</span>
                            </td>
                            <td class="px-4 py-2">{{ $c->recipient_count }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $c->sent_at?->diffForHumans() ?? $c->scheduled_at?->diffForHumans() ?? $c->created_at->diffForHumans() }}</td>
                            <td class="px-4 py-2 text-right">
                                @if (in_array($c->status, ['draft', 'scheduled']))
                                    <button type="button" wire:click="cancelCampaign({{ $c->id }})" class="text-xs text-red-600 hover:underline">Cancel</button>
                                @endif
                                @if ($c->status === 'sent')
                                    <button type="button" wire:click="resendFailed({{ $c->id }})" wire:confirm="Resend to every currently-failed recipient?" class="text-xs text-amber-600 hover:underline">Resend failed</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No campaigns yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3 border-t">{{ $campaigns->links() }}</div>
        </div>
    @endif

    @if ($section === 'meetings')
        <div class="bg-white rounded-lg shadow-sm p-4 mb-6 space-y-3">
            <h2 class="text-sm font-semibold">Schedule a Meeting / Announcement</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium mb-1">Title <span class="text-red-500">*</span></label>
                    <input type="text" wire:model="meetingTitle" placeholder="Mandatory Rider Training" class="w-full border rounded px-3 py-2 text-sm">
                    @error('meetingTitle') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Audience</label>
                    <select wire:model="meetingRecipientType" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="providers">Providers / Riders</option>
                        <option value="customers">Customers</option>
                        <option value="staff">Staff / Admins</option>
                        <option value="everyone">Everyone</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium mb-1">Description</label>
                <textarea wire:model="meetingDescription" rows="2" class="w-full border rounded px-3 py-2 text-sm"></textarea>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div>
                    <label class="block text-xs font-medium mb-1">Starts at <span class="text-red-500">*</span></label>
                    <input type="datetime-local" wire:model="meetingStartsAt" class="w-full border rounded px-3 py-2 text-sm">
                    @error('meetingStartsAt') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Duration (minutes)</label>
                    <input type="number" wire:model="meetingDuration" class="w-full border rounded px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Location</label>
                    <input type="text" wire:model="meetingLocation" placeholder="Franchise office, Nellore" class="w-full border rounded px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1">Online link</label>
                    <input type="text" wire:model="meetingLink" class="w-full border rounded px-3 py-2 text-sm">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-medium mb-1">Scope</label>
                    <select wire:model="meetingScopeType" class="w-full border rounded px-3 py-2 text-sm">
                        <option value="global">Global</option>
                        <option value="franchise">Franchise</option>
                    </select>
                </div>
                @if ($meetingScopeType === 'franchise')
                    <div>
                        <label class="block text-xs font-medium mb-1">Franchise</label>
                        <select wire:model="meetingScopeFranchiseId" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="">Select...</option>
                            @foreach ($franchises as $f) <option value="{{ $f->id }}">{{ $f->name }}</option> @endforeach
                        </select>
                    </div>
                @endif
                <div>
                    <label class="block text-xs font-medium mb-1">Reminders (minutes before, comma-separated)</label>
                    <input type="text" wire:model="meetingReminders" placeholder="1440,120" class="w-full border rounded px-3 py-2 text-sm">
                </div>
            </div>
            <div class="flex justify-end pt-2">
                <button type="button" wire:click="createMeeting" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">Schedule Meeting</button>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <th class="px-4 py-2">Title</th>
                        <th class="px-4 py-2">Audience</th>
                        <th class="px-4 py-2">Starts</th>
                        <th class="px-4 py-2">Location</th>
                        <th class="px-4 py-2">Reminders</th>
                        <th class="px-4 py-2">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($meetings as $m)
                        <tr class="border-t hover:bg-gray-50" wire:key="meeting-{{ $m->id }}">
                            <td class="px-4 py-2 font-medium">{{ $m->title }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $m->recipient_type }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $m->starts_at->format('d M Y, g:i A') }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $m->location ?? $m->meeting_link ?? '—' }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ count($m->reminder_offsets_minutes ?? []) }}</td>
                            <td class="px-4 py-2">{{ $m->status }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No meetings scheduled yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3 border-t">{{ $meetings->links() }}</div>
        </div>
    @endif

    @if ($section === 'templates')
        @if ($canManageTemplates)
            <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
                <h2 class="text-sm font-semibold text-gray-500 uppercase mb-3">{{ $editingTemplateId ? 'Edit template' : 'New template' }}</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div><label class="block text-xs font-medium mb-1">Key (unique, machine-readable)</label><input type="text" wire:model="templateKey" class="w-full border rounded px-3 py-2 text-sm">@error('templateKey')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror</div>
                    <div><label class="block text-xs font-medium mb-1">Name</label><input type="text" wire:model="templateName" class="w-full border rounded px-3 py-2 text-sm">@error('templateName')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror</div>
                    <div class="md:col-span-2"><label class="block text-xs font-medium mb-1">Title template</label><input type="text" wire:model="templateTitle" class="w-full border rounded px-3 py-2 text-sm">@error('templateTitle')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror</div>
                    <div class="md:col-span-2"><label class="block text-xs font-medium mb-1">Body template</label><textarea wire:model="templateBody" rows="3" class="w-full border rounded px-3 py-2 text-sm"></textarea>@error('templateBody')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror</div>
                    <div class="md:col-span-2"><label class="block text-xs font-medium mb-1">Description</label><input type="text" wire:model="templateDescription" class="w-full border rounded px-3 py-2 text-sm"></div>
                </div>
                <div class="flex justify-end gap-2 pt-4 mt-4 border-t">
                    @if ($editingTemplateId)<button type="button" wire:click="startEditingTemplate" class="text-sm text-gray-500">Cancel edit</button>@endif
                    <button type="button" wire:click="saveTemplate" class="bg-slate-900 text-white px-6 py-2 rounded text-sm font-medium hover:bg-slate-800">{{ $editingTemplateId ? 'Update' : 'Create' }} Template</button>
                </div>
            </div>
        @endif

        <div class="bg-white rounded-lg shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-500"><tr><th class="px-4 py-2">Key</th><th class="px-4 py-2">Name</th><th class="px-4 py-2">Title template</th><th class="px-4 py-2 text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($templates as $t)
                        <tr class="border-t hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono text-xs">{{ $t->key }}</td>
                            <td class="px-4 py-2 font-medium">{{ $t->name }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ \Illuminate\Support\Str::limit($t->title_template, 60) }}</td>
                            <td class="px-4 py-2 text-right">
                                @if ($canManageTemplates)
                                    <button type="button" wire:click="startEditingTemplate({{ $t->id }})" class="text-xs text-blue-600 hover:underline mr-3">Edit</button>
                                    <button type="button" wire:click="deleteTemplate({{ $t->id }})" wire:confirm="Delete this template?" class="text-xs text-red-600 hover:underline">Delete</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No templates yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($section === 'logs')
        @if (! $canViewLogs)
            <p class="text-sm text-gray-400">You do not have permission to view delivery logs.</p>
        @else
            <div class="flex flex-wrap gap-3 mb-4">
                <input type="text" wire:model.live.debounce.400ms="logSearch" placeholder="Search event/type/error..." class="border rounded px-3 py-2 text-sm w-72">
                <select wire:model.live="logChannelFilter" class="border rounded px-3 py-2 text-sm">
                    <option value="">All channels</option>
                    <option value="mail">Mail</option><option value="sms">SMS</option><option value="push">Push</option><option value="database">In-app</option>
                </select>
                <select wire:model.live="logStatusFilter" class="border rounded px-3 py-2 text-sm">
                    <option value="">All statuses</option><option value="sent">Sent</option><option value="failed">Failed</option>
                </select>
            </div>
            <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-gray-500"><tr><th class="px-4 py-2">Recipient</th><th class="px-4 py-2">Channel</th><th class="px-4 py-2">Type / Event</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Error</th><th class="px-4 py-2">Sent</th></tr></thead>
                    <tbody>
                        @forelse ($logs as $l)
                            <tr class="border-t hover:bg-gray-50">
                                <td class="px-4 py-2">{{ $l->notifiable?->name ?? "#{$l->notifiable_id}" }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ $l->channel }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ $l->notification_type }} / {{ $l->event }}</td>
                                <td class="px-4 py-2">
                                    <span @class(['px-2 py-0.5 rounded text-xs', 'bg-green-100 text-green-700' => $l->status === 'sent', 'bg-red-100 text-red-700' => $l->status === 'failed'])>{{ $l->status }}</span>
                                </td>
                                <td class="px-4 py-2 text-red-500 text-xs">{{ \Illuminate\Support\Str::limit($l->error, 60) }}</td>
                                <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ $l->sent_at?->diffForHumans() ?? $l->created_at->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">No delivery logs match your filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="px-4 py-3 border-t">{{ $logs->links() }}</div>
            </div>
        @endif
    @endif

    @if ($section === 'status')
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded-lg shadow-sm p-4">
                <h2 class="text-sm font-semibold text-gray-500 uppercase mb-3">Provider bindings</h2>
                <p class="text-xs text-gray-400 mb-3">Which adapter is actually wired up right now. No credentials are ever shown here.</p>
                <dl class="text-sm space-y-2">
                    <div class="flex justify-between items-center">
                        <dt>SMS</dt>
                        <dd class="flex items-center gap-2">
                            {{ $providerStatus['sms_adapter'] }}
                            @if ($providerStatus['sms_is_log_fallback'])<span class="px-2 py-0.5 rounded text-xs bg-amber-100 text-amber-700">Dev/log fallback — not real delivery</span>@else<span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-700">Real provider bound</span>@endif
                        </dd>
                    </div>
                    <div class="flex justify-between items-center">
                        <dt>Push</dt>
                        <dd class="flex items-center gap-2">
                            {{ $providerStatus['push_adapter'] }}
                            @if ($providerStatus['push_is_log_fallback'])<span class="px-2 py-0.5 rounded text-xs bg-amber-100 text-amber-700">Dev/log fallback — not real delivery</span>@else<span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-700">Real provider bound</span>@endif
                        </dd>
                    </div>
                </dl>
            </div>
            <div class="bg-white rounded-lg shadow-sm p-4">
                <h2 class="text-sm font-semibold text-gray-500 uppercase mb-3">Delivery stats (last 30 days)</h2>
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500"><tr><th class="py-1">Channel</th><th class="py-1">Sent</th><th class="py-1">Failed</th></tr></thead>
                    <tbody>
                        @forelse ($deliveryStats as $channel => $counts)
                            <tr class="border-t"><td class="py-1.5">{{ $channel }}</td><td class="py-1.5 text-green-700">{{ $counts['sent'] ?? 0 }}</td><td class="py-1.5 text-red-700">{{ $counts['failed'] ?? 0 }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="py-3 text-center text-gray-400">No deliveries in the last 30 days.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
