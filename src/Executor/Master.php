<?php declare(strict_types=1);
/* (c) Anton Medvedev <anton@medv.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Deployer\Executor;

use Deployer\Component\Ssh\Client;
use Deployer\Configuration\Configuration;
use Deployer\Deployer;
use Deployer\Exception\ConnectException;
use Deployer\Exception\Exception;
use Deployer\Host\Host;
use Deployer\Host\Localhost;
use Deployer\Selector\Selector;
use Deployer\Task\Task;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

const FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

function spinner($message = '')
{
    $frame = FRAMES[(int)(microtime(true) * 10) % count(FRAMES)];
    return "  $frame $message\r";
}

class Master
{
    private $input;
    private $output;
    private $server;
    private $messenger;
    private $client;
    private $config;

    public function __construct(
        InputInterface $input,
        OutputInterface $output,
        Server $server,
        Messenger $messenger,
        Client $client,
        Configuration $config
    )
    {
        $this->input = $input;
        $this->output = $output;
        $this->server = $server;
        $this->messenger = $messenger;
        $this->client = $client;
        $this->config = $config;
    }

    /**
     * @param Task[] $tasks
     * @param Host[] $hosts
     * @param Planner|null $plan
     * @return int
     */
    public function run(array $tasks, array $hosts, $plan = null): int
    {
        $globalLimit = (int)$this->input->getOption('limit') ?: count($hosts);

        foreach ($tasks as $task) {
            $plan || $this->messenger->startTask($task);

            $plannedHosts = $hosts;

            $limit = min($globalLimit, $task->getLimit() ?? $globalLimit);

            if ($task->isOnce()) {
                $plannedHosts = [];
                foreach ($hosts as $currentHost) {
                    if (Selector::apply($task->getSelector(), $currentHost)) {
                        $plannedHosts[] = $currentHost;
                        break;
                    }
                }
            }

            if ($task->isLocal()) {
                $plannedHosts = [new Localhost('localhost')];
            }

            if ($limit === 1 || count($plannedHosts) === 1) {
                foreach ($plannedHosts as $currentHost) {
                    if (!Selector::apply($task->getSelector(), $currentHost)) {
                        if ($plan) {
                            $plan->commit([], $task);
                        }
                        continue;
                    }

                    if ($plan) {
                        $plan->commit([$currentHost], $task);
                        continue;
                    }

                    $exitCode = $this->runTask($task, [$currentHost]);
                    if ($exitCode !== 0) {
                        return $exitCode;
                    }
                }
            } else {
                foreach (array_chunk($hosts, $limit) as $chunk) {
                    $selector = $task->getSelector();
                    $selectedHosts = [];
                    foreach ($chunk as $currentHost) {
                        if ($selector === null || Selector::apply($selector, $currentHost)) {
                            $selectedHosts[] = $currentHost;
                        }
                    }

                    if ($plan) {
                        $plan->commit($selectedHosts, $task);
                        continue;
                    }

                    $exitCode = $this->runTask($task, $selectedHosts);
                    if ($exitCode !== 0) {
                        return $exitCode;
                    }
                }
            }

            if (!$plan) {
                $this->messenger->endTask($task);
            }
        }

        return 0;
    }

    /**
     * @param Host[] $hosts
     */
    public function connect(array $hosts)
    {
        $callback = function (string $output) {
            $output = preg_replace('/\n$/', '', $output);
            if (strlen($output) !== 0) {
                $this->output->writeln($output);
            }
        };

        // Connect to each host sequentially, to prevent getting locked.
        foreach ($hosts as $host) {
            if ($host instanceof Localhost) {
                continue;
            }
            $process = $this->createConnectProcess($host);
            $process->start();

            while ($process->isRunning()) {
                $this->gatherOutput([$process], $callback);
                if ($this->output->isDecorated()) {
                    $this->output->write(spinner(str_pad("connect {$host->getTag()}", intval(getenv('COLUMNS')) - 1)));
                }
                usleep(1000);
            }

            if ($process->getExitCode() !== 0) {
                throw new ConnectException($process->getOutput());
            }
        }

        // Clear spinner.
        $this->output->write(str_repeat(' ', intval(getenv('COLUMNS')) - 1) . "\r");
    }

    /**
     * @param Task $task
     * @param Host[] $hosts
     * @return int
     */
    private function runTask(Task $task, array $hosts): int
    {
        if (getenv('DEPLOYER_LOCAL_WORKER') === 'true') {
            // This allows to code coverage all recipe,
            // as well as speedup tests by not spawning
            // lots of processes. Also there is a few tests
            // what runs with workers for tests subprocess
            // communications.
            foreach ($hosts as $host) {
                $worker = new Worker(Deployer::get());
                $exitCode = $worker->execute($task, $host);
                if ($exitCode !== 0) {
                    return $exitCode;
                }
            }
            return 0;
        }

        $callback = function (string $output) {
            $output = preg_replace('/\n$/', '', $output);
            if (strlen($output) !== 0) {
                $this->output->writeln($output);
            }
        };

        /** @var Process[] $processes */
        $processes = [];

        $this->server->addTimer(0, function () use (&$processes, $hosts, $task) {
            foreach ($hosts as $host) {
                $processes[] = $this->createProcess($host, $task);
            }

            foreach ($processes as $process) {
                $process->start();
            }
        });

        $this->server->addPeriodicTimer(0.03, function ($timer) use (&$processes, $callback) {
            $this->gatherOutput($processes, $callback);
            $this->output->write(spinner());
            if ($this->allFinished($processes)) {
                $this->server->stop();
                $this->server->cancelTimer($timer);
            }
        });

        $this->server->run();

        $this->output->write("    \r"); // clear spinner
        $this->gatherOutput($processes, $callback);

        return $this->cumulativeExitCode($processes);
    }

    protected function createProcess(Host $host, Task $task): Process
    {
        $dep = PHP_BINARY . ' ' . DEPLOYER_BIN;
        $decorated = $this->output->isDecorated() ? '--decorated' : '';
        $verbosity = self::stringifyVerbosity($this->output->getVerbosity());
        if ($task->isVerbose() && $this->output->getVerbosity() === OutputInterface::VERBOSITY_NORMAL) {
            $verbosity = '-v';
        }
        $command = "$dep worker $task {$host->getAlias()} {$this->server->getPort()} {$this->input} $decorated $verbosity";

        if ($this->output->isDebug()) {
            $this->output->writeln("[{$host->getTag()}] $command");
        }

        return Process::fromShellCommandline($command);
    }

    protected function createConnectProcess(Host $host): Process
    {
        $dep = PHP_BINARY . ' ' . DEPLOYER_BIN;
        $option = implode(' ', array_map(function ($o) {
            return "-o $o";
        }, $this->input->getOption('option')));
        $decorated = $this->output->isDecorated() ? '--decorated' : '';
        $verbosity = self::stringifyVerbosity($this->output->getVerbosity());
        $command = "$dep connect {$host->getAlias()} $option $decorated $verbosity";

        if ($this->output->isDebug()) {
            $this->output->writeln("[{$host->getTag()}] $command");
        }

        return Process::fromShellCommandline($command);
    }

    /**
     * @param Process[] $processes
     * @return bool
     */
    protected function allFinished(array $processes): bool
    {
        foreach ($processes as $process) {
            if (!$process->isTerminated()) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param Process[] $processes
     * @param callable $callback
     */
    protected function gatherOutput(array $processes, callable $callback)
    {
        foreach ($processes as $process) {
            $output = $process->getIncrementalOutput();
            if (strlen($output) !== 0) {
                $callback($output);
            }

            $errorOutput = $process->getIncrementalErrorOutput();
            if (strlen($errorOutput) !== 0) {
                $callback($errorOutput);
            }
        }
    }

    /**
     * @param Process[] $processes
     * @return int
     */
    protected function cumulativeExitCode(array $processes): int
    {
        foreach ($processes as $process) {
            if ($process->getExitCode() > 0) {
                return $process->getExitCode();
            }
        }
        return 0;
    }

    private static function stringifyVerbosity(int $verbosity): string
    {
        switch ($verbosity) {
            case OutputInterface::VERBOSITY_QUIET:
                return '-q';
            case OutputInterface::VERBOSITY_NORMAL:
                return '';
            case OutputInterface::VERBOSITY_VERBOSE:
                return '-v';
            case OutputInterface::VERBOSITY_VERY_VERBOSE:
                return '-vv';
            case OutputInterface::VERBOSITY_DEBUG:
                return '-vvv';
            default:
                throw new Exception('Unknown verbosity level: ' . $verbosity);
        }
    }
}
