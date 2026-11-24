<?php

namespace App\Services\Marketing;

use App\Models\Setting;
use App\Models\SocialPost;
use Illuminate\Support\Str;

/**
 * 🎬 YouTube Shorts prompt (owner 2026-11-24: "from our digital marketing section we will get the prompt to generate
 * AI short videos on YouTube Shorts create"). Turns a post or a ready-made idea into a copy-ready English prompt for
 * YouTube's own AI video (Veo, about 8 seconds; YouTube asks for English prompts), with the presenter speaking either
 * English or Telugu. The Telugu line is the post's own Telugu caption, so no new translation is invented here.
 *
 * Plain text only: no AI call, no video made on our server. The team pastes it in YouTube, then adds the 1CallFix
 * ending (public/videos/shorts-ending-en|te.mp4), because AI video cannot draw our logo or the real app.
 *
 * Super Admin controls it (Social posts → ⚙️ Settings): on/off and the presenter (woman, man or taking turns).
 * Rules carried in every prompt: the presenter is a host and never a customer, no logos or words on screen, and any
 * offer says it applies when paying online (thumb rule: online payment only for all benefits).
 */
class ShortVideoPrompt
{
    public const ENABLED_SETTING = 'marketing.shorts_prompts';

    public const PRESENTER_SETTING = 'marketing.shorts_presenter';

    public const PRESENTERS = ['alternate' => '🔁 Take turns', 'woman' => '👩 Woman', 'man' => '👨 Man'];

    public const ENDINGS = ['en' => '/videos/shorts-ending-en.mp4', 'te' => '/videos/shorts-ending-te.mp4'];

    private const PEOPLE = [
        'woman' => 'a friendly young Indian woman in a colourful kurta',
        'man' => 'a friendly young Indian man in a neat checked shirt',
    ];

    /** What the camera shows, per Social posts module. */
    private const SCENES = [
        'service' => 'a clean, sunny Indian home; in the background a technician in uniform is servicing a split air conditioner',
        'food' => 'a bright home dining table where a steaming plate of biryani is being served',
        'shop' => 'a colourful local shopping street in a South Indian town with goods on display in the shop fronts',
        'parcel' => 'a house gate where a delivery rider on a scooter hands over a parcel; a small goods truck passes on the street',
        'hotel' => 'a clean, modern hotel room with a neatly made bed and warm lights',
        'events' => 'a festive decorated hall with lights and happy guests in the background',
        'rental' => 'a beautifully decorated function hall with flowers and rows of chairs',
        'listings' => 'a home courtyard with neatly arranged pre-owned furniture',
        'taxi' => 'a clean car waiting at a city roadside',
    ];

    private const DEFAULT_SCENE = 'a sunny South Indian street; the presenter holds a smartphone with the screen facing away from the camera';

    private const OFFER_EN = 'The offer applies when you pay online.';

    private const OFFER_TE = 'ఆన్‌లైన్ చెల్లింపుకు మాత్రమే ఆఫర్ వర్తిస్తుంది.';

    public static function enabled(): bool
    {
        return (string) Setting::get(self::ENABLED_SETTING, '1') !== '0';
    }

    public static function presenterSetting(): string
    {
        $value = (string) Setting::get(self::PRESENTER_SETTING);

        return isset(self::PRESENTERS[$value]) ? $value : 'alternate';
    }

    /** @return array{presenter: string, en: string, te: ?string} */
    public static function forPost(SocialPost $post, ?string $cityName = null): array
    {
        return self::build([
            'key' => 'post:'.$post->id, 'title' => (string) $post->title, 'caption_en' => (string) $post->caption_en,
            'caption_te' => (string) $post->caption_te, 'module' => $post->module, 'source' => (string) $post->source,
        ], $cityName ?? $post->city?->name);
    }

    /** @return array{presenter: string, en: string, te: ?string} */
    public static function forIdea(array $idea, ?string $cityName = null): array
    {
        return self::build([
            'key' => ($idea['source'] ?? '').':'.($idea['source_ref'] ?? ''), 'title' => (string) ($idea['title'] ?? ''),
            'caption_en' => (string) ($idea['caption_en'] ?? ''), 'caption_te' => (string) ($idea['caption_te'] ?? ''),
            'module' => $idea['module'] ?? null, 'source' => (string) ($idea['source'] ?? ''),
        ], $cityName);
    }

    /** @param array{key: string, title: string, caption_en: string, caption_te: string, module: ?string, source: string} $item */
    private static function build(array $item, ?string $cityName): array
    {
        $presenter = self::presenterSetting();
        if ($presenter === 'alternate') {
            $presenter = crc32($item['key']) % 2 === 0 ? 'woman' : 'man';
        }
        $offer = self::isOffer($item);

        $lineEn = self::spokenLine($item['caption_en']) ?: self::spokenLine($item['title']);
        if ($offer && ! Str::contains(Str::lower($lineEn), 'pay online')) {
            $lineEn = rtrim($lineEn, ' .!').'. '.self::OFFER_EN;
        }
        $lineTe = self::spokenLine($item['caption_te']);
        if ($lineTe !== '' && $offer && ! Str::contains($lineTe, 'ఆన్‌లైన్')) {
            $lineTe = rtrim($lineTe, ' .!').'. '.self::OFFER_TE;
        }

        return [
            'presenter' => $presenter,
            'en' => self::prompt($presenter, $item['module'], $cityName, 'in clear, friendly Indian English', $lineEn),
            'te' => $lineTe === '' ? null : self::prompt($presenter, $item['module'], $cityName, 'in Telugu', $lineTe),
        ];
    }

    private static function prompt(string $presenter, ?string $module, ?string $cityName, string $voice, string $line): string
    {
        $where = $cityName ? " in {$cityName}, India" : ' in a South Indian town';
        $scene = self::SCENES[$module] ?? self::DEFAULT_SCENE;

        return 'Vertical 9:16 video, 8 seconds, realistic, bright natural daylight, smooth handheld camera. '
            ."Setting{$where}: {$scene}. "
            .'In front, '.self::PEOPLE[$presenter]." smiles, looks into the camera and says {$voice}: \"{$line}\" "
            .'Warm, trustworthy, upbeat mood with light background music. '
            .'No text, captions, logos or brand names on screen. The presenter is a host, not a customer.';
    }

    /** Offer posts and any wording about a discount must carry the online-payment sentence. */
    private static function isOffer(array $item): bool
    {
        return $item['source'] === 'offer'
            || (bool) preg_match('/\b(offer|coupon|code|discount|cashback|% ?off)\b/i', $item['caption_en'].' '.$item['title']);
    }

    /** Spoken words: whole sentences while they fit in about 8 seconds (22 words), without emoji, links or hashtags. */
    private static function spokenLine(string $text): string
    {
        $text = preg_replace('~https?://\S+|#\S+~u', '', $text);
        $text = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', (string) $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
        if ($text === '') {
            return '';
        }
        $line = '';
        foreach (preg_split('/(?<=[.!?])\s+/u', $text) as $sentence) {
            $next = trim($line.' '.$sentence);
            if ($line !== '' && count(preg_split('/\s+/u', $next)) > 22) {
                break;
            }
            $line = $next;
        }

        return Str::words($line, 22, '…');
    }
}
