<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Search placeholder fallback examples (1CF-HOMESCREEN-UX-001)
    |--------------------------------------------------------------------------
    |
    | The rotating search-bar placeholder ("Search for 'AC service'", ...)
    | prefers real, ACTIVE service/category names for the viewer's zone (see
    | App\Livewire\Customer\SearchBar::placeholderExamples()). This list is
    | only the fallback for when the catalog has fewer than 3 usable names —
    | a brand-new franchise, an empty catalog, or a zone with no bookings/
    | services yet. Not an admin-editable setting: the spec asks for one only
    | if catalog-driven examples turn out to be impractical, and they are not
    | — this is a plain config default for the rare empty-catalog case, kept
    | here (rather than hard-coded in the component) so it is at least easy
    | to find and edit without a code review of the component itself.
    |
    */
    'search_placeholder_examples' => [
        'AC service',
        'Plumbing',
        'Electrician',
        'Home cleaning',
        'Appliance repair',
        'Pest control',
    ],

];
