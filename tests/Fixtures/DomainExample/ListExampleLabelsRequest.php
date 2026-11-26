<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Fixtures\DomainExample;

use Ulaxys\Identity\Http\Requests\ApiFormRequest;

final class ListExampleLabelsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return ['limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'offset' => ['sometimes', 'integer', 'min:0']];
    }
}
