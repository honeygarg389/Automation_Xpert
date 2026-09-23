<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Support\DriverCapabilities;
use App\Modules\Social\Support\NetworkCapabilities;
use App\Rules\ValidTimezone;
use App\Support\TimezoneNormalizer;
use App\Support\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SocialPostController extends Controller
{
    private function workspaceId(Request $request): int
    {
        return (int) (WorkspaceContext::id() ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);

        $status = $request->query('status');
        $network = $request->query('network');

        $accounts = SocialAccount::where('workspace_id', $wid)
            ->where('active', true)
            ->get(['id', 'network', 'name', 'picture_url']);

        // Collect account IDs for the requested network filter
        $networkAccountIds = $network
            ? $accounts->where('network', $network)->pluck('id')->map(fn ($id) => (string) $id)
            : collect();

        $query = SocialPost::where('workspace_id', $wid)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($network && $networkAccountIds->isNotEmpty(), function ($q) use ($networkAccountIds) {
                $q->where(function ($inner) use ($networkAccountIds) {
                    foreach ($networkAccountIds as $aid) {
                        $inner->orWhereJsonContains('target_accounts', $aid)
                            ->orWhereJsonContains('target_accounts', (int) $aid);
                    }
                });
            })
            ->orderByDesc('created_at');

        $posts = $query->paginate(20)->withQueryString();

        return Inertia::render('Social/Posts/Index', [
            'posts' => $posts,
            'accounts' => $accounts,
            // AiPlannerModal is rendered from this page and needs the limits.
            'networkCapabilities' => NetworkCapabilities::forFrontend(),
            'driverCapabilities' => DriverCapabilities::forFrontend(),
            'filters' => ['status' => $status, 'network' => $network],
        ]);
    }

    public function composer(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $accounts = SocialAccount::where('workspace_id', $wid)->where('active', true)->get(['id', 'network', 'name', 'picture_url']);

        return Inertia::render('Social/Composer', [
            'accounts' => $accounts,
            'networkCapabilities' => NetworkCapabilities::forFrontend(),
            'driverCapabilities' => DriverCapabilities::forFrontend(),
        ]);
    }

    public function calendar(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $month = $request->query('month', now()->format('Y-m'));
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month), 422, 'Invalid month format.');

        $filterStatus = $request->query('status');
        $filterAccountId = $request->query('account_id');
        $filterNetwork = $request->query('network');

        $userTz = $request->user()?->timezone ?? 'Asia/Dhaka';
        try {
            $tz = new \DateTimeZone($userTz);
        } catch (\Exception) {
            $tz = new \DateTimeZone('Asia/Dhaka');
        }

        [$year, $mon] = explode('-', $month);
        $start = Carbon::createFromDate((int) $year, (int) $mon, 1, $tz)->startOfMonth()->utc();
        $end = Carbon::createFromDate((int) $year, (int) $mon, 1, $tz)->endOfMonth()->utc();

        $accounts = SocialAccount::where('workspace_id', $wid)
            ->where('active', true)
            ->get(['id', 'network', 'name', 'picture_url']);

        // Resolve account IDs for a network filter
        $networkAccountIds = $filterNetwork
            ? $accounts->where('network', $filterNetwork)->pluck('id')->map(fn ($id) => (string) $id)
            : collect();

        $posts = SocialPost::where('workspace_id', $wid)
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$start, $end])
            ->when($filterStatus, fn ($q) => $q->where('status', $filterStatus))
            ->when($filterAccountId, function ($q) use ($filterAccountId) {
                $q->where(function ($inner) use ($filterAccountId) {
                    $inner->orWhereJsonContains('target_accounts', $filterAccountId)
                        ->orWhereJsonContains('target_accounts', (int) $filterAccountId);
                });
            })
            ->when($filterNetwork && $networkAccountIds->isNotEmpty(), function ($q) use ($networkAccountIds) {
                $q->where(function ($inner) use ($networkAccountIds) {
                    foreach ($networkAccountIds as $aid) {
                        $inner->orWhereJsonContains('target_accounts', $aid)
                            ->orWhereJsonContains('target_accounts', (int) $aid);
                    }
                });
            })
            ->get(['id', 'title', 'status', 'scheduled_at', 'timezone', 'target_accounts']);

        return Inertia::render('Social/Calendar', [
            'posts' => $posts,
            'month' => $month,
            'accounts' => $accounts,
            'filters' => [
                'status' => $filterStatus,
                'account_id' => $filterAccountId,
                'network' => $filterNetwork,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        TimezoneNormalizer::normalizeRequest($request);
        $wid = $this->workspaceId($request);
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:256'],
            'body' => ['required', 'string', 'max:5000'],
            'media_urls' => ['nullable', 'array'],
            'media_urls.*' => ['nullable', 'url', 'max:2048'],
            'target_accounts' => ['required', 'array', 'min:1'],
            'target_accounts.*' => ['integer'],
            'post_type' => ['nullable', 'string', 'in:image,video,text'],
            'media_type' => ['nullable', 'string', 'in:single,carousel'],
            'scheduled_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64', new ValidTimezone],
        ]);

        // Ensure every requested account belongs to this workspace (cross-workspace IDOR guard).
        $requestedIds = collect($validated['target_accounts'])->map(fn ($id) => (int) $id);
        $ownedCount = SocialAccount::where('workspace_id', $wid)
            ->whereIn('id', $requestedIds)
            ->count();
        if ($ownedCount !== $requestedIds->count()) {
            throw ValidationException::withMessages([
                'target_accounts' => ['One or more selected accounts do not belong to your workspace.'],
            ]);
        }

        $this->assertBodyFitsEveryNetwork($validated['body'], $requestedIds);

        $validated['post_type'] = $validated['post_type'] ?? DriverCapabilities::TYPE_TEXT;
        $validated['media_type'] = $validated['post_type'] === DriverCapabilities::TYPE_IMAGE
            ? ($validated['media_type'] ?? DriverCapabilities::MEDIA_SINGLE)
            : null;

        $this->assertPostTypeIsDeliverable(
            $validated['post_type'],
            $validated['media_type'],
            $requestedIds,
            array_values(array_filter($validated['media_urls'] ?? [], fn ($v) => $v !== null && $v !== ''))
        );

        // scheduled_at arrives as UTC ISO from the frontend (already converted).
        // Allow a 30-second buffer to account for form submission latency.
        if (! empty($validated['scheduled_at']) && now()->subSeconds(30)->gt($validated['scheduled_at'])) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The scheduled time must be in the future.'],
            ]);
        }

        // Strip empty media URL entries before persisting.
        $validated['media_urls'] = array_values(array_filter($validated['media_urls'] ?? [], fn ($v) => $v !== null && $v !== ''));

        // Laravel's validate() OMITS an absent nullable key rather than returning
        // it as null, so a request that simply does not send scheduled_at (the
        // normal case for an unscheduled post) left every read below undefined.
        // Normalise once here instead of guarding each use.
        $validated['scheduled_at'] = $validated['scheduled_at'] ?? null;

        $post = SocialPost::create(array_merge($validated, [
            'workspace_id' => $wid,
            'status' => $validated['scheduled_at'] ? 'scheduled' : 'draft',
        ]));

        if (! $validated['scheduled_at']) {
            PublishSocialPostJob::dispatch($post->id)->onQueue('social');
            $post->update(['status' => 'publishing']);
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'post_id' => $post->id]);
        }

        return back()->with('success', 'Post '.($validated['scheduled_at'] ? 'scheduled' : 'queued for publishing').'.');
    }

    public function edit(Request $request, SocialPost $post): Response
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);
        abort_if(in_array($post->status, ['publishing', 'published']), 403, 'Cannot edit a post that is already published.');

        $wid = $this->workspaceId($request);
        $accounts = SocialAccount::where('workspace_id', $wid)->where('active', true)->get(['id', 'network', 'name', 'picture_url']);

        return Inertia::render('Social/Posts/Edit', [
            'post' => $post,
            'accounts' => $accounts,
            'networkCapabilities' => NetworkCapabilities::forFrontend(),
            'driverCapabilities' => DriverCapabilities::forFrontend(),
        ]);
    }

    public function update(Request $request, SocialPost $post): RedirectResponse
    {
        TimezoneNormalizer::normalizeRequest($request);
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);
        abort_if(in_array($post->status, ['publishing', 'published']), 403, 'Cannot edit a post that is already published or being published.');

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:256'],
            'body' => ['required', 'string', 'max:5000'],
            'media_urls' => ['nullable', 'array'],
            'media_urls.*' => ['nullable', 'url', 'max:2048'],
            'target_accounts' => ['required', 'array', 'min:1'],
            'target_accounts.*' => ['integer'],
            'post_type' => ['nullable', 'string', 'in:image,video,text'],
            'media_type' => ['nullable', 'string', 'in:single,carousel'],
            'scheduled_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64', new ValidTimezone],
        ]);

        // ⚠️ THE SAME CROSS-WORKSPACE IDOR GUARD store() HAS. It was absent from
        // this path only: `target_accounts.*` is validated as `integer`, so any
        // account id in the database was accepted and written straight to the
        // post by $post->update() below.
        //
        // Nothing was ever POSTED to another workspace's account — SocialPublisher
        // re-scopes to the post's own workspace before publishing, so a foreign id
        // is silently dropped. That second check is the only thing that kept this
        // from being cross-tenant publishing, which is exactly why this one should
        // not be missing: it was the sole remaining layer.
        //
        // Same query shape as store() deliberately — two spellings of one rule is
        // how the versions drift apart.
        $requestedIds = collect($validated['target_accounts'])->map(fn ($id) => (int) $id);
        $ownedCount = SocialAccount::where('workspace_id', $this->workspaceId($request))
            ->whereIn('id', $requestedIds)
            ->count();
        if ($ownedCount !== $requestedIds->count()) {
            throw ValidationException::withMessages([
                'target_accounts' => ['One or more selected accounts do not belong to your workspace.'],
            ]);
        }

        // Ownership is checked BEFORE the char-limit rule, matching store().
        // assertBodyFitsEveryNetwork() resolves ids -> networks with an UNSCOPED
        // SocialAccount query, so running it first would let a foreign account id
        // shape the error message ("... but twitter allows 280") and turn
        // validation into an oracle for which network an arbitrary id belongs to.
        // A foreign id has to die at the guard above, before anything reads it.
        //
        // Reuses $requestedIds rather than recomputing collect(), also per store().
        $this->assertBodyFitsEveryNetwork($validated['body'], $requestedIds);

        // ⚠️ Falls back to the post's CURRENT type, not to 'text'. An edit form
        // that does not resend post_type must not silently downgrade a carousel
        // to a text post — the same absent-nullable-key trap that made
        // scheduled_at throw "Undefined array key" here before.
        $validated['post_type'] = $validated['post_type'] ?? $post->post_type ?? DriverCapabilities::TYPE_TEXT;
        $validated['media_type'] = $validated['post_type'] === DriverCapabilities::TYPE_IMAGE
            ? ($validated['media_type'] ?? $post->media_type ?? DriverCapabilities::MEDIA_SINGLE)
            : null;

        $this->assertPostTypeIsDeliverable(
            $validated['post_type'],
            $validated['media_type'],
            $requestedIds,
            array_values(array_filter($validated['media_urls'] ?? [], fn ($v) => $v !== null && $v !== ''))
        );

        if (! empty($validated['scheduled_at']) && now()->subSeconds(30)->gt($validated['scheduled_at'])) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The scheduled time must be in the future.'],
            ]);
        }

        $validated['media_urls'] = array_values(array_filter($validated['media_urls'] ?? [], fn ($v) => $v !== null && $v !== ''));

        // See store(): validate() omits absent nullable keys, so an edit that
        // does not resend scheduled_at previously threw "Undefined array key".
        $validated['scheduled_at'] = $validated['scheduled_at'] ?? null;
        $validated['status'] = $validated['scheduled_at'] ? 'scheduled' : 'draft';

        $post->update($validated);

        return redirect()->route('client.social.posts.index')->with('success', 'Post updated successfully.');
    }

    public function publishNow(Request $request, SocialPost $post): RedirectResponse
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);
        abort_if($post->status === 'publishing', 422, 'Post is already being published.');
        abort_if($post->status === 'published', 422, 'Post is already published.');

        $post->update(['scheduled_at' => null, 'status' => 'publishing']);
        PublishSocialPostJob::dispatch($post->id)->onQueue('social');

        return back()->with('success', 'Post queued for immediate publishing.');
    }

    public function cancel(Request $request, SocialPost $post): RedirectResponse
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);
        abort_unless($post->status === 'scheduled', 422, 'Only scheduled posts can be cancelled.');

        $post->update(['status' => 'draft', 'scheduled_at' => null]);

        return back()->with('success', 'Scheduled post cancelled and moved to drafts.');
    }

    public function destroy(Request $request, SocialPost $post): RedirectResponse
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);
        abort_if($post->status === 'publishing', 422, 'Cannot delete a post that is currently being published.');
        $post->delete();

        return back()->with('success', 'Post deleted.');
    }

    public function aiPlan(Request $request): JsonResponse
    {
        TimezoneNormalizer::normalizeRequest($request);
        $wid = $this->workspaceId($request);

        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:500'],
            'campaign_goal' => ['nullable', 'string', 'max:200'],
            'tone' => ['nullable', 'string', 'in:professional,casual,humorous,inspirational,educational'],
            'post_count' => ['nullable', 'integer', 'min:3', 'max:14'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'target_accounts' => ['required', 'array', 'min:1'],
            'target_accounts.*' => ['integer'],
            'timezone' => ['nullable', 'string', 'max:64', new ValidTimezone],
        ]);

        $requestedIds = collect($validated['target_accounts'])->map(fn ($id) => (int) $id);
        $accounts = SocialAccount::where('workspace_id', $wid)
            ->whereIn('id', $requestedIds)
            ->where('active', true)
            ->get(['id', 'network', 'name']);

        if ($accounts->count() !== $requestedIds->count()) {
            return response()->json(['errors' => ['target_accounts' => ['One or more selected accounts are invalid.']]], 403);
        }

        $networks = $accounts->pluck('network')->unique()->values()->all();
        $postCount = $validated['post_count'] ?? 7;
        $tone = $validated['tone'] ?? 'professional';
        $goal = $validated['campaign_goal'] ?? 'increase engagement and brand awareness';

        try {
            $gateway = app(LlmGateway::class);
            $messages = $this->buildPlanMessages(
                $validated['topic'], $networks, $postCount, $tone, $goal,
                $validated['start_date'], $validated['end_date'], $validated['timezone'] ?? 'UTC'
            );
            $response = $gateway->chat($wid, $messages, ['temperature' => 0.7, 'max_tokens' => 4096]);
            $posts = $this->parsePlanResponse($response->content, $postCount);

            return response()->json(['posts' => $posts, 'accounts' => $accounts]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    private function buildPlanMessages(
        string $topic,
        array $networks,
        int $count,
        string $tone,
        string $goal,
        string $startDate,
        string $endDate,
        string $timezone
    ): array {
        $networksStr = implode(', ', $networks);
        // ⚠️ The fourth copy of this map used to live here. The AI prompt and
        // the composer must quote the SAME limit, or the model writes to one
        // number while the UI counts against another.
        $limitLines = collect($networks)
            ->map(fn ($n) => "- {$n}: ".NetworkCapabilities::charLimit($n).' characters')
            ->implode("\n");

        $system = <<<SYSTEM
You are an expert social media strategist. Generate a content calendar as JSON.

RULES:
1. Output ONLY valid JSON — no markdown, no prose, no code fences.
2. Top-level object must be: {"posts": [...]}
3. Generate exactly {$count} posts spread evenly between {$startDate} and {$endDate}.
4. Each post must have EXACTLY these fields:
   - "title": short title (string, max 100 chars)
   - "body": post content (string)
   - "suggested_time": UTC ISO 8601 datetime (e.g. "2026-06-01T10:00:00Z")
   - "rationale": one sentence explaining timing/approach (string)
   - "platform_notes": object keyed by network with tailored copy variants, or null
5. Character limits per network:
{$limitLines}
6. Primary "body" must fit the SHORTEST character limit among: {$networksStr}
7. Tone: {$tone}. Campaign goal: {$goal}.
8. If you cannot produce valid JSON, return exactly: {"error": "generation_failed"}
SYSTEM;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => "Create a {$count}-post campaign calendar for: {$topic}\nPlatforms: {$networksStr}\nSchedule: {$startDate} to {$endDate} ({$timezone})."],
        ];
    }

    private function parsePlanResponse(string $content, int $expectedCount): array
    {
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);

        $decoded = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! isset($decoded['posts'])) {
            throw new \RuntimeException('AI returned malformed JSON. Please try again.');
        }
        if (isset($decoded['error'])) {
            throw new \RuntimeException('AI failed to generate the plan. Please refine your brief.');
        }

        return collect($decoded['posts'])->map(function ($post, $i) {
            if (empty($post['body'])) {
                throw new \RuntimeException("Post #{$i} is missing body content.");
            }

            return [
                'title' => $post['title'] ?? '',
                'body' => $post['body'],
                'suggested_time' => $post['suggested_time'] ?? null,
                'rationale' => $post['rationale'] ?? '',
                'platform_notes' => $post['platform_notes'] ?? null,
            ];
        })->all();
    }

    public function bulkStore(Request $request): JsonResponse
    {
        TimezoneNormalizer::normalizeRequest($request);
        $wid = $this->workspaceId($request);

        $validated = $request->validate([
            'posts' => ['required', 'array', 'min:1', 'max:14'],
            'posts.*.title' => ['nullable', 'string', 'max:256'],
            'posts.*.body' => ['required', 'string', 'max:5000'],
            'posts.*.scheduled_at' => ['nullable', 'date'],
            'posts.*.timezone' => ['nullable', 'string', 'max:64', new ValidTimezone],
            'posts.*.target_accounts' => ['required', 'array', 'min:1'],
            'posts.*.target_accounts.*' => ['integer'],
            'posts.*.ai_prompt' => ['nullable', 'string', 'max:1000'],
        ]);

        $allIds = collect($validated['posts'])
            ->flatMap(fn ($p) => $p['target_accounts'])
            ->map(fn ($id) => (int) $id)
            ->unique();

        $ownedCount = SocialAccount::where('workspace_id', $wid)->whereIn('id', $allIds)->count();
        if ($ownedCount !== $allIds->count()) {
            return response()->json(['errors' => ['posts' => ['One or more accounts do not belong to your workspace.']]], 403);
        }

        $now = now();
        foreach ($validated['posts'] as $i => $postData) {
            if (! empty($postData['scheduled_at']) && $now->copy()->addMinute()->gt($postData['scheduled_at'])) {
                return response()->json(['errors' => ["posts.{$i}.scheduled_at" => ['Must be at least 1 minute in the future.']]], 422);
            }

            // ⚠️ THE CHAR LIMIT WAS NEVER ENFORCED ON THIS PATH. store() and
            // update() both call assertBodyFitsEveryNetwork(); bulkStore()
            // enforced it only by ASKING THE MODEL NICELY — buildPlanMessages()
            // says "Primary body must fit the SHORTEST character limit", and an
            // LLM returning 400 characters for an X account was persisted
            // without objection. A prompt instruction is not a validation.
            //
            // Third occurrence of one rule reaching some write paths and not
            // others (char limit, then the ownership guard on update(), now
            // this), which is why post_type validation below is a single shared
            // method rather than a fourth copy.
            try {
                $this->assertBodyFitsEveryNetwork(
                    $postData['body'],
                    array_map('intval', $postData['target_accounts'])
                );
            } catch (ValidationException $e) {
                return response()->json([
                    'errors' => ["posts.{$i}.body" => $e->validator->errors()->get('body')],
                ], 422);
            }
        }

        $created = [];
        \DB::transaction(function () use ($validated, $wid, &$created) {
            foreach ($validated['posts'] as $postData) {
                $scheduledAt = $postData['scheduled_at'] ?? null;
                $post = SocialPost::create([
                    'workspace_id' => $wid,
                    'title' => $postData['title'] ?? null,
                    'body' => $postData['body'],
                    'media_urls' => [],
                    // AI-planned posts are text-only BY CONSTRUCTION, not by
                    // default-and-hope: buildPlanMessages() asks the model for
                    // title/body/suggested_time/rationale/platform_notes and no
                    // media field, parsePlanResponse() maps only those five, and
                    // media_urls is [] immediately above. Neither the request nor
                    // the LLM is asked to declare a type, because neither is in a
                    // position to know one. 'text' is deliverable on every driver,
                    // so these posts are never blocked by the rules above.
                    'post_type' => DriverCapabilities::TYPE_TEXT,
                    'media_type' => null,
                    'target_accounts' => array_map('intval', $postData['target_accounts']),
                    'scheduled_at' => $scheduledAt,
                    'timezone' => $postData['timezone'] ?? 'UTC',
                    'status' => $scheduledAt ? 'scheduled' : 'draft',
                    'ai_generated' => true,
                    'ai_prompt' => $postData['ai_prompt'] ?? null,
                ]);
                $created[] = $post->id;
            }
        });

        return response()->json(['success' => true, 'created' => count($created), 'post_ids' => $created]);
    }

    /** AI Post Planner – generate body copy from a prompt. */
    public function aiGenerate(Request $request): JsonResponse
    {
        $wid = $this->workspaceId($request);
        $request->validate([
            'prompt' => ['required', 'string', 'max:500'],
            'network' => ['nullable', 'string'],
        ]);

        try {
            $gateway = app(LlmGateway::class);
            $network = $request->network ?? 'any social network';
            $messages = [
                ['role' => 'system', 'content' => "You are a social media copywriter. Write engaging, concise posts optimized for {$network}. Return ONLY the post text, no explanations."],
                ['role' => 'user',   'content' => $request->prompt],
            ];
            $response = $gateway->chat($wid, $messages, []);

            return response()->json(['body' => $response->content]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Refuse a body longer than the strictest selected network allows.
     *
     * ⚠️ THIS IS A NEW RULE, NOT A REFACTOR. Before this, `body` was validated
     * only as `max:5000` regardless of destination, so a 1,000-character post
     * addressed to X was accepted here and then failed at publish time — after
     * scheduling, with the failure surfacing on the link row rather than on the
     * form the author could still edit. The four duplicated limit maps existed
     * to WARN in the UI; nothing enforced them.
     *
     * ⚠️ It can therefore reject drafts that were previously accepted. That is
     * the intent — the alternative is discovering the truncation from a failed
     * publish — but it only applies to new saves. Existing rows are untouched.
     *
     * The limit comes from NetworkCapabilities, the single source that replaced
     * those four maps, so the number enforced here, the number counted down in
     * the composer, and the number quoted to the AI planner cannot drift apart.
     *
     * @param  Collection<int, int>  $accountIds
     */
    /**
     * The post_type / media_type rules, shared by store(), update() AND
     * bulkStore().
     *
     * ⚠️ ONE DEFINITION ON PURPOSE. The char-limit rule reached store() and
     * update() but never bulkStore(), and the workspace-ownership guard reached
     * store() and the API controller but never update(). That is the same defect
     * twice: a rule written per-call-site gets forgotten at the call site nobody
     * was looking at. This one is a single method that every write path calls,
     * so forgetting it means deleting a line rather than failing to add one.
     *
     * @param  Collection<int, int>  $accountIds  ints, already
     *                                            ownership-checked by the caller
     * @param  list<string>  $mediaUrls
     */
    private function assertPostTypeIsDeliverable(
        string $postType,
        ?string $mediaType,
        Collection $accountIds,
        array $mediaUrls
    ): void {
        if ($postType === DriverCapabilities::TYPE_TEXT) {
            return; // deliverable everywhere; nothing to check
        }

        $networks = SocialAccount::whereIn('id', $accountIds)
            ->pluck('network', 'id')
            ->all();

        if ($networks === []) {
            return;
        }

        $capability = DriverCapabilities::requiredCapability($postType, $mediaType);

        // ⚠️ Gated on DRIVER reality, not on NetworkCapabilities. Instagram's API
        // accepts carousels; InstagramSocialDriver sends mediaUrls[0] and drops
        // the rest with no error. Allowing the selection because the platform
        // permits it is how media disappears silently between save and publish.
        $blocked = [];
        foreach (array_unique($networks) as $network) {
            if (! DriverCapabilities::supports($network, $capability)) {
                $blocked[] = $network;
            }
        }

        if ($blocked !== []) {
            $label = $postType === DriverCapabilities::TYPE_VIDEO
                ? 'video'
                : ($mediaType === DriverCapabilities::MEDIA_CAROUSEL ? 'carousel' : 'image');

            sort($blocked);

            throw ValidationException::withMessages([
                'target_accounts' => [sprintf(
                    'Cannot publish a %s post to: %s. Remove %s, or change the post type.',
                    $label,
                    implode(', ', $blocked),
                    count($blocked) === 1 ? 'that account' : 'those accounts'
                )],
            ]);
        }

        if ($postType === DriverCapabilities::TYPE_IMAGE && $mediaType === DriverCapabilities::MEDIA_CAROUSEL) {
            $this->assertCarouselCountFits(array_values(array_unique($networks)), $mediaUrls);
        }

        $this->assertMediaMatchesDeclaredType($postType, $mediaUrls);

        if ($postType === DriverCapabilities::TYPE_IMAGE) {
            $this->assertImageRatiosFit(array_values(array_unique($networks)), $mediaUrls);
        }
    }

    /**
     * A declared image post must not carry a video file, and vice versa.
     *
     * ⚠️ Caught AT SAVE, not at publish. Discovering the mismatch in the driver
     * means it surfaces in a queued job, where the user is not present: the post
     * goes to `failed` minutes later with a provider error, or worse publishes
     * wrong. The composer knows the answer while the author is still looking at
     * the form.
     *
     * Uses mime_content_type() on locally stored files only — the same call
     * YoutubeDriver already makes. Remote URLs are skipped rather than fetched;
     * see localPathForMediaUrl().
     *
     * @param  list<string>  $mediaUrls
     */
    private function assertMediaMatchesDeclaredType(string $postType, array $mediaUrls): void
    {
        if ($postType === DriverCapabilities::TYPE_TEXT) {
            return;
        }

        $expected = $postType === DriverCapabilities::TYPE_VIDEO ? 'video' : 'image';

        foreach ($mediaUrls as $url) {
            $path = $this->localPathForMediaUrl($url);
            if ($path === null) {
                continue;
            }

            // mime_content_type() returns string|false — never null, so a
            // null check here was a branch that could not be taken.
            $mime = @mime_content_type($path);
            if ($mime === false) {
                continue;
            }

            $actual = explode('/', $mime)[0];
            if ($actual !== 'image' && $actual !== 'video') {
                continue; // not a media file we classify; other rules cover it
            }

            if ($actual !== $expected) {
                throw ValidationException::withMessages([
                    'media_urls' => [sprintf(
                        'This is declared as %s post, but %s is %s.',
                        $expected === 'video' ? 'a video' : 'an image',
                        basename($path),
                        $actual === 'video' ? 'a video' : 'an image'
                    )],
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $networks
     * @param  list<string>  $mediaUrls
     */
    private function assertCarouselCountFits(array $networks, array $mediaUrls): void
    {
        $range = NetworkCapabilities::carouselRange($networks);
        $count = count($mediaUrls);

        // ⚠️ An EMPTY carousel is an unfinished draft, not an invalid one.
        // Enforcing the minimum at zero would trap a post the author saved
        // before attaching images: every later edit — even fixing a typo in the
        // body — would be refused, with no way to reach a valid state except
        // abandoning the post. The floor applies once media exists; publishing
        // an empty carousel still fails at the driver, where the author is not
        // mid-edit.
        if ($count === 0) {
            return;
        }

        if ($count < $range['min']) {
            throw ValidationException::withMessages([
                'media_urls' => ["A carousel needs at least {$range['min']} images; this post has {$count}."],
            ]);
        }

        // ⚠️ A null max is "not verified", NEVER "unlimited" — see
        // NetworkCapabilities::carouselRange(). Nothing is enforced above the
        // floor when no bound is known, but the uncertainty is not laundered
        // into a silent pass either: the UI is told which networks are
        // unverified so it can say so.
        if ($range['max'] !== null && $count > $range['max']) {
            throw ValidationException::withMessages([
                'media_urls' => ["The strictest selected network allows {$range['max']} images; this post has {$count}."],
            ]);
        }
    }

    /**
     * Aspect-ratio validation for LOCAL image files only.
     *
     * ⚠️ VIDEO RATIO IS DELIBERATELY OUT OF SCOPE ON THIS BRANCH. It is not a
     * matter of effort: reading a video's dimensions needs ffprobe or Imagick,
     * and this machine has neither (GD and exif only, measured). Adding a binary
     * dependency is a deployment decision, not a code one. NetworkCapabilities
     * already carries video_ratio_min/max and max_video_seconds, so the data is
     * ready and unused.
     *
     * TODO(Branch 4 — existing-platform video/carousel work): revisit together
     * with real driver video support. Enforcing a video ratio is pointless while
     * DriverCapabilities says only YouTube can receive a video at all.
     *
     * Remote URLs are skipped rather than fetched — downloading arbitrary
     * user-supplied URLs server-side to measure them is an SSRF surface, and
     * this codebase has already had one.
     *
     * @param  list<string>  $networks
     * @param  list<string>  $mediaUrls
     */
    private function assertImageRatiosFit(array $networks, array $mediaUrls): void
    {
        $range = NetworkCapabilities::imageRatioRange($networks);

        // Nothing verified among the selected networks -> nothing to enforce.
        if ($range['min'] === null && $range['max'] === null) {
            return;
        }

        foreach ($mediaUrls as $url) {
            $path = $this->localPathForMediaUrl($url);
            if ($path === null) {
                continue;
            }

            $size = @getimagesize($path);
            if ($size === false || empty($size[1])) {
                continue; // unreadable: not this rule's job to reject
            }

            $ratio = round($size[0] / $size[1], 4);

            if ($range['min'] !== null && $ratio < $range['min'] - 0.001) {
                throw ValidationException::withMessages([
                    'media_urls' => [sprintf(
                        'Image is %s:1, narrower than the %s:1 minimum for the selected networks.',
                        $ratio, $range['min']
                    )],
                ]);
            }

            if ($range['max'] !== null && $ratio > $range['max'] + 0.001) {
                throw ValidationException::withMessages([
                    'media_urls' => [sprintf(
                        'Image is %s:1, wider than the %s:1 maximum for the selected networks.',
                        $ratio, $range['max']
                    )],
                ]);
            }
        }
    }

    /**
     * Resolve a media URL to a readable local file, or null when it is remote.
     *
     * Only files this application stored are inspected. Anything else — an
     * absolute URL to another host, a path that escapes the media root — returns
     * null and is skipped.
     */
    private function localPathForMediaUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ($host !== null && $host !== parse_url(config('app.url'), PHP_URL_HOST)) {
            return null;
        }

        $relative = ltrim(str_replace('/storage/', '', $path), '/');
        if (str_contains($relative, '..')) {
            return null;
        }

        $full = storage_path('app/public/'.$relative);

        return is_file($full) && is_readable($full) ? $full : null;
    }

    /**
     * @param  Collection<int, int>|list<int>  $accountIds
     */
    private function assertBodyFitsEveryNetwork(string $body, Collection|array $accountIds): void
    {
        $networks = SocialAccount::whereIn('id', $accountIds)->pluck('network')->all();
        if ($networks === []) {
            return;
        }

        $limit = NetworkCapabilities::minCharLimit($networks);
        $length = mb_strlen($body);

        if ($length > $limit) {
            // Name the network that set the limit — "too long" without saying
            // which destination caused it leaves the author guessing.
            $strictest = collect($networks)
                ->unique()
                ->sortBy(fn (string $n) => NetworkCapabilities::charLimit($n))
                ->first();

            throw ValidationException::withMessages([
                'body' => ["The post is {$length} characters, but {$strictest} allows {$limit}."],
            ]);
        }
    }
}
