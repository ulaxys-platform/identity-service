<?php

namespace Ulaxys\Identity\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Ulaxys\Identity\Dto\BaseDto;
use Ulaxys\Identity\Transformers\HttpSuccessResponseObjectOfContract;

final class ContractTest extends TestCase
{
    public function test_typed_payload_serializes_values_and_rejects_unknown_fields(): void
    {
        $dto = SampleDto::fromArray(['name' => 'test']);
        $response = (new SampleResponse)->setStatus(200)->setSize(1)->setMessage('OK');
        $response->items = $dto;
        $this->assertSame(['name' => 'test'], $response->toArray()['items']);
        $this->expectException(\InvalidArgumentException::class);
        SampleDto::fromArray(['model' => new \stdClass]);
    }

    public function test_dto_type_rejects_model_retention(): void
    {
        $this->expectException(\TypeError::class);
        SampleDto::fromArray(['name' => new class extends Model {}]);
    }
}
final class SampleDto extends BaseDto
{
    public string $name;
}
final class SampleResponse extends HttpSuccessResponseObjectOfContract
{
    public SampleDto $items;
}
