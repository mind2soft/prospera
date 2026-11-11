<?php

namespace App\Http\Requests\Concerns;

use App\Models\FinancialProfile;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Creation requests naming their profile in the payload (`profile_id`):
 * the caller must manage the profile, checked before the rest of the
 * payload is validated. Unknown profiles and non-members get a 404
 * (profiles are invisible to outsiders); a missing or malformed
 * `profile_id` is left to the validation rules.
 */
trait AuthorizesPayloadProfile
{
    private ?FinancialProfile $payloadProfile = null;

    public function authorize(): Response
    {
        $profileId = filter_var($this->input('profile_id'), FILTER_VALIDATE_INT);

        if ($profileId === false) {
            return Response::allow();
        }

        $this->payloadProfile = FinancialProfile::query()->find($profileId);

        return $this->payloadProfile === null
            ? Response::denyAsNotFound()
            : Gate::inspect('manage', $this->payloadProfile);
    }

    /**
     * The authorized profile (only once the request passed validation).
     */
    public function profile(): FinancialProfile
    {
        /** @var FinancialProfile */
        return $this->payloadProfile;
    }
}
