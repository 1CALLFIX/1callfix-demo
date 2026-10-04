# Acquisition attribution (F1)

First-touch marketing attribution saved on `bookings.acquisition` (nullable JSON). **Display and reporting only**:
never used for pricing, permissions or eligibility. One whitelist, one cleaner:
`App\Support\Acquisition\AcquisitionSanitizer` (web middleware and API both use it).

## Web
`CaptureAcquisition` middleware (web group): the first GET carrying a tracking key is stored in the session and a
30-day `cf_acq` cookie. Later visits never overwrite it. `CreateBookingAction` copies it onto the booking.

## Flutter / API
`POST /api/bookings` and `POST /api/booking-bundles` accept an optional `acquisition` object (a bundle's children
all receive it). Allowed keys, all strings, max 255 characters each:

`utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `gclid`, `fbclid`, `msclkid`,
`landing_path`, `referrer_host`, `captured_at` (ISO 8601).

Rules: unknown keys and values over 255 characters are dropped (not truncated); control characters are stripped;
the object is stored only if at least one of the first eight keys survives. A bad `acquisition` never fails a booking.

Where the app gets the values (nothing is built in Flutter yet):
- **Play Install Referrer** (Play Store installs): read the `referrer` string on first launch, parse it as a query
  string (`utm_source=...&utm_campaign=...`), keep it locally.
- **Deep link / dynamic link parameters**: same keys on the opening URL.
- Store it once on first launch (first touch), then send it unchanged with every booking.

Admin: the booking detail page shows source, medium, campaign, landing path and captured-at (read only).
