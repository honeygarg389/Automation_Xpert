<?php

namespace Tests\Unit\Flows;

use App\Modules\Flows\Services\FlowSubmissionContactEnricher;
use App\Modules\Shared\Models\Contact;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlowSubmissionContactEnricherTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_fills_only_missing_contact_fields_and_creates_for_a_new_phone(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $existing = WorkspaceContext::for($workspace->id, fn (): Contact => Contact::create([
            'workspace_id' => $workspace->id,
            'phone_e164' => '+15555550101',
            'first_name' => 'Established',
            'email' => null,
            'source' => 'manual',
        ]));

        $enricher = app(FlowSubmissionContactEnricher::class);
        $enriched = WorkspaceContext::for($workspace->id, fn () => $enricher->enrich($workspace->id, [
            'phone' => '+15555550101',
            'first_name' => 'Untrusted form value',
            'email' => 'new-email@example.test',
        ]));

        $this->assertSame($existing->id, $enriched?->id);
        $this->assertSame('Established', $enriched?->fresh()->first_name);
        $this->assertSame('new-email@example.test', $enriched?->fresh()->email);

        $created = WorkspaceContext::for($workspace->id, fn () => $enricher->enrich($workspace->id, [
            'phone' => '+15555550102',
            'first_name' => 'Brand New',
        ]));

        $this->assertNotNull($created);
        $this->assertSame('+15555550102', $created->phone_e164);
        $this->assertSame('Brand New', $created->first_name);
    }
}
