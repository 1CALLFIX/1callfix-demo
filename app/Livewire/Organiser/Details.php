<?php

namespace App\Livewire\Organiser;

use App\Livewire\Organiser\Concerns\InteractsWithOrganiser;
use App\Services\ActivityLogger;
use App\Support\ImageShrinker;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Organiser portal → Organiser (Events E1): what buyers see about the organiser — name, about, phone, address, GST, logo. */
class Details extends Component
{
    use InteractsWithOrganiser;
    use WithFileUploads;

    public string $name = '';
    public string $about = '';
    public string $contactPhone = '';
    public string $addressLine = '';
    public string $gstNumber = '';
    public $logo = null;
    public string $notice = '';

    public function mount(): void
    {
        $o = $this->organiser();
        $this->name = (string) $o->name;
        $this->about = (string) $o->about;
        $this->contactPhone = (string) $o->contact_phone;
        $this->addressLine = (string) $o->address_line;
        $this->gstNumber = (string) $o->gst_number;
    }

    public function save(): void
    {
        $this->reset('notice');
        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'about' => ['nullable', 'string', 'max:2000'],
            'contactPhone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+][0-9 \-]*$/'],
            'addressLine' => ['nullable', 'string', 'max:500'],
            'gstNumber' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z0-9]{13}$/'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], ['gstNumber.regex' => 'A GST number is 15 characters, like 37ABCDE1234F1Z5.']);

        $organiser = $this->organiser();
        $fields = [
            'name' => trim($this->name),
            'about' => trim($this->about) ?: null,
            'contact_phone' => trim($this->contactPhone) ?: null,
            'address_line' => trim($this->addressLine) ?: null,
            'gst_number' => strtoupper(trim($this->gstNumber)) ?: null,
        ];
        if ($this->logo) {
            $fields['logo'] = ImageShrinker::store($this->logo, 'organisers/'.$organiser->id, 'organiser_logo');
        }
        $organiser->update($fields);
        ActivityLogger::logModel(auth()->user(), $organiser, 'Organiser details updated (organiser portal)');
        $this->reset('logo');
        $this->notice = 'Saved.';
    }

    public function render()
    {
        $organiser = $this->organiser();

        return view('livewire.organiser.details', [
            'organiser' => $organiser,
            'logoUrl' => $organiser->logo ? (str_starts_with($organiser->logo, 'http') ? $organiser->logo : \Illuminate\Support\Facades\Storage::disk('public')->url($organiser->logo)) : null,
        ])->layout('components.layouts.organiser', ['title' => 'Organiser']);
    }
}
