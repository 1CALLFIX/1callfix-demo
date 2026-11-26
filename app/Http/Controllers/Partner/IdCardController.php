<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use App\Models\PartnerIdCard;
use App\Services\Partners\IdCardService;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Owner 2026-11-26: the 1CallFix ID card as a PDF to print (the partner's own card only), and the public verify page
 * the card's QR opens (App\Services\Partners\IdCardService).
 */
class IdCardController extends Controller
{
    public function __construct(private IdCardService $cards)
    {
    }

    /** /provider/id-card/print and /rider/id-card/print: the signed-in partner's own card, ready to print. */
    public function pdf(string $type)
    {
        abort_unless(in_array($type, IdCardService::TYPES, true), 404);
        $user = auth()->user();
        $holder = $this->cards->holder($user, $type);
        abort_unless($holder && $this->cards->state($holder) === 'ready', 404);

        $card = $this->cards->cardFor($user, $holder);
        abort_if($this->cards->status($card, $holder) !== 'active', 404);

        $c = $this->cards->data($card, $holder);

        return Pdf::loadView('partner.id-card.pdf', ['c' => $c])
            ->setPaper('a4')
            ->stream('1CallFix-ID-card-'.$c['code'].'.pdf');
    }

    /**
     * /verify/{token}: what a customer sees after scanning a card. Photo, name, work, ID and a big Active / Not active
     * right now. An unknown or replaced token says the card is not valid (same words for every reason).
     */
    public function verify(string $token)
    {
        $card = ctype_xdigit($token) && strlen($token) === 32 ? PartnerIdCard::where('token', $token)->first() : null;
        $holder = $card ? $this->cards->holderOf($card) : null;

        if (! $card || ! $holder) {
            return response()->view('partner.id-card.verify', ['status' => 'invalid', 'c' => null], 404);
        }

        $status = $this->cards->status($card, $holder);
        $c = $this->cards->data($card, $holder);

        return response()->view('partner.id-card.verify', ['status' => $status, 'c' => $c])
            ->header('Cache-Control', 'no-store');
    }
}
