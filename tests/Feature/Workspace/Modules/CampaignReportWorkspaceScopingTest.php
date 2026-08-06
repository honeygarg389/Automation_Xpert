<?php

namespace Tests\Feature\Workspace\Modules;

use App\Modules\Broadcasting\Models\Campaign;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 1c — Core: Client controllers (1 resolution site, 1 §G-1b authorization site).
 *
 * CampaignReportController is a single-action controller whose one resolution
 * feeds one abort_if(). Its comment claimed "current_workspace_id wins over the
 * user's home workspace_id" — the intent was right, the effect was not:
 * current_workspace_id does not exist, so it always resolved to HOME and the
 * report authorised against the wrong workspace whenever a user had switched.
 *
 * Campaign binds by uuid, so route() is given the model rather than an id.
 */
class CampaignReportWorkspaceScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::flush();
    }

    protected function tearDown(): void
    {
        WorkspaceContext::flush();
        parent::tearDown();
    }

    private function makeCampaign(int $workspaceId, string $name): Campaign
    {
        return Campaign::create([
            'workspace_id' => $workspaceId,
            'name' => $name,
            'channel' => 'sms',
            'audience_type' => 'segment',
            'status' => 'completed',
        ]);
    }

    #[Test]
    public function viewing_another_tenants_campaign_report_is_blocked(): void
    {
        ['user' => $user] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign-report@example.com']);
        $campaign = $this->makeCampaign($foreign->id, 'ForeignCampaign');

        $this->actingAs($user)
            ->get(route('client.reports.campaigns.show', $campaign))
            ->assertForbidden();
    }

    /**
     * POSITIVE CONTROL. A 403 above proves nothing alone — it is equally
     * consistent with the report refusing everyone.
     */
    #[Test]
    public function viewing_a_campaign_report_in_the_home_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $campaign = $this->makeCampaign($home->id, 'MyCampaign');

        $this->actingAs($user)
            ->get(route('client.reports.campaigns.show', $campaign))
            ->assertOk();
    }

    /** §G-1b: after switching, the switched-into workspace's report is reachable. */
    #[Test]
    public function viewing_a_campaign_report_in_the_switched_workspace_succeeds(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();
        $campaign = $this->makeCampaign($other->id, 'OtherCampaign');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.reports.campaigns.show', $campaign))
            ->assertOk();
    }

    /** The converse: once switched away, the home campaign's report is out of scope. */
    #[Test]
    public function after_switching_a_home_workspace_campaign_report_is_out_of_scope(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $campaign = $this->makeCampaign($home->id, 'HomeCampaign');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.reports.campaigns.show', $campaign))
            ->assertForbidden();
    }
}
