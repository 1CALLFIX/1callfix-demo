{{--
    Owner 2026-11-26: "this is how we need the logo" (the banners): ONE blue pill with a white edge, the round logo
    inside on the left filling the pill's height (about 2.3 times the word), a gap, then "1CallFix" centred beside it
    (Arial Bold, "1" and "Fix" #11C3F2, "Call" white), more room on the right. Used on the ID card, the Verified
    Partner certificate and the door stickers, on screen and in the PDF. dompdf centres table cells reliably but not
    inline text, hence the small table inside the pill; display on the img because the site's base styles make images
    blocks. $logo = BrandingAssetService::logoDataUri() (null: the word alone); $size = font size of the word (em).
--}}
<span data-brand-pill style="display: inline-block; background: #133DA9; border: 0.09em solid #ffffff; border-radius: 1.5em; padding: {{ ! empty($logo) ? '0.07em 0.95em 0.07em 0.07em' : '0.35em 0.8em' }}; font-family: Arial, Helvetica, sans-serif; font-weight: bold; font-size: {{ $size }}; line-height: 1;"><table style="border-collapse: collapse; font-size: 1em;" cellpadding="0" cellspacing="0"><tr>@if (! empty($logo))<td style="vertical-align: middle; padding: 0;"><img src="{{ $logo }}" alt="1CallFix logo" data-brand-logo style="display: block; width: 2.3em; height: 2.3em;"></td>@endif<td style="vertical-align: middle; padding: 0 0 0 {{ ! empty($logo) ? '0.45em' : '0' }}; white-space: nowrap; font-weight: bold; line-height: 1;"><span style="color: #11C3F2;">1</span><span style="color: #ffffff;">Call</span><span style="color: #11C3F2;">Fix</span></td></tr></table></span>
