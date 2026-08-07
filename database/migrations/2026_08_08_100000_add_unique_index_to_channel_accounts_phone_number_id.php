<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BUG-019. A UNIQUE index on `channel_accounts.phone_number_id`.
 *
 * ⚠️ THIS COVERS ONE OF THREE SHAPES. It is a backstop, not the control.
 *
 * Messenger routes on `meta_json->page_id` and Instagram on
 * `meta_json->instagram_page_id` OR `meta_json->instagram_account_id` — JSON
 * paths, which this index does not touch. A generated column would cover
 * Messenger but cannot express Instagram's two-key rule, and a schema that
 * protects two channels of three while appearing to protect all of them is
 * worse than one that protects one and says so.
 *
 * `App\Modules\Shared\Services\ChannelAccountRouting` is the load-bearing
 * control. Read it before assuming the database handles this.
 *
 * NULLs are fine: MySQL permits many NULLs in a UNIQUE index, and every
 * Messenger/Instagram row has `phone_number_id = NULL`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->abortOnExistingDuplicates();

        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->unique('phone_number_id', 'channel_accounts_phone_number_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->dropUnique('channel_accounts_phone_number_id_unique');
        });
    }

    /**
     * Pre-flight.
     *
     * `ALTER TABLE … ADD UNIQUE` fails with ERROR 1062 naming exactly ONE
     * offending value, which is useless for planning a fix — an operator needs
     * to know how many collisions there are and which workspaces are involved
     * before deciding anything.
     *
     * Deliberately no auto-resolve: which workspace legitimately owns a number
     * is a business fact. The newest row may be a genuine migration between
     * agencies, or a mis-onboarding. Only a human who knows the customers can
     * say.
     */
    private function abortOnExistingDuplicates(): void
    {
        $duplicates = DB::table('channel_accounts')
            ->selectRaw('phone_number_id, COUNT(*) AS row_count, GROUP_CONCAT(DISTINCT workspace_id ORDER BY workspace_id) AS workspace_ids')
            ->whereNotNull('phone_number_id')
            ->where('phone_number_id', '<>', '')
            ->groupBy('phone_number_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $lines = $duplicates->map(
            fn ($row) => "  phone_number_id={$row->phone_number_id}  rows={$row->row_count}  workspaces=[{$row->workspace_ids}]"
        )->implode(PHP_EOL);

        throw new RuntimeException(implode(PHP_EOL, [
            '',
            'MIGRATION ABORTED — duplicate channel_accounts.phone_number_id values exist.',
            '',
            'Every one of these means inbound WhatsApp messages for that number are currently',
            'being routed to whichever row the database happened to return first — in practice',
            'the oldest — regardless of which workspace actually owns the number today.',
            '',
            $lines,
            '',
            'Total: '.$duplicates->count().' duplicated identifier(s).',
            '',
            'Resolve each by hand. This migration will not choose for you: which workspace owns',
            'a number is a business fact, and picking wrong puts one company\'s conversations in',
            'another\'s inbox.',
            '',
            'Run `php artisan channels:audit-routing` for the Messenger and Instagram picture too —',
            'those are NOT covered by this index and have no schema-level protection at all.',
            '',
        ]));
    }
};
