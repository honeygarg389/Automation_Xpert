<?php

namespace Tests\Feature\Channels;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * BUG-019. The connect-time guard is the load-bearing control, so it has to be
 * unavoidable.
 *
 * The unique index on `channel_accounts.phone_number_id` protects ONE of the
 * three routing shapes. Messenger's `meta_json->page_id` and Instagram's two
 * keys are JSON paths with no schema-level protection at all, so for those the
 * only thing preventing two workspaces claiming the same page is that every
 * attach goes through `ChannelAccountRouting`.
 *
 * This fails if any other file attaches a routing identifier directly.
 *
 * Same hand-maintained shape — and the same known blindness — as the Phase 0
 * guards: a text scan cannot see an attach performed through a variable or a
 * raw query. It stops the attach being added without thought, not a determined
 * author.
 */
class ChannelRoutingGuardTest extends TestCase
{
    /**
     * Files permitted to write a routing identifier onto a ChannelAccount.
     *
     * @var array<string, string>
     */
    private const SANCTIONED = [
        'app/Modules/Shared/Services/ChannelAccountRouting.php' => 'The guard itself.',
        'app/Modules/Whatsapp/Http/Controllers/WhatsappEmbeddedSignupController.php' => 'Attaches via resolveForAttach().',
        'app/Modules/Whatsapp/Http/Controllers/WhatsappSetupController.php' => 'Attaches via resolveForAttach().',
        'app/Modules/Inbox/Http/Controllers/InboxSetupController.php' => 'Attaches Messenger + Instagram via resolveForAttach().',
        'app/Console/Commands/ChannelRoutingAuditCommand.php' => 'Reads only; reports duplicates. Never writes.',
    ];

    /**
     * The identifiers that decide which tenant an inbound message belongs to.
     *
     * @var list<string>
     */
    private const ROUTING_IDENTIFIERS = [
        'phone_number_id',
        'page_id',
        'instagram_page_id',
        'instagram_account_id',
    ];

    #[Test]
    public function only_the_routing_service_attaches_a_channel_routing_identifier(): void
    {
        $offenders = [];

        foreach ($this->phpFiles() as $path => $source) {
            if (array_key_exists($path, self::SANCTIONED)) {
                continue;
            }

            // Only WRITES matter. Reading phone_number_id to display it is fine
            // and happens in a dozen places.
            if (! preg_match('/ChannelAccount::(create|firstOrNew|firstOrCreate|updateOrCreate)/', $source)) {
                continue;
            }

            foreach (self::ROUTING_IDENTIFIERS as $identifier) {
                if (str_contains($source, $identifier)) {
                    $offenders[] = "  {$path}  (writes ChannelAccount and mentions {$identifier})";
                    break;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            '',
            'A file outside ChannelAccountRouting attaches a channel routing identifier:',
            implode("\n", $offenders),
            '',
            'Route it through ChannelAccountRouting::resolveForAttach() instead.',
            '',
            'For Messenger and Instagram that service is the ONLY protection — their identifiers',
            'live in a JSON column and no unique index covers them. A duplicate created here',
            'sends a customer\'s messages to another company\'s inbox, silently.',
            '',
        ]));
    }

    /** A stale exemption silently covers the next attach added to that file. */
    #[Test]
    public function the_sanctioned_list_has_no_entries_for_files_that_no_longer_exist(): void
    {
        $missing = array_values(array_filter(
            array_keys(self::SANCTIONED),
            fn (string $p) => ! file_exists(base_path($p))
        ));

        $this->assertSame([], $missing, 'Stale sanctioned files: '.implode(', ', $missing));
    }

    /**
     * Guards the guard. Narrowing the identifier list would leave the scan
     * passing while a channel went unprotected — Instagram in particular, whose
     * id is stored under either of two keys.
     */
    #[Test]
    public function the_scan_covers_every_routing_identifier_including_both_instagram_keys(): void
    {
        foreach (['phone_number_id', 'page_id', 'instagram_page_id', 'instagram_account_id'] as $identifier) {
            $this->assertContains($identifier, self::ROUTING_IDENTIFIERS,
                "{$identifier} routes inbound traffic to a workspace and must stay in the scan.");
        }
    }

    /** @return array<string, string> relative path => source */
    private function phpFiles(): array
    {
        $files = [];
        $root = base_path().DIRECTORY_SEPARATOR;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $files[str_replace($root, '', $file->getPathname())] = file_get_contents($file->getPathname());
        }

        return $files;
    }
}
