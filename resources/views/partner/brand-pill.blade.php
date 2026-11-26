{{--
    Owner 2026-11-26: "this is how we need the logo" (the banners), measured from the owner's banner and copied in
    proportion and colour, all in em of the word "1CallFix" (Arial Bold, "1" and "Fix" #13A4F3, "Call" white,
    pill #0D3FAD, soft white edge #DCEDFF):
      white edge 0.055 · blue gap round the logo 0.26 top/bottom, 0.29 left · round logo 1.97 across ·
      gap 0.43 · the word · 0.87 blue on the right · pill 2.64 tall, fully rounded (banner: word 505 px, pill 994 × 348).
    The logo is the owner's own (BrandingAssetService::PILL_LOGO). Used on the ID card, the Verified Partner certificate
    and the door stickers, on screen and in the PDF. dompdf centres table cells reliably but not inline text, hence the
    small table inside the pill; display on the img because the site's base styles make images blocks.
    $logo = BrandingAssetService::logoDataUri() (null: the word alone); $size = font size of the word (em).
--}}
<span data-brand-pill style="display: inline-block; background: #0D3FAD; border: 0.055em solid #DCEDFF; border-radius: 1.35em; padding: {{ ! empty($logo) ? '0.26em 0.87em 0.26em 0.29em' : '0.35em 1em' }}; font-family: Arial, Helvetica, sans-serif; font-weight: bold; font-size: {{ $size }}; line-height: 1;"><table style="border-collapse: collapse; font-size: 1em;" cellpadding="0" cellspacing="0"><tr>@if (! empty($logo))<td style="vertical-align: middle; padding: 0;"><img src="{{ $logo }}" alt="1CallFix logo" data-brand-logo style="display: block; width: 1.97em; height: 1.97em;"></td>@endif<td style="vertical-align: middle; padding: 0 0 0 {{ ! empty($logo) ? '0.43em' : '0' }}; white-space: nowrap; font-weight: bold; line-height: 1;"><span style="color: #13A4F3;">1</span><span style="color: #ffffff;">Call</span><span style="color: #13A4F3;">Fix</span></td></tr></table></span>
