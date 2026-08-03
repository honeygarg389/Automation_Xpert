<?php

namespace App\Modules\Leads\Services;

use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadScrapeJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Scrapes Google Places API (Text Search / Nearby Search) and populates the leads table.
 * Uses the system-level Google Places API key from CredentialResolver (workspace → system).
 */
class GooglePlacesScraper
{
    private const PLACES_URL = 'https://maps.googleapis.com/maps/api/place/textsearch/json';

    private const DETAILS_URL = 'https://maps.googleapis.com/maps/api/place/details/json';

    public function __construct(private readonly CredentialResolver $credentials) {}

    public function run(LeadScrapeJob $job): void
    {
        $job->update(['status' => 'running', 'started_at' => now()]);

        try {
            $creds = $this->credentials->generic('google_places');
            $apiKey = $creds->get('api_key');
            if (! $apiKey) {
                throw new \RuntimeException('Google Places API key not configured.');
            }

            $count = 0;
            $query = "{$job->keyword} in {$job->location}";
            $pageToken = null;

            do {
                $params = ['query' => $query, 'key' => $apiKey];
                if ($pageToken) {
                    $params['pagetoken'] = $pageToken;
                }

                $res = Http::get(self::PLACES_URL, $params)->json();
                $places = $res['results'] ?? [];

                foreach ($places as $place) {
                    $count += $this->upsertPlace($job->workspace_id, $place, $apiKey);
                }

                $pageToken = $res['next_page_token'] ?? null;
                if ($pageToken) {
                    sleep(2); // Google requires a short delay before using next_page_token
                }
            } while ($pageToken);

            $job->update(['status' => 'done', 'leads_found' => $count, 'completed_at' => now()]);
            if ($count > 0) {
                UsageMeter::track($job->workspace_id, 'lead_credits', $count);
            }
        } catch (\Throwable $e) {
            Log::error('GooglePlacesScraper error: '.$e->getMessage());
            $job->update(['status' => 'failed', 'error' => $e->getMessage(), 'completed_at' => now()]);
        }
    }

    private function upsertPlace(int $workspaceId, array $place, string $apiKey): int
    {
        $placeId = $place['place_id'] ?? null;
        if (! $placeId) {
            return 0;
        }

        // Fetch details for phone number
        $details = Http::get(self::DETAILS_URL, [
            'place_id' => $placeId,
            'fields' => 'formatted_phone_number,website,url',
            'key' => $apiKey,
        ])->json()['result'] ?? [];

        $geometry = $place['geometry']['location'] ?? [];

        Lead::updateOrCreate(
            ['google_place_id' => $placeId],
            [
                'workspace_id' => $workspaceId,
                'name' => $place['name'] ?? null,
                'phone' => $details['formatted_phone_number'] ?? null,
                'website' => $details['website'] ?? null,
                'address' => $place['formatted_address'] ?? null,
                'category' => implode(', ', array_slice($place['types'] ?? [], 0, 3)),
                'rating' => $place['rating'] ?? null,
                'review_count' => $place['user_ratings_total'] ?? 0,
                'lat' => $geometry['lat'] ?? null,
                'lng' => $geometry['lng'] ?? null,
            ]
        );

        return 1;
    }
}
