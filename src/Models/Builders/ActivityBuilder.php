<?php

namespace Storyfeed\Models\Builders;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of \Storyfeed\Models\Activity
 *
 * @extends Builder<TModel>
 */
class ActivityBuilder extends Builder
{
    /**
     * Activities visible to readers. The reader passes its own $now so the
     * gate cannot shift between the phases of one read.
     */
    public function published(?DateTimeInterface $now = null): static
    {
        $this->whereNotNull('published_at')
            ->where('published_at', '<=', $now ?? now());

        return $this;
    }

    public function verb(string $verb): static
    {
        $this->where('verb', $verb);

        return $this;
    }

    public function actor(Model $model): static
    {
        return $this->whereMorphRole('actor', $model);
    }

    public function object(Model $model): static
    {
        return $this->whereMorphRole('object', $model);
    }

    public function target(Model $model): static
    {
        return $this->whereMorphRole('target', $model);
    }

    public function context(Model $model): static
    {
        return $this->whereMorphRole('context', $model);
    }

    /**
     * Activities involving the model in any role: actor, object, or target.
     */
    public function involving(Model $model): static
    {
        $this->where(function (self $query) use ($model) {
            $query
                ->where(fn (self $q) => $q->whereMorphRole('actor', $model))
                ->orWhere(fn (self $q) => $q->whereMorphRole('object', $model))
                ->orWhere(fn (self $q) => $q->whereMorphRole('target', $model));
        });

        return $this;
    }

    public function today(): static
    {
        $this->whereDate('published_at', today());

        return $this;
    }

    public function yesterday(): static
    {
        $this->whereDate('published_at', today()->subDay());

        return $this;
    }

    public function thisWeek(): static
    {
        $this->whereBetween('published_at', [
            now()->startOfWeek(),
            now()->endOfWeek(),
        ]);

        return $this;
    }

    /**
     * Role columns store morph aliases, so the comparison must use
     * getMorphClass() — never get_class().
     */
    protected function whereMorphRole(string $role, Model $model): static
    {
        $this->where("{$role}_type", $model->getMorphClass())
            ->where("{$role}_id", $model->getKey());

        return $this;
    }
}
