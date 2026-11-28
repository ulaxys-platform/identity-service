<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\Repository;

use Illuminate\Support\Carbon;
use Ulaxys\Identity\Models\SigningKey;
use Ulaxys\Identity\Repositories\RepositoryInterface\SigningKeyRepositoryInterface;

final class SigningKeyRepository implements SigningKeyRepositoryInterface
{
    public function active(): ?SigningKey
    {
        return SigningKey::query()->where('is_active', true)->orderByDesc('activated_at')->first();
    }

    public function verifiable(): array
    {
        $publicationDays = max(0, (int) config('identity.keys.retired_publication_days', 7));
        $cutoff = Carbon::now()->subDays($publicationDays);

        return SigningKey::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where('is_active', true)->orWhere(function ($retired) use ($cutoff): void {
                    $retired->where('is_active', false)->whereNotNull('retired_at')->where('retired_at', '>=', $cutoff);
                });
            })
            ->orderByDesc('is_active')
            ->orderByDesc('activated_at')
            ->get()
            ->all();
    }

    public function create(array $attributes): SigningKey
    {
        return SigningKey::query()->create($attributes);
    }

    public function retireOthers(string $kid): int
    {
        return SigningKey::query()
            ->where('kid', '!=', $kid)
            ->where('is_active', true)
            ->update(['is_active' => false, 'retired_at' => now()]);
    }
}
