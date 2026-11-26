<?php

namespace Ulaxys\Identity\Http\Requests\HealthRequests;

use Ulaxys\Identity\Http\Requests\ApiFormRequest;

final class PostgresStatusRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [];
    }
}
