<?php

namespace App\Http\Requests\V1\Contractor;

use App\Enums\ContractorMemberRole;
use App\Http\Requests\V1\Promoter\InvitationRequest;
use Illuminate\Validation\Rule;

/**
 * « Inviter un collaborateur » : nom, rôle (chef de chantier, technicien, comptable, observateur) et canal (SMS
 * avec dial_code/phone_number, ou e-mail).
 */
class MemberInvitationRequest extends InvitationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'role' => ['required', Rule::enum(ContractorMemberRole::class)]];
    }
}
