<?php

namespace App\Services\Marketplace\Publishing;

use App\Models\MarketplaceAccount;
use App\Models\Part;

class OvokoPublishAdapter extends BaseMarketplacePublishAdapter
{
    protected function channel(): string { return 'ovoko'; }
    protected function marketplace(): string { return 'ovoko'; }
    protected function accountCode(): string { return 'ovoko_main'; }

    protected function performLivePublish(Part $part, array $readiness, array $payload, ?MarketplaceAccount $account): array
    {
        return ['ok' => false, 'status' => 'not_configured', 'action' => 'publishListing', 'error' => 'Ovoko live publish endpoint is not configured in the existing project client. Existing Ovoko client exposes read-only/import endpoints (/v2/get/parts, /get/categories) only; add documented write endpoint and response id mapping before enabling live publish.', 'request_summary' => $this->requestSummary($payload), 'response_summary' => ['missing' => ['documented Ovoko write endpoint', 'write client method', 'external listing id response mapping']]];
    }
}
