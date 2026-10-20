<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('docket_queues', function (Blueprint $table) {
    $table->increments('id');
    $table->string('name', 100);
    $table->string('description', 255)->nullable();
    $table->string('color', 20)->nullable();
    $table->unsignedInteger('position')->default(0);
    $table->dateTime('created_at')->nullable();
});
