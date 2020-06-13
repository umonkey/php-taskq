# Simple task queue

Simple task queue for personal use.

Usage:

```
$taskq = new \Umonkey\TaskQ($db, $logger, [
    'lock_file' => 'tmp/taskq.lock',
]);

$taskq->add('action-name', [
    'param1' => 'value',
], 20);
```
