# Simple task queue

Simple task queue for personal use.  Features:

- Requires only one database table to work.
- Tasks have custom payload and priority.
- Failed tasks a retried for up to 10 times.
- Queue workers run in a separate process, outside of the web server (fpm).
- Task runner is run in yet another separate process, for more fail safety.


## Adding a task

Basically, adding a task is just an insert into the table named `taskq`.  Normally you call the `add` method of the [TaskQueue class](src/TaskQueue.php), which does that and logging.


## Running tasks

Run the `bin/taskq` script, it will handle the rest.  Make sure you restart it when it fails (with cron or supervisord).  The logic is:

1. Grab a file lock.  Exit if failed (another instance is running).
2. Check table `taskq`, fetch the first task with the highest priority for which the time has come.
3. Run the task runner process.  If it fails, postpone the task.  If it succeeds (exit code 0), delete the task.
4. Go to 2.


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
