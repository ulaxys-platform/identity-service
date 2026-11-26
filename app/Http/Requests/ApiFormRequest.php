<?php

namespace Ulaxys\Identity\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Ulaxys\Identity\Transformers\ErrorResponseObjects\HttpErrorResponseObjectOfResult;

abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function failedValidation(Validator $validator): void
    {
        $error = (new HttpErrorResponseObjectOfResult)->setStatus(400)->setCode(400)->setMessage(implode(' ', $validator->errors()->all()));
        throw new HttpResponseException(response()->json($error->toArray(), 400));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $allowed = array_map(fn ($key) => explode('.', $key)[0], array_keys($this->rules()));
            foreach (array_keys($this->all()) as $field) {
                if (! in_array($field, $allowed, true)) {
                    $validator->errors()->add($field, 'Unsupported request field.');
                }
            }
            $walk = function (mixed $value, string $path) use (&$walk, $validator): void {
                if (is_array($value)) {
                    foreach ($value as $key => $item) {
                        $walk($item, $path.'.'.$key);
                    }
                } elseif (is_string($value) && (str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8'))) {
                    $validator->errors()->add($path, 'Text must be valid UTF-8 without NUL bytes.');
                }
            };
            $walk($this->all(), 'request');
        });
    }
}
