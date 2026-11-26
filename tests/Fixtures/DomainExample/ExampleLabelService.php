<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Fixtures\DomainExample;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Ulaxys\Identity\Transformers\ErrorResponseObjects\HttpErrorResponseObjectOfResult;
use Ulaxys\Identity\Transformers\ResponseObject;

final class ExampleLabelService
{
    public function __construct(
        private ExampleLabelRepositoryInterface $repository,
        private ExampleLabelMapper $mapper,
    ) {}

    public function list(array $filters): ListExampleLabelsResponse
    {
        $limit = (int) ($filters['limit'] ?? 25);
        $offset = (int) ($filters['offset'] ?? 0);
        [$models, $total] = $this->repository->page($limit, $offset);
        $items = array_map($this->mapper->map(...), $models);

        return (new ListExampleLabelsResponse)
            ->setStatus(200)->setMessage('OK')->setSize(count($items))
            ->withPage($items, $limit, $offset, $total);
    }

    public function create(array $data): ResponseObject
    {
        try {
            return DB::transaction(function () use ($data): ResponseObject {
                // Helpful domain precheck; PostgreSQL remains the final concurrency authority.
                if ($this->repository->codeExists($data['code'])) {
                    return $this->duplicateCode();
                }

                $model = $this->repository->create($data['code'], $data['title']);

                return (new CreateExampleLabelResponse)
                    ->setStatus(201)->setMessage('Created')->setSize(1)
                    ->withItem($this->mapper->map($model));
            });
        } catch (QueryException $exception) {
            // Catch only after transaction rollback: a failed PostgreSQL statement
            // invalidates the transaction until it has rolled back.
            // This fixture has exactly one UNIQUE constraint: the label code.
            if ((string) $exception->getCode() !== '23505') {
                throw $exception;
            }

            return $this->duplicateCode();
        }
    }

    private function duplicateCode(): HttpErrorResponseObjectOfResult
    {
        return (new HttpErrorResponseObjectOfResult)
            ->setStatus(409)->setCode(409)->setMessage('Label code already exists.');
    }
}
