<?php

/**
 * Task queue service.
 **/

declare(strict_types=1);

namespace Umonkey\TaskQueue;

use Psr\Log\LoggerInterface;
use Umonkey\Database;

class TaskQueue implements TaskQueueInterface
{
    /**
     * @var Database
     **/
    protected $db;

    /**
     * @var LoggerInterface
     **/
    protected $logger;

    /**
     * @var array
     **/
    protected $settings;

    protected $container;

    public function __construct(Database $db, LoggerInterface $logger, $settings, $container)
    {
        $this->db = $db;
        $this->logger = $logger;

        $this->settings = array_replace([
            'lock_file' => 'var/taskq.lock',
        ], $settings['taskq'] ?? []);

        $this->container = $container;
    }

    public function add(string $action, array $data = [], int $priority = 0): int
    {
        $now = strftime('%Y-%m-%d %H:%M:%S');

        $id = $this->db->insert('taskq', [
            'added_at' => $now,
            'run_after' => $now,
            'priority' => $priority,
            'attempts' => 0,
            'payload' => serialize([
                'action' => $action,
                'data' => $data,
            ]),
        ]);

        if (empty($data)) {
            $this->logger->debug('taskq: task {id} added with action={action} and no data.', [
                'id' => $id,
                'action' => $action,
            ]);
        } elseif (count($data) == 1) {
            $keys = array_keys($data);

            $this->logger->debug('taskq: task {id} added with action={action} and {k}={v}.', [
                'id' => $id,
                'action' => $action,
                'k' => $keys[0],
                'v' => $data[$keys[0]],
            ]);
        } else {
            $this->logger->debug('taskq: task {id} added with action={action} data={data}.', [
                'id' => $id,
                'action' => $action,
                'data' => $data,
            ]);
        }


        return $id;
    }

    public function run(string $mode = 'all'): void
    {
        if (php_sapi_name() != 'cli') {
            throw new \RuntimeException('taskq runner is for CLI only');
        }

        if ($lock = $this->settings['lock_file']) {
            if (!($f = fopen($lock, 'w+'))) {
                throw new \RuntimeException("could not open lock file {$lock} for writing");
            }

            $res = flock($f, LOCK_EX | LOCK_NB);
            if (false === $res) {
                fprintf(STDERR, "TaskQueue is already running.\n");
                exit(0);
            }
        }

        $waiting = false;
        while (true) {
            $sleep = false;

            $this->db->beginTransaction();

            if ($task = $this->pickTask($mode)) {
                $this->runTask($task);
                $waiting = false;
            } else {
                $sleep= true;

                if (!$waiting) {
                    $this->logger->debug('taskq: waiting for tasks.');
                    $waiting = true;
                }
            }

            $this->db->commit();

            if ($sleep) {
                sleep(1);
            }
        }
    }

    protected function pickTask(string $mode): ?array
    {
        $now = strftime('%Y-%m-%d %H:%M:%S');

        if ($mode === 'all') {
            $row = $this->db->fetchOne('SELECT * FROM `taskq` WHERE `run_after` < ? AND `attempts` < 10 ORDER BY `priority` DESC, `id` LIMIT 1', [$now]);
        } elseif ($mode == 'lo') {
            $row = $this->db->fetchOne('SELECT * FROM `taskq` WHERE `run_after` < ? AND `attempts` < 10 AND priority < 0 ORDER BY `priority` DESC, `id` LIMIT 1', [$now]);
        } elseif ($mode == 'hi') {
            $row = $this->db->fetchOne('SELECT * FROM `taskq` WHERE `run_after` < ? AND `attempts` < 10 AND priority >= 0 ORDER BY `priority` DESC, `id` LIMIT 1', [$now]);
        } else {
            throw new \InvalidArgumentException('unknown taskq mode');
        }

        return $row;
    }

    protected function runTask(array $task): void
    {
        $this->db->update('taskq', [
            'attempts' => $task['attempts'] + 1,
            'run_after' => strftime('%Y-%m-%d %H:%M:%S', time() + 60),
        ], [
            'id' => $task['id'],
        ]);

        $command = sprintf('%s %d', $_SERVER['SCRIPT_FILENAME'], $task['id']);
        $this->logger->info('taskq: running task {0}', [$task['id']]);
        exec($command, $output, $rc);

        if ($rc === 0) {
            $this->logger->info('taskq: task {0} finished, deleting.', [$task['id']]);
            $this->db->query('DELETE FROM taskq WHERE id = ?', [$task['id']]);
        } else {
            $this->logger->error('taskq: task {0} failed, will retry.', [$task['id']]);
        }
    }

    public function executeTask(int $id): void
    {
        $task = $this->db->fetchOne('SELECT * FROM taskq WHERE id = ?', [$id]);

        if (empty($task)) {
            $this->logger->error('taskq: task {0} does not exist.', $id);
            return;
        }

        $payload = unserialize($task['payload']);

        $action = $payload['action'];
        $data = $payload['data'];

        list($serviceName, $methodName) = explode('.', $action, 2);

        $service = $this->container->get($serviceName);
        if (null === $service) {
            $this->logger->error('taskq: service {0} not found, cannot run task {1}.', [$serviceName, $id]);
            return;
        }

        call_user_func([$service, $methodName], $data);
    }
}
