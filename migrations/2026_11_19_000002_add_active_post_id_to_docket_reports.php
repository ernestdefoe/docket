<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * One open report per post, held by the database itself: active_post_id is
 * the post's id while the report is open or in progress, and empty once it
 * closes, under a unique index (empty values never clash). Existing open
 * reports fill it, the newest per post when there are several. The index
 * is named by Laravel, prefix included.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('docket_reports', function (Blueprint $table) {
            $table->unsignedInteger('active_post_id')->nullable();
        });

        $db = $schema->getConnection();
        $newest = $db->table('docket_reports')
            ->whereNotNull('post_id')
            ->whereIn('status', ['open', 'in_progress'])
            ->groupBy('post_id')
            ->selectRaw('max(id) as id')
            ->pluck('id')
            ->all();

        foreach (array_chunk($newest, 500) as $ids) {
            $db->table('docket_reports')->whereIn('id', $ids)->update(['active_post_id' => $db->raw($db->getQueryGrammar()->wrap('post_id'))]);
        }

        $schema->table('docket_reports', function (Blueprint $table) {
            $table->unique('active_post_id');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('docket_reports', function (Blueprint $table) {
            $table->dropUnique(['active_post_id']);
            $table->dropColumn('active_post_id');
        });
    },
];
