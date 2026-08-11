<?php

namespace App\Observers;

use App\Models\User;
use App\Services\CodeGeneratorService;
use App\Services\ReferralService;

class UserObserver
{
    public function __construct(private CodeGeneratorService $codeGenerator, private ReferralService $referralService)
    {
    }

    /**
     * Every user gets their own referral_code to hand out, generated the
     * same way Franchise/Zone codes already are (CodeGeneratorService) --
     * not a second code generator. Never overwrites one already set.
     */
    public function creating(User $user): void
    {
        if (empty($user->referral_code)) {
            $user->referral_code = $this->codeGenerator->generate($user->name ?: 'user', User::class, 'referral_code');
        }
    }

    /**
     * If this user was created with referred_by already set (the resolved
     * referrer's id -- see ReferralService::createFromSignup()'s
     * docblock), create the pending Referral row. Works no matter WHERE
     * the user was created (future signup API, admin form, test fixture),
     * same reasoning as every other observer already registered in
     * AppServiceProvider.
     */
    public function created(User $user): void
    {
        $this->referralService->createFromSignup($user);
    }
}
