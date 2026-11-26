<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Fixtures\DomainExample;

use Ulaxys\Identity\Http\Requests\ApiFormRequest;

final class CreateExampleLabelRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,19}$/'],
            'title' => ['required', 'array:en,tr,ar'],
            'title.en' => ['required', 'string', 'max:120'],
            'title.tr' => ['sometimes', 'string', 'max:120'],
            'title.ar' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
