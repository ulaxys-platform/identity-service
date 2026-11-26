<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Fixtures\DomainExample;

use Illuminate\Database\Eloquent\Model;

/** Test-only model: no production migration, route or permission. */
final class ExampleLabel extends Model
{
    protected $table = 'example_domain_labels';

    /** Different test processes must never share and drop the same fixture table. */
    private static ?string $fixtureTable = null;

    public static function useFixtureTable(?string $table): void
    {
        if ($table !== null && ! preg_match('/^example_labels_[a-f0-9]{16}$/D', $table)) {
            throw new \InvalidArgumentException('Invalid disposable fixture table.');
        }

        self::$fixtureTable = $table;
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        if (self::$fixtureTable !== null) {
            $this->setTable(self::$fixtureTable);
        }
    }

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['title' => 'array', 'active' => 'boolean'];
}
