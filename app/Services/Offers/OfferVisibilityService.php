<?php

namespace App\Services\Offers;

use Illuminate\Database\Eloquent\Builder;

class OfferVisibilityService
{
    public function applyVisibilityFilter(Builder $query, string $context = 'web'): Builder
    {
        if ($context === 'web') {
            return $query->whereIn('visibility_rule', ['show_all', 'show_accepted_only']);
        }

        if ($context === 'admin') {
            return $query->where('visibility_rule', '!=', 'hide_rejected');
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    public function getVisibilityRules(): array
    {
        return ['show_all', 'show_accepted_only', 'hide_rejected'];
    }
}
