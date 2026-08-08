<?php

namespace App\Modules\Leads\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Support\Concerns\MasksDemoData;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    /**
     * Phase 0, slice 5. The first application model to carry the workspace
     * scope — the canary, chosen because Leads is one small module with three
     * call sites.
     *
     * ⚠️ It exposed a real defect on the way in. See BUG-020: the scraper's
     * write path keys `updateOrCreate` on `google_place_id` ALONE, and that
     * column is GLOBALLY unique — so two workspaces scraping the same business
     * collide. The scope changes that failure from "silently steal the other
     * tenant's lead" to "the scrape job dies on a unique violation". Currently
     * unreachable because the scraper has never worked (BUG-007), which is the
     * only reason it is not live.
     */
    use BelongsToWorkspace, HasFactory, MasksDemoData;

    protected static function newFactory()
    {
        return LeadFactory::new();
    }

    protected $table = 'leads';

    /**
     * Scraped-lead PII masked in demo mode (see App\Support\Concerns\MasksDemoData).
     *
     * @return array<string, string>
     */
    protected function demoMask(): array
    {
        return [
            'name' => 'name',
            'phone' => 'phone',
            'email' => 'email',
            'website' => 'redact',
            'address' => 'redact',
            'lat' => 'null',
            'lng' => 'null',
        ];
    }

    protected $fillable = ['workspace_id', 'name', 'phone', 'email', 'website', 'address', 'city', 'country', 'lat', 'lng', 'category', 'rating', 'review_count', 'google_place_id', 'whatsapp_status', 'pushed_to_contacts'];

    protected function casts(): array
    {
        return [
            'pushed_to_contacts' => 'boolean',
            'rating' => 'float',
        ];
    }

    public function scrapeJob()
    {
        return $this->belongsTo(LeadScrapeJob::class, 'workspace_id', 'workspace_id');
    }
}
