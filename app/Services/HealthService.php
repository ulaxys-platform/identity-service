<?php

namespace Ulaxys\Identity\Services;

use Ulaxys\Identity\Repositories\RepositoryInterface\HealthRepositoryInterface;
use Ulaxys\Identity\Transformers\ErrorResponseObjects\HttpErrorResponseObjectOfResult;
use Ulaxys\Identity\Transformers\HttpSuccessResponseObject;
use Ulaxys\Identity\Transformers\ResponseObject;

/** Infrastructure probes open a connection; they do not query or mutate domain tables. */
final class HealthService
{
    public function __construct(private HealthRepositoryInterface $repository) {}

    public function getHealthCheck(): ResponseObject
    {
        return (new HttpSuccessResponseObject)->setStatus(200)->setMessage('Service is available')->setSize(0);
    }

    public function getPostgresCheck(): ResponseObject
    {
        try {
            $this->repository->probe();

            return (new HttpSuccessResponseObject)->setStatus(200)->setMessage('Database is available')->setSize(0);
        } catch (\Throwable) {
            return (new HttpErrorResponseObjectOfResult)->setStatus(503)->setCode(503)->setMessage('Database is unavailable.');
        }
    }
}
