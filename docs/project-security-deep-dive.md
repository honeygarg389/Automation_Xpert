# Source-Code Security Deep Dive — Critical Paths & Loopholes

**Date:** 2026-08-03 · **Method:** manual code reading of critical paths (not grep summaries)
**Scope:** authentication bypass, privilege escalation, IDOR, SSRF, webhook forgery, mass assignment, injection, path traversal
**No files modified.** No exploit executed against a live system — all findings derived from reading source.

This document supplements [`project-security-findings.md`](project-security-findings.md) with findings that only surfaced by tracing actual code paths end to end.

## New findings

| ID | Severity | Title | Exploitable by |
|---|---|---|---|
| DEEP-01 | **Critical** | SSRF with full response disclosure via outbound webhooks | Any authenticated client user |
| DEEP-02 | **High** | WooCommerce webhook signature check skipped when header omitted | Unauthenticated attacker |
| DEEP-03 | **High** | Client impersonation gated by a read-only permission | Low-privilege admin |
| DEEP-04 | **Medium** | Shopify webhook accepted unverified when store secret unset | Unauthenticated attacker |
| DEEP-05 | **Medium** | Subscription plan assignment gated by a read-only permission | Low-privilege admin |
| DEEP-06 | **Low** | Impersonation honours client-supplied `remember` flag | Admin (persistence) |
| DEEP-07 | **Low** | Unbounded workspace creation | Any authenticated user |

---

## DEEP-01 · SSRF with full response disclosure — **Critical**

**Files:** `app/Http/Controllers/Client/WebhookEndpointController.php:47-52,111-124` · `app/Jobs/DispatchWebhookJob.php:47-59`
**Status:** **Confirmed by code reading.** Not executed.

### The chain

**1. The URL is user-controlled and only syntax-validated.**

```php
// WebhookEndpointController::store — line 47
$validated = $request->validate([
    'url' => ['required', 'url', 'max:500'],   // syntax only — no host restriction
    ...
]);
$request->user()->webhookEndpoints()->create([...$validated, ...]);
```

Laravel's `url` rule validates structure. It does **not** reject loopback, link-local, or RFC1918 hosts.

**2. The server fetches it with no egress control.**

```php
// DispatchWebhookJob::handle — line 47
$response = Http::timeout(10)
    ->withHeaders([...])
    ->post($this->endpoint->url, $payloadJson);
```

No allowlist, no DNS-rebinding protection, no redirect cap, no private-range block.

**3. The response body is stored.**

```php
// line 58
'response_body' => substr($response->body(), 0, 2000),
```

**4. And returned to the user.**

```php
// WebhookEndpointController::deliveries — line 116
$deliveries = $webhookEndpoint->deliveries()->latest()->paginate(25);
return Inertia::render('client/Webhooks/Deliveries', [
    'deliveries' => $deliveries,      // full models — includes response_body
]);
```

`WebhookDelivery::$fillable` includes `response_body`, and no `$hidden` excludes it.

### Why this is Critical rather than High

Most SSRF is blind. This one **returns 2000 bytes of the internal response to the attacker's own dashboard**, turning it into a general-purpose internal read primitive. There is also a "send test webhook" action (`line ~108`), so the attacker triggers fetches on demand rather than waiting for a business event.

Reachable targets on a typical deployment:

| Target | Yield |
|---|---|
| `http://169.254.169.254/latest/meta-data/iam/security-credentials/…` | AWS IAM credentials (IMDSv1) |
| `http://metadata.google.internal/computeMetadata/v1/…` | GCP tokens (needs a header — partially mitigated) |
| `http://127.0.0.1:6379/`, `:3306`, `:8080` | Internal service banners/data |
| `http://127.0.0.1:8005/admin/…` | Internal-only endpoints |

**Business impact.** On AWS with IMDSv1 this is cloud-account compromise from a normal customer account. Even without cloud metadata it maps and reads the internal network.

**Technical impact.** Authenticated SSRF with response disclosure; potential credential theft and lateral movement.

