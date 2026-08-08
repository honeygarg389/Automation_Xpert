<?php

namespace App\Services;

use App\Models\OnboardingStep;
use App\Models\User;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Message;
use App\Modules\Social\Models\SocialAccount;
use App\Support\WorkspaceContext;

class OnboardingService
{
    public const STEPS = [
        'verify_email' => 'Verify your email address',
        'choose_plan' => 'Choose a plan',
        'connect_first_channel' => 'Connect your first messaging channel',
        'import_first_contacts' => 'Import or add your first contacts',
        'send_first_message' => 'Send your first message',
        'train_first_chatbot' => 'Train an AI chatbot',
        'connect_first_social_account' => 'Connect a social media account',
    ];

    /**
     * @param  ?int  $workspaceId  The workspace to report progress for. Passed
     *                             explicitly rather than derived from $user: a
     *                             user has a home workspace but not a current
     *                             one, so deriving it reported home progress to
     *                             a user working in another workspace.
     */
    public function getProgress(User $user, ?int $workspaceId): array
    {
        // Phase 0, slice 6. This service is GIVEN a workspace (the 1c Option-B
        // ruling: carry it explicitly) and its detections then filter on it
        // directly — `Contact::where('workspace_id', $workspaceId)->exists()`.
        //
        // Under the scope that predicate is ANDed with the resolved context, so
        // when the two disagree — or when there is no context, as in a queued
        // export or a direct service call — every milestone silently reports
        // FALSE. Hazard H-2 exactly: harmless when they agree, silently empty
        // when they do not, and "no milestones completed" looks like a plausible
        // answer rather than a bug.
        //
        // Establishing the context it was given makes the explicit filters agree
        // with the scope instead of fighting it.
        if ($workspaceId !== null) {
            return WorkspaceContext::for($workspaceId, fn () => $this->detect($user, $workspaceId));
        }

        return $this->detect($user, $workspaceId);
    }

    /** @return array<string, mixed> */
    private function detect(User $user, ?int $workspaceId): array
    {

        // BUG-009: read per workspace, to match how markStep() now writes.
        $completed = OnboardingStep::where('user_id', $user->id)
            ->where('workspace_id', $workspaceId)
            ->where('completed', true)
            ->pluck('step')
            ->toArray();

        $steps = [];
        foreach (self::STEPS as $key => $label) {
            $isCompleted = $this->isCompleted($user, $workspaceId, $key, $completed);
            $steps[] = [
                'key' => $key,
                'label' => $label,
                'completed' => $isCompleted,
            ];

            // Persist auto-detected completions so they survive future checks
            if ($isCompleted && ! in_array($key, $completed, true)) {
                $this->complete($user, $workspaceId, $key);
                $completed[] = $key;
            }
        }

        $doneCount = count(array_filter($steps, fn ($s) => $s['completed']));
        $totalCount = count($steps);

        // Next pending step for the dashboard nudge
        $nextStep = null;
        foreach ($steps as $step) {
            if (! $step['completed']) {
                $nextStep = $step;
                break;
            }
        }

        return [
            'steps' => $steps,
            'percent' => $totalCount > 0 ? (int) round(($doneCount / $totalCount) * 100) : 0,
            'done' => $doneCount,
            'total' => $totalCount,
            'is_complete' => $doneCount === $totalCount,
            'next_step' => $nextStep,
        ];
    }

    private function isCompleted(User $user, ?int $workspaceId, string $step, array $manuallyCompleted): bool
    {
        if (in_array($step, $manuallyCompleted, true)) {
            return true;
        }

        return match ($step) {
            'verify_email' => $user->hasVerifiedEmail(),
            'choose_plan' => $user->effectiveSubscription() !== null,

            'connect_first_channel' => $workspaceId !== null &&
                ChannelAccount::where('workspace_id', $workspaceId)->exists(),

            'import_first_contacts' => $workspaceId !== null &&
                Contact::where('workspace_id', $workspaceId)->exists(),

            'send_first_message' => $workspaceId !== null &&
                Message::whereHas('conversation', function ($q) use ($workspaceId) {
                    $q->where('workspace_id', $workspaceId);
                })->where('direction', 'out')->exists(),

            'train_first_chatbot' => $workspaceId !== null &&
                AiChatbot::where('workspace_id', $workspaceId)
                    ->where('enabled', true)
                    ->exists(),

            'connect_first_social_account' => $workspaceId !== null &&
                SocialAccount::where('workspace_id', $workspaceId)->exists(),

            default => false,
        };
    }

    /**
     * Mark a step as complete.
     *
     * If $verify is true (default), the step is only recorded when the real-world
     * condition confirms it is actually done — preventing a user from spoofing
     * progress by calling this endpoint with an arbitrary step key.
     */
    /** @param  ?int  $workspaceId  See getProgress(); passed explicitly. */
    public function markStep(User $user, ?int $workspaceId, string $step, bool $verify = true): bool
    {
        if (! array_key_exists($step, self::STEPS)) {
            return false;
        }

        if ($verify && ! $this->isCompleted($user, $workspaceId, $step, [])) {
            return false;
        }

        // Phase 0 slice 9: workspace_id is NOT NULL now. Without a workspace there
        // is nothing meaningful to record — progress is per workspace — so refuse
        // rather than insert a null and 500. false is the existing "not marked"
        // signal, and every caller already handles it.
        if ($workspaceId === null) {
            return false;
        }

        // BUG-009, THE FIX. This used to key on (user_id, step) alone, so a step
        // completed in ONE workspace marked it complete in every workspace that
        // user could reach — while getProgress() DETECTED completion per
        // workspace. Record and detection disagreed.
        //
        // The key now includes the workspace, matching the widened UNIQUE
        // (user_id, workspace_id, step).
        OnboardingStep::updateOrCreate(
            ['user_id' => $user->id, 'workspace_id' => $workspaceId, 'step' => $step],
            ['completed' => true, 'completed_at' => now()]
        );

        return true;
    }

    /**
     * @deprecated Use markStep(), which takes the workspace explicitly.
     *
     * Kept for signature compatibility; it has no callers. The workspace is
     * passed through so this shim cannot become a second way to resolve one —
     * that is the "one concept, two definitions" trap CLAUDE.md warns about.
     */
    public function complete(User $user, ?int $workspaceId, string $step): void
    {
        $this->markStep($user, $workspaceId, $step, false);
    }
}
