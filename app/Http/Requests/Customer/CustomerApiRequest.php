<?php

namespace App\Http\Requests\Customer;

use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base FormRequest for the P0 Customer Core API. `authorize()` defaults
 * true everywhere — every route this backs already sits behind
 * `auth:sanctum`, and per-record OWNERSHIP (address belongs to the caller,
 * booking belongs to the caller, ...) is a data-dependent check the
 * controller/Action performs against the actual row, not something a
 * FormRequest running before the route model even resolves could do
 * generically.
 *
 * Scoped ONLY to requests under this namespace — every other existing
 * FormRequest/inline `$request->validate()` call in the codebase is
 * completely untouched, so this cannot change any pre-existing endpoint's
 * error shape (mission item 7's explicit "compatibility-safe... do not
 * mass-refactor unrelated APIs").
 */
abstract class CustomerApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 422, $validator->errors())
        );
    }
}
