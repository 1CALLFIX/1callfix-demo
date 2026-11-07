<?php

namespace App\Support;

/**
 * The best picture size for every upload in the app, in ONE place (owner 2026-11-07: "show image sizes wherever we
 * need to upload the image, for all the pages"). Shown under each upload by <x-ui.image-size use="..." />.
 *
 * Sizes follow how the picture is actually shown on the site (2× for sharp phone screens); `mb` is the limit the
 * server enforces in that screen's validation (ImageSizesTest keeps the two in step).
 */
final class ImageSizes
{
    /** use => [width, height, shape label, types, max MB, tip] (width null = a photo or document, no fixed size) */
    public const USES = [
        'banner' => [1920, 640, '3:1 wide', 'PNG or JPG', 2, 'Keep words and faces in the middle: phones show less of the sides.'],
        'category_icon' => [512, 512, 'square', 'PNG or JPG', 2, 'Transparent PNG looks best.'],
        'subcategory_icon' => [512, 512, 'square', 'PNG or JPG', 2, 'Transparent PNG looks best.'],
        'service_cover' => [1200, 900, '4:3', 'PNG or JPG', 2, 'The service itself, well lit, no text on the photo.'],
        'home_door' => [600, 600, 'square', 'PNG, JPG or WebP', 2, null],
        'parcel_picture' => [800, 550, 'wide', 'PNG, JPG or WebP', 2, 'Transparent PNG, the object only.'],
        'parcel_icon' => [320, 320, 'square', 'PNG, JPG or WebP', 2, 'Transparent PNG.'],
        'site_logo' => [512, 512, 'square', 'PNG, JPG or SVG', 4, 'Transparent background.'],
        'franchise_hero' => [1600, 1200, '4:3', 'JPG, PNG or WebP', 4, 'A real photo of your team, office or partners at work.'],
        'store_logo' => [512, 512, 'square', 'JPG, PNG or WebP', 2, null],
        'store_cover' => [1600, 600, 'wide', 'JPG, PNG or WebP', 4, 'Phones show the middle; keep the main subject centred.'],
        'product_photo' => [1000, 1000, 'square', 'JPG, PNG or WebP', 4, 'Plain background, product filling the frame.'],
        // Restaurant menu items: shown as a small card on the restaurant page and full width on a phone when opened.
        'dish_photo' => [800, 800, 'square', 'JPG, PNG or WebP', 4, 'The dish itself in good light, from above or the side. No text or logos on the photo.'],
        'product_camera' => [1000, 1000, 'square', 'a phone photo', 10, 'Plain background, product filling the frame.'],
        'evidence_photo' => [null, null, null, 'a clear phone photo or PDF', 4, null],
        'safety_photo' => [null, null, null, 'a clear phone photo', 8, null],
        'claim_photo' => [null, null, null, 'clear phone photos', 8, null],
        'return_photo' => [null, null, null, 'clear photos (JPG, PNG or WebP)', 5, null],
        'kyc_document' => [null, null, null, 'a clear photo or PDF of the whole document', 10, 'All four corners visible, no glare.'],
    ];

    /** The one line shown under an upload. */
    public static function line(string $use): string
    {
        [$w, $h, $shape, $types, $mb, $tip] = self::USES[$use] ?? [null, null, null, 'an image', 2, null];
        $parts = $w ? ["Best size {$w} × {$h} px ({$shape})"] : [];
        $parts[] = ucfirst($types);
        $parts[] = "up to {$mb} MB";

        return implode(' · ', $parts).($tip ? '. '.$tip : '.');
    }
}
