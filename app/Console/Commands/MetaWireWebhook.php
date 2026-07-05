<?php

namespace App\Console\Commands;

use App\Services\Social\MetaMessengerService;
use Illuminate\Console\Command;

/**
 * Point the Meta app's webhook at our /api/webhooks/meta endpoint and subscribe
 * the page to the messaging fields — using the credentials in the server .env
 * (META_APP_ID/APP_SECRET/VERIFY_TOKEN/PAGE_ID/PAGE_ACCESS_TOKEN).
 *
 * Run by deploy.yml after config:cache so the social inbox is wired without
 * anyone touching the Meta developer console. Idempotent: re-running just
 * re-asserts the same subscription. Always exits 0 (a Graph API hiccup must not
 * fail the deploy) — inspect the printed summary / logs for the per-step result.
 */
class MetaWireWebhook extends Command
{
    protected $signature = 'meta:wire-webhook';

    protected $description = 'Point the Meta webhook at this server and subscribe the page (idempotent)';

    public function handle(MetaMessengerService $meta): int
    {
        $result = $meta->wireWebhook();
        foreach ($result as $key => $value) {
            $this->line("  {$key}: ".(is_string($value) ? $value : json_encode($value)));
        }

        return self::SUCCESS;
    }
}