**Mitigating factors.** Requires an authenticated client user (self-registration is open, so this is a low bar). Ownership is correctly enforced on the deliveries view (`$this->authorize('view', ...)`) — so it is *not* also an IDOR.

**Recommended fix.**
1. Resolve the hostname **before** the request and reject loopback, link-local (`169.254.0.0/16`), RFC1918, IPv6 ULA/`::1`, and `0.0.0.0/8`. Re-check after redirects, or disable redirects (`->withoutRedirecting()`).
2. Restrict schemes to `https` (and `http` only if you must).
3. Stop returning `response_body` to users — store a hash or truncated status line, or add `response_body` to `$hidden`.
4. Consider a dedicated egress proxy for all outbound webhooks.

**Effort:** Medium. **Priority:** **P0.**

---

## DEEP-02 · WooCommerce webhook signature check skipped when the header is omitted — **High**

**File:** `app/Modules/Ecommerce/Http/Controllers/EcommerceWebhookController.php:43-50`
**Status:** **Confirmed by code reading.**

```php
$signature = $request->header('x-wc-webhook-signature');
if ($signature) {                                    // ← attacker controls this condition
    $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $store->webhook_secret, true));
    if (! hash_equals($expected, $signature)) {
        abort(401, 'Invalid signature');
    }
}
// falls through to ingest() when no signature header is sent
```

The verification branch is guarded by the presence of the very header it validates. **Omit `x-wc-webhook-signature` and no check runs at all.**

The HMAC itself is correct (`hash_equals`, raw binary, base64) — the flaw is purely the fail-open guard.

### Reachability

Route: `POST webhooks/ecommerce/woocommerce/{store}` — CSRF-exempt, unauthenticated, `throttle:webhooks`. `{store}` is implicit route-model binding on the **primary key**, so store IDs are sequential integers and trivially enumerable.

### Impact

`ingest()` → `ProcessEcommerceWebhookJob` on the `automation` queue, which creates/updates orders and raises `CommerceEventReceived`. That event is wired to `AutomationTriggerListener`, so forged webhooks can:

- Inject fake orders/customers into any tenant
- **Trigger automations that send real WhatsApp/SMS/email messages** to that tenant's contacts
- Fire abandoned-cart recovery flows
- Corrupt revenue reporting

Idempotency (`WebhookIdempotencyService`) only deduplicates repeats — it does not authenticate.

**Recommended fix.** Make the signature mandatory:

```php
$signature = (string) $request->header('x-wc-webhook-signature', '');
$expected  = base64_encode(hash_hmac('sha256', $request->getContent(), (string) $store->webhook_secret, true));
if ($signature === '' || blank($store->webhook_secret) || ! hash_equals($expected, $signature)) {
    abort(401, 'Invalid signature');
}
```

**Effort:** Small. **Priority:** **P0.**

---

## DEEP-03 · Client impersonation gated by a read-only permission — **High**

**Files:** `app/Policies/ClientPolicy.php:40-43` · `routes/admin.php:52` · `app/Http/Controllers/Admin/ClientController.php:297-329`
**Status:** **Confirmed.**

```php
// ClientPolicy.php
public function view(AdminUser $user, Client $client): bool
{
    return $user->hasPermissionTo('view_clients');
}

public function impersonate(AdminUser $user, Client $client): bool
{
    return $user->hasPermissionTo('view_clients');   // ← identical to read access
}
```

Route middleware agrees: `->middleware('permission:view_clients')`.

The permission table defines a proper CRUD ladder — `view_clients`, `create_clients`, `update_clients`, `delete_clients` — so the granularity exists and simply isn't used here.

### Impact

Any admin granted **only "View Clients"** — the permission you would give a read-only support or analyst role — can:

```php
Auth::guard('web')->login($targetUser, $request->boolean('remember', false));
```

log in as the target client's **administrator** user (the code deliberately prefers `client_role = 'administrator'`), and then perform every client-side action: read all conversations and contact PII, send messages, delete data, change billing, export the workspace.

