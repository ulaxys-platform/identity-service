<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ulaxys\Identity\Tests\Fixtures\DomainExample\CreateExampleLabelResponse;
use Ulaxys\Identity\Tests\Fixtures\DomainExample\ExampleLabelDto;
use Ulaxys\Identity\Tests\Fixtures\DomainExample\ListExampleLabelsResponse;

final class DomainExampleContractTest extends TestCase
{
    public function test_unknown_or_untyped_dto_fields_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExampleLabelDto::fromArray(['id' => 1, 'code' => 'EX', 'title' => (object) ['en' => 'Example'], 'active' => true, 'internal_note' => 'secret']);
    }

    public function test_typed_dto_and_contract_response_have_deterministic_envelope(): void
    {
        $item = ExampleLabelDto::fromArray(['id' => 7, 'code' => 'EX', 'title' => (object) ['en' => 'Example', 'ar' => 'مثال'], 'active' => true]);
        self::assertEquals(['id' => 7, 'code' => 'EX', 'title' => (object) ['en' => 'Example', 'ar' => 'مثال'], 'active' => true], $item->toArray());
        self::assertSame(['size' => 1, 'status' => 201, 'message' => 'Created', 'items' => $item->toArray()], (new CreateExampleLabelResponse)->setStatus(201)->setMessage('Created')->setSize(1)->withItem($item)->toArray());
        self::assertSame(['size' => 1, 'status' => 200, 'message' => 'OK', 'items' => [$item->toArray()], 'pagination' => ['limit' => 10, 'offset' => 0, 'total' => 1]], (new ListExampleLabelsResponse)->setStatus(200)->setMessage('OK')->setSize(1)->withPage([$item], 10, 0, 1)->toArray());
    }

    public function test_wrong_type_is_rejected_by_php(): void
    {
        $this->expectException(\TypeError::class);
        ExampleLabelDto::fromArray(['id' => ['not an integer']]);
    }
}
