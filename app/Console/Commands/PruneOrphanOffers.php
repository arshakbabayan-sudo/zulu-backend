<?php

namespace App\Console\Commands;

use App\Models\Offer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class PruneOrphanOffers extends Command
{
    protected $signature = 'offers:prune-orphans
                            {--hours=48 : Delete draft offers older than this many hours with no module}
                            {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Delete draft offers that have no linked inventory module (orphans from failed 2-step create)';

    public function handle(): int
    {
        $hours   = (int) $this->option('hours');
        $dryRun  = (bool) $this->option('dry-run');
        $cutoff  = now()->subHours($hours);

        $query = Offer::query()
            ->where('status', Offer::STATUS_DRAFT)
            ->where('created_at', '<', $cutoff)
            ->whereIn('type', ['flight', 'hotel', 'transfer', 'car', 'excursion'])
            ->where(function (Builder $q): void {
                $q->where(fn (Builder $q) => $q->where('type', 'flight')->whereDoesntHave('flight'))
                  ->orWhere(fn (Builder $q) => $q->where('type', 'hotel')->whereDoesntHave('hotel'))
                  ->orWhere(fn (Builder $q) => $q->where('type', 'transfer')->whereDoesntHave('transfer'))
                  ->orWhere(fn (Builder $q) => $q->where('type', 'car')->whereDoesntHave('car'))
                  ->orWhere(fn (Builder $q) => $q->where('type', 'excursion')->whereDoesntHave('excursion'));
            });

        $orphans = $query->get(['id', 'type', 'company_id', 'created_at']);

        if ($orphans->isEmpty()) {
            $this->info('No orphan offers found.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'type', 'company_id', 'created_at'],
            $orphans->map(fn (Offer $o) => [
                $o->id, $o->type, $o->company_id, $o->created_at,
            ])
        );

        if ($dryRun) {
            $this->warn("[dry-run] Would delete {$orphans->count()} orphan offer(s). No changes made.");

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Deleted {$deleted} orphan offer(s) older than {$hours}h.");

        return self::SUCCESS;
    }
}