This converts a read-only admin grant into full write access over every tenant. Note the three seeded roles: Super Admin (28 permissions), Admin (0), Support (0) — the risk materialises the moment someone assigns `view_clients` to Support.

**Mitigating factor.** Impersonation *is* audit-logged (`impersonation.started`/`ended`), so it is detectable after the fact.

#### ⛔ This is a white-label blocker, not just an admin-panel issue

**Escalated 2026-08-04. Added to the hard gate in `docs/deployment-safety.md`.**

The finding above describes a *platform* admin with `view_clients` impersonating a client.
Under the partner model the shape changes and gets materially worse:

| | Today | Under the partner tier |
|---|---|---|
| Who holds the permission | A platform employee | **A partner's staff account** |
| What they reach | Any client | Any client — **including other partners' customers** |
| What the boundary is meant to guarantee | Platform-internal access control | **The core promise of white-label: partners cannot see each other's book of business** |

A reseller platform whose *read-only* role confers cross-tenant impersonation is not
sellable. `view_clients` is exactly the permission a partner would hand to a support agent or
an analyst.

**Therefore:** fix before the partner tier ships, independently of the customer-data gate.
The two deadlines are different and this finding is subject to the earlier of them.

Note the interaction with §G-1d: once partner reassignment exists, stale workspace membership
and read-permission impersonation compound — one grants lingering access, the other grants it
to the wrong role.

**Recommended fix.** Introduce a dedicated `impersonate_clients` permission, assign it only to Super Admin, and gate both the policy and the route on it.

**Effort:** Small. **Priority:** **P1.**

---

## DEEP-04 · Shopify webhook accepted unverified when the store secret is unset — **Medium**

**File:** `app/Modules/Ecommerce/Http/Controllers/EcommerceWebhookController.php:22-30`

```php
$secret = $store->credentials['api_secret_key'] ?? null;
if ($secret) {                       // no secret → no verification, in any environment
    ...hash_equals...
}
```

Same fail-open shape as DEEP-02, but the condition depends on store state rather than a request header, so it is not directly attacker-controlled — hence Medium.

Contrast with `RazorpayGateway.php:148`, which handles this correctly:

```php
} elseif (app()->environment('production')) {
    return new Response('Webhook secret not configured', 401);
}
```

…and with `bigcommerce()` (line 62), which is **fully correct** — mandatory token, rejects a null secret, uses `hash_equals`.

**Recommended fix.** Reject when the secret is missing, at minimum in production. **Effort:** Small. **Priority:** P1.

---

## DEEP-05 · Subscription plan assignment gated by a read-only permission — **Medium**

**File:** `app/Policies/ClientPolicy.php:35-38`

```php
public function assignPlan(AdminUser $user, Client $client): bool
{
    return $user->hasPermissionTo('view_clients'); // or manage_subscriptions
}
```

The trailing comment is the author acknowledging this is wrong. A read-only admin can assign any subscription plan to any client — granting paid entitlements or downgrading a paying customer.

**Recommended fix.** Create and use `manage_subscriptions`. **Effort:** Small. **Priority:** P1.

---

## DEEP-06 · Impersonation honours a client-supplied `remember` flag — **Low**

**File:** `app/Http/Controllers/Admin/ClientController.php:320`

```php
Auth::guard('web')->login($targetUser, $request->boolean('remember', false));
```

The admin's request body controls whether a persistent remember-me cookie is issued **for the impersonated client user**. `ImpersonationController::stop()` calls `Auth::guard('web')->logout()`, which clears it — but only if the admin explicitly ends the session. Abandoning the browser leaves a long-lived credential for another tenant's account.

`stop()` also does not call `$request->session()->regenerate()`, leaving the session ID unchanged across a privilege transition.

**Recommended fix.** Pass `false` unconditionally; regenerate the session on both start and stop. **Effort:** Small. **Priority:** P2.

---

## DEEP-07 · Unbounded workspace creation — **Low**

**File:** `app/Policies/WorkspacePolicy.php:20-23`

```php
public function create(User $user): bool
{
    return true;
}
```

