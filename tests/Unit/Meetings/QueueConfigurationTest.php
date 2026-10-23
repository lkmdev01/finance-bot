<?php

uses(Tests\TestCase::class);

it('uses the worker queue name for newly dispatched Redis jobs', function () {
    $previousQueueName = getenv('QUEUE_NAME');
    $previousRedisQueue = getenv('REDIS_QUEUE');

    putenv('QUEUE_NAME=financi');
    putenv('REDIS_QUEUE=default');

    try {
        $queue = require base_path('config/queue.php');

        expect($queue['connections']['redis']['queue'])->toBe('financi');
    } finally {
        $previousQueueName === false ? putenv('QUEUE_NAME') : putenv('QUEUE_NAME='.$previousQueueName);
        $previousRedisQueue === false ? putenv('REDIS_QUEUE') : putenv('REDIS_QUEUE='.$previousRedisQueue);
    }
});
