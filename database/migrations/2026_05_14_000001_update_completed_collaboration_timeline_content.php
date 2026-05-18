<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('posts')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
UPDATE public.posts
SET content_text = regexp_replace(
    content_text,
    '^[A-Za-z ]+ completed collaboration:',
    'I have completed collaboration:'
)
WHERE source_type = 'collaboration_post'
AND source_event = 'completed'
AND content_text ILIKE '%completed collaboration:%'
SQL);

            return;
        }

        DB::table('posts')
            ->where('source_type', 'collaboration_post')
            ->where('source_event', 'completed')
            ->where('content_text', 'like', '%completed collaboration:%')
            ->chunkById(100, function ($posts): void {
                foreach ($posts as $post) {
                    $content = preg_replace(
                        '/^[A-Za-z ]+ completed collaboration:/',
                        'I have completed collaboration:',
                        (string) $post->content_text
                    );

                    if ($content !== $post->content_text) {
                        DB::table('posts')
                            ->where('id', $post->id)
                            ->update(['content_text' => $content]);
                    }
                }
            });
    }

    public function down(): void
    {
        // This content normalization is not safely reversible because the original peer name is not preserved.
    }
};
