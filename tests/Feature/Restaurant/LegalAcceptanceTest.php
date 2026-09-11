<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Exceptions\ImmutableLegalAcceptanceException;
use App\Modules\Restaurant\Exceptions\NoPublishedLegalDocumentException;
use App\Modules\Restaurant\Models\LegalAcceptance;
use App\Modules\Restaurant\Models\LegalDocumentVersion;
use App\Modules\Restaurant\Services\LegalDocumentPublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Task 2 — LegalAcceptance::currentFor() must invalidate on version
 * supersession: accepting v1 does not satisfy the requirement once v2 is
 * published, even though "an acceptance exists" remains true.
 */
class LegalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function accepting_v1_is_satisfied_until_v2_is_published_then_becomes_unsatisfied(): void
    {
        ['workspace' => $workspace, 'user' => $user] = $this->createWorkspaceContext();
        $service = app(LegalDocumentPublishingService::class);

        $v1 = LegalDocumentVersion::factory()->create([
            'document_type' => LegalDocumentVersion::TYPE_TERMS,
            'version' => 'v1',
        ]);
        $service->publish($v1);

        LegalAcceptance::create([
            'workspace_id' => $workspace->id,
            'document_type' => LegalDocumentVersion::TYPE_TERMS,
            'legal_document_version_id' => $v1->id,
            'document_version' => $v1->version,
            'content_sha256' => $v1->content_sha256,
            'accepted_by_user_id' => $user->id,
            'accepted_at' => now(),
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $current = LegalAcceptance::currentFor($workspace->id, LegalDocumentVersion::TYPE_TERMS);
        $this->assertNotNull($current, 'Accepting the currently published version must be satisfied.');
        $this->assertSame($v1->id, $current->legal_document_version_id);

        $v2 = LegalDocumentVersion::factory()->create([
            'document_type' => LegalDocumentVersion::TYPE_TERMS,
            'version' => 'v2',
        ]);
        $service->publish($v2);

        $this->assertNull(
            LegalAcceptance::currentFor($workspace->id, LegalDocumentVersion::TYPE_TERMS),
            'Publishing v2 must invalidate the v1 acceptance — an acceptance existing is not enough.'
        );

        LegalAcceptance::create([
            'workspace_id' => $workspace->id,
            'document_type' => LegalDocumentVersion::TYPE_TERMS,
            'legal_document_version_id' => $v2->id,
            'document_version' => $v2->version,
            'content_sha256' => $v2->content_sha256,
            'accepted_by_user_id' => $user->id,
            'accepted_at' => now(),
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $current = LegalAcceptance::currentFor($workspace->id, LegalDocumentVersion::TYPE_TERMS);
        $this->assertNotNull($current, 'Accepting v2 must satisfy the requirement again.');
        $this->assertSame($v2->id, $current->legal_document_version_id);
    }

    #[Test]
    public function current_for_is_null_when_the_document_type_has_never_been_published(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $this->assertNull(LegalAcceptance::currentFor($workspace->id, LegalDocumentVersion::TYPE_DPA));
    }

    #[Test]
    public function legal_acceptances_are_workspace_scoped(): void
    {
        ['workspace' => $workspaceA, 'user' => $userA] = $this->createWorkspaceContext();
        ['workspace' => $workspaceB] = $this->createWorkspaceContext();

        $v1 = LegalDocumentVersion::factory()->create(['document_type' => LegalDocumentVersion::TYPE_TERMS]);
        app(LegalDocumentPublishingService::class)->publish($v1);

        LegalAcceptance::create([
            'workspace_id' => $workspaceA->id,
            'document_type' => LegalDocumentVersion::TYPE_TERMS,
            'legal_document_version_id' => $v1->id,
            'document_version' => $v1->version,
            'content_sha256' => $v1->content_sha256,
            'accepted_by_user_id' => $userA->id,
            'accepted_at' => now(),
        ]);

        $this->assertNotNull(LegalAcceptance::currentFor($workspaceA->id, LegalDocumentVersion::TYPE_TERMS));
        $this->assertNull(LegalAcceptance::currentFor($workspaceB->id, LegalDocumentVersion::TYPE_TERMS),
            'Workspace B must not see workspace A\'s acceptance.');
    }

    // ══ recordFor() — guarded creation (pre-commit addendum) ═════════════

    #[Test]
    public function record_for_throws_when_nothing_has_ever_been_published(): void
    {
        ['workspace' => $workspace, 'user' => $user] = $this->createWorkspaceContext();

        $this->expectException(NoPublishedLegalDocumentException::class);

        LegalAcceptance::recordFor($workspace->id, LegalDocumentVersion::TYPE_TERMS, $user->id);
    }

    #[Test]
    public function record_for_throws_when_only_a_draft_exists(): void
    {
        ['workspace' => $workspace, 'user' => $user] = $this->createWorkspaceContext();

        LegalDocumentVersion::factory()->create([
            'document_type' => LegalDocumentVersion::TYPE_TERMS,
            'status' => LegalDocumentVersion::STATUS_DRAFT,
        ]);

        $this->expectException(NoPublishedLegalDocumentException::class);

        LegalAcceptance::recordFor($workspace->id, LegalDocumentVersion::TYPE_TERMS, $user->id);
    }

    #[Test]
    public function record_for_succeeds_against_a_published_version_and_copies_content_sha256(): void
    {
        ['workspace' => $workspace, 'user' => $user] = $this->createWorkspaceContext();

        $published = LegalDocumentVersion::factory()->create([
            'document_type' => LegalDocumentVersion::TYPE_TERMS,
            'content_body' => 'the exact accepted text',
        ]);
        app(LegalDocumentPublishingService::class)->publish($published);

        $acceptance = LegalAcceptance::recordFor(
            $workspace->id,
            LegalDocumentVersion::TYPE_TERMS,
            $user->id,
            ['source' => 'signup_form'],
        );

        $this->assertSame($workspace->id, $acceptance->workspace_id);
        $this->assertSame($published->id, $acceptance->legal_document_version_id);
        $this->assertSame($published->version, $acceptance->document_version);
        $this->assertSame(hash('sha256', 'the exact accepted text'), $acceptance->content_sha256);
        $this->assertSame($published->content_sha256, $acceptance->content_sha256);
        $this->assertSame(['source' => 'signup_form'], $acceptance->evidence);
        $this->assertSame($user->id, $acceptance->accepted_by_user_id);
        $this->assertNotNull($acceptance->accepted_at);

        $this->assertNotNull(LegalAcceptance::currentFor($workspace->id, LegalDocumentVersion::TYPE_TERMS));
    }

    // ══ Append-only after creation (pre-commit addendum) ═════════════════

    /**
     * Creation itself must be unaffected by the updating() guard — it fires
     * only on UPDATE, never on the initial INSERT. Then, once persisted,
     * updating ANY protected field must throw and leave the row untouched.
     */
    #[Test]
    public function creation_succeeds_but_updating_a_persisted_acceptance_throws_and_leaves_it_unchanged(): void
    {
        ['workspace' => $workspace, 'user' => $user] = $this->createWorkspaceContext();

        $published = LegalDocumentVersion::factory()->create(['document_type' => LegalDocumentVersion::TYPE_TERMS]);
        app(LegalDocumentPublishingService::class)->publish($published);

        // Creation succeeds — unaffected by the guard.
        $acceptance = LegalAcceptance::recordFor($workspace->id, LegalDocumentVersion::TYPE_TERMS, $user->id);
        $originalDocumentVersion = $acceptance->document_version;
        $originalContentSha256 = $acceptance->content_sha256;

        try {
            $acceptance->update(['document_version' => 'tampered-version']);
            $this->fail('Expected ImmutableLegalAcceptanceException.');
        } catch (ImmutableLegalAcceptanceException $e) {
            $this->assertStringContainsString('document_version', $e->getMessage());
        }

        try {
            $acceptance->fresh()->update(['content_sha256' => str_repeat('f', 64)]);
            $this->fail('Expected ImmutableLegalAcceptanceException.');
        } catch (ImmutableLegalAcceptanceException $e) {
            $this->assertStringContainsString('content_sha256', $e->getMessage());
        }

        $fresh = $acceptance->fresh();
        $this->assertSame($originalDocumentVersion, $fresh->document_version);
        $this->assertSame($originalContentSha256, $fresh->content_sha256);
    }
}
