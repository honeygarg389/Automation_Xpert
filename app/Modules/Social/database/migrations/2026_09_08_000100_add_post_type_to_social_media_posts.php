<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Declares what a post IS, instead of leaving every driver to re-guess it.
 *
 * Until now the intent was implicit and reconstructed differently by each
 * driver: FacebookDriver branches on count($mediaUrls) > 1, everyone else takes
 * [0], and nothing recorded whether the author meant a video, one image or a
 * carousel. Two posts with identical rows could mean different things.
 *
 * ─── BACKFILL: INFERRED FROM media_urls, NOT DEFAULTED FLAT ─────────────────
 *
 * The column is NOT NULL, so every existing row needs a value. Taking the
 * column default ('text') for all of them would be wrong in a way that is
 * invisible: a draft with four image URLs would be relabelled text-only, and
 * the composer would then refuse to show its images as a carousel. The row
 * would look fine and mean something else.
 *
 * So existing rows are inferred from what they actually carry:
 *
 *   media_urls empty/null      -> text        (no media was ever attached)
 *   any URL with a video ext   -> video       (mp4, mov, webm, m4v, avi)
 *   otherwise                  -> image, and media_type = carousel when the
 *                                 array holds more than one URL, else single.
 *
 * ⚠️ Extension sniffing is a heuristic, and it is used here ONLY because the
 * alternative is worse. It is applied to historical rows once, never on the
 * write path — new posts declare their type explicitly. A URL with no
 * extension (a CDN redirect, a signed URL) falls through to 'image', which is
 * the safer wrong answer: image is deliverable on more drivers than video, so
 * a misread degrades to a post that still publishes rather than one the
 * composer refuses to open.
 *
 * media_type stays NULL for text and video — it is meaningful only for images,
 * and a non-null value there would imply a distinction that does not exist.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const VIDEO_EXTENSIONS = ['mp4', 'mov', 'webm', 'm4v', 'avi'];

    public function up(): void
    {
        Schema::table('social_media_posts', function (Blueprint $table) {
            $table->enum('post_type', ['image', 'video', 'text'])
                ->default('text')
                ->after('media_urls');

            $table->enum('media_type', ['single', 'carousel'])
                ->nullable()
                ->after('post_type');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('social_media_posts', function (Blueprint $table) {
            $table->dropColumn(['post_type', 'media_type']);
        });
    }

    /**
     * Chunked so a large table does not load every row at once. Reads only the
     * two columns it needs.
     */
    private function backfill(): void
    {
        DB::table('social_media_posts')
            ->select('id', 'media_urls')
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $urls = array_values(array_filter(
                        json_decode($row->media_urls ?? '[]', true) ?: [],
                        fn ($u) => is_string($u) && $u !== ''
                    ));

                    if ($urls === []) {
                        continue; // stays 'text' by column default
                    }

                    $isVideo = false;
                    foreach ($urls as $url) {
                        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                        if (in_array($ext, self::VIDEO_EXTENSIONS, true)) {
                            $isVideo = true;
                            break;
                        }
                    }

                    DB::table('social_media_posts')->where('id', $row->id)->update($isVideo
                        ? ['post_type' => 'video', 'media_type' => null]
                        : ['post_type' => 'image', 'media_type' => count($urls) > 1 ? 'carousel' : 'single']
                    );
                }
            });
    }
};
