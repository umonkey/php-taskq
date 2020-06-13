<?php

/**
 * Task queue service.
 **/

declare(strict_types=1);

namespace Umonkey;

use Psr\Log\LoggerInterface;
use Umonkey\Database;

class TaskQueue
{
    /**
     * @var Database
     **/
    protected $database;

    /**
     * @var LoggerInterface
     **/
    protected $logger;

    /**
     * @var array
     **/
    protected $settings;

    protected $ping = false;

    public function __construct(Database $db, LoggerInterface $logger, array $settings)
    {
        $this->database = $db;
        $this->logger = $logger;

        $this->settings = array_replace([
            'ping_url' => null,
            'exec_pattern' => null,
            'lock_file' => 'tmp/taskq.lock',
        ]);
    }

    public function add(string $action, array $data = [], int $priority = 0): int
    {
        $logger = $this->logger;

        $data['__action'] = $action;

        $id = $this->database->insert('taskq', [
            'added' => strftime('%Y-%m-%d %H:%M:%S'),
            'priority' => $priority,
            'payload' => serialize($data),
        ]);

        $logger->debug('taskq: task {id} added with action {action}.', [
            'id' => $id,
            'action' => $action,
        ]);

        // Ping the server once per request.
        if ($this->ping === false) {
            $this->ping = true;

            $settings = $this->settings;
            if (!empty($settings['ping_url'])) {
                $url = $settings['ping_url'];
                @file_get_contents($url);

                $logger->info('taskq: ping sent to {url}', [
                    'url' => $url,
                ]);
            } else {
                $logger->info('taskq: ping_url not set.');
            }
        }

        return $id;
    }

    /**
     * Run the task queue daemon.
     *
     * Usually you run this from cron, e.g.:
     *
     * * * * * * cd /var/www/acme.com ; vendor/bin/taskq-runner >/dev/null 2>&1
     *
     * TODO: priorities.
     * TODO: throttle failed tasks.
     * TODO: file-lock multi-instance blocking.
     **/
    public function run()
    {
        if (php_sapi_name() != 'cli') {
            throw new \RuntimeException('taskq runner is for CLI only');
        }

        $pattern = $this->settings['exec_pattern'];
        if (null === $pattern) {
            throw new \RuntimeException('taskq.exec_pattern not set');
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

        fprintf(STDERR, "Waiting for tasks...\n");

        while (true) {
            $count = $this->database->transact(function ($db) {
                $rows = $db->fetch('SELECT `id` FROM `taskq` ORDER BY `priority` DESC, `id`');

                foreach ($rows as $row) {
                    $url = sprintf($st['exec_pattern'], $row['id']);
                    $this->httpPost($url, []);
                }

                return count($rows);
            });

            if (0 === $count) {
                sleep(1);
            }
        }
    }

    protected function httpPost(string $url, array $args = []): array
    {
        $payload = http_build_query($args);

        $context = stream_context_create([
            "http" => [
                "method" => "POST",
                "header" => "Content-Type: application/x-www-form-urlencoded",
                "content" => $payload,
                "ignore_errors" => true,
                "timeout" => 1200,
            ],
        ]);

        $res = [
            "status" => null,
            "headers" => [],
            "body" => null,
        ];

        $this->logger->info('taskq: POST {0}', [$url]);
        $res["body"] = file_get_contents($url, false, $context);

        foreach ($http_response_header as $k => $v) {
            if ($k == 0) {
                $s = explode(" ", $http_response_header[0], 3);
                $res["status"] = (int)$s[1];
            }

            else {
                $kv = preg_split('@:\s+@', $v, 2, PREG_SPLIT_NO_EMPTY);
                $res["headers"][$kv[0]] = $kv[1];
            }
        }

        return $res;
    }
}
