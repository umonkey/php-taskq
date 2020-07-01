# Simple task queue

Simple task queue for personal use.  Features:

- Requires only one database table to work.
- Tasks have custom payload, an array of data.
- Tasks have priorities, an integer.  Highest priority tasks are executed first.
- Separate worker processes.   Each worker can either handle all tasks, or high priority (>=0), or low priority (<0).
- Failed tasks get repeated.
- Task name is a combination of service name and method name within that service.  Services are read from the dependency container.


## Setup

Add this to `config/dependencies.php`:

```
$container['taskq'] = function ($c) {
    return return $c['callableResolver']->getClassInstance('Umonkey\\TaskQueue\\TaskQueue');
};
```

## Code examples

Queue a task:

```
class SomeClass
{
    public function __construct($taskq)
    {
        $this->taskq = $taskq;
    }

    protected function doSomething(): void
    {
        $this->taskq->add('acme.doSomething', [
            'key' => 'foo',
            'value' => 'bar',
        ], -20);
    }
}
```

Register task handler in `config/dependencies.php`:


```
$container['acme'] = function ($c) {
    return return $c['callableResolver']->getClassInstance('Acme\\TaskHandler\\TaskHandler');
};
```

Handle tasks:

```
declare(strict_types=1);

namespace Acme\TaskHandler

class TaskHandler
{
    public function doSomething(array $data): void
    {
        error_log(var_export($data, true));
    }
}
```


## Database structure

```
CREATE TABLE IF NOT EXISTS `taskq` (
    `id` INTEGER UNSIGNED NOT NULL AUTO_INCREMENT,
    `added_at` DATETIME NOT NULL,
    `run_after` DATETIME NOT NULL,
    `priority` INTEGER NOT NULL DEFAULT 0,
    `payload` MEDIUMBLOB NOT NULL,
    `attempts` INTEGER UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY (`priority`),
    KEY(`run_after`),
    KEY(`attempts`)
) DEFAULT CHARSET utf8;
```