No plan-limit or quota check at the policy layer. If `EnforceLimit` middleware does not cover this route, any user can create unlimited workspaces — resource exhaustion and a possible billing-limit bypass. **Requires manual verification** of whether `limit` middleware guards the create route.

**Effort:** Small. **Priority:** P2.

---

# Verified secure — critical paths that passed

These were read in full and found correct. Recorded so future changes do not silently regress them.

| Area | File | Verdict |
|---|---|---|
| Admin authorization | `RequirePermission.php` | ✅ Checks auth, **re-checks `isActive()` and force-logs-out inactive admins**, correct 401/403 split |
| API token scoping | `CheckApiAbility.php` | ✅ Correct — note `'*'` tokens bypass all scopes by design |
| Mass assignment | all controllers | ✅ **Zero** `$request->all()` / `$request->input()` into `create`/`update`/`fill` |
| Registration | `RegisteredUserController.php:41-64` | ✅ Explicit field whitelist; `role`/`client_role`/`status` never client-settable |
| Password storage | `User.php:97`, `AdminUser.php:23` | ✅ `'password' => 'hashed'` on both guards |
| 2FA secret storage | `User.php:98-99` | ✅ `encrypted` / `encrypted:array` |
| Team role changes | `TeamController::update:91-120` | ✅ Administrator check + client scoping + `in:administrator,staff` enum |
| Team deletion | `TeamController::destroy:123-137` | ✅ Scoped, plus last-administrator guard |
| Media deletion | `MediaController::destroy:77` | ✅ Polymorphic owner check |
| Invitation deletion | `InvitationController::destroy:70` | ✅ `client_id` match |
| Notifications | `NotificationController:65,86` | ✅ Scoped through `$request->user()->notifications()` |
| Webhook delivery view | `WebhookEndpointController::deliveries:113` | ✅ `$this->authorize('view', ...)` |
| BigCommerce webhook | `EcommerceWebhookController::bigcommerce:62` | ✅ Mandatory token, rejects null secret, `hash_equals` |
| Paystack webhook | `PaystackGateway.php:143-149` | ✅ Mandatory, `hash_equals` |
| Razorpay webhook | `RazorpayGateway.php:140-152` | ✅ Empty-signature rejected **and** production guard for missing secret |
| Demo mode | `EnsureNotDemoMode.php` | ✅ Method-based, tight named allowlist, documented rationale |
| Path traversal | filesystem call sites | ✅ No user input reaches file paths; `prefixedPath` applies `ltrim` |
| SQL injection | `InboxDuplicateReportCommand:73-78` | ✅ Interpolated `$path` comes from a hardcoded array |
| Dynamic execution | all of `app/` | ✅ No `eval`, `create_function`, or `unserialize` |

---

# Revised priority list

DEEP-01 and DEEP-02 are both remotely reachable and outrank several items in the original roadmap.

| Priority | Item | Source |
|---|---|---|
| **P0** | Test suite destroys the live database | SEC-001 |
| **P0** | Git + database backup | P0-2 |
| **P0** | **SSRF with response disclosure** | **DEEP-01** |
| **P0** | **WooCommerce webhook bypass** | **DEEP-02** |
| P0 | `db:backup` credential leak | SEC-003 |
| P1 | Impersonation permission | DEEP-03 |
| P1 | Shopify unverified webhook | DEEP-04 |
| P1 | `assignPlan` permission | DEEP-05 |
| P1 | Remaining original P1 set | roadmap |

## Assessment

The pattern across these findings is consistent: **the cryptography and the ownership checks are right; the guard conditions around them are wrong.** Every HMAC uses `hash_equals`, every sampled controller scopes to the owner, passwords and secrets are correctly hashed and encrypted. What fails is the surrounding `if` — `if ($signature)`, `if ($secret)`, `hasPermissionTo('view_clients')` — where an optional check silently becomes no check.

That is an encouraging shape of defect: the fixes are small and local, not architectural. Four of the seven findings here are single-line changes. DEEP-01 is the only one requiring real design work.
