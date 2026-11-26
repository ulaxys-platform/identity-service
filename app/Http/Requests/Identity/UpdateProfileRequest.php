<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Http\Requests\Identity;

use OpenApi\Attributes as OA;
use Ulaxys\Identity\Http\Requests\ApiFormRequest;

#[OA\Schema(
    schema: 'IdentityUpdateProfileInput',
    additionalProperties: false,
    properties: [
        new OA\Property(property: 'displayName', type: 'string', maxLength: 120),
        new OA\Property(property: 'firstName', type: 'string', maxLength: 80, nullable: true),
        new OA\Property(property: 'lastName', type: 'string', maxLength: 80, nullable: true),
        new OA\Property(property: 'preferredLanguage', type: 'string', enum: ['en', 'tr', 'ar']),
        new OA\Property(property: 'preferredTimezone', type: 'string'),
    ],
)]
final class UpdateProfileRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'displayName' => ['sometimes', 'string', 'min:1', 'max:120'],
            'firstName' => ['sometimes', 'nullable', 'string', 'max:80'],
            'lastName' => ['sometimes', 'nullable', 'string', 'max:80'],
            'preferredLanguage' => ['sometimes', 'string', 'in:en,tr,ar'],
            'preferredTimezone' => ['sometimes', 'string', 'timezone:all'],
        ];
    }
}
