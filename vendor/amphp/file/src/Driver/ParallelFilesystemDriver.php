<?php declare(strict_types=1);

namespace Amp\File\Driver;

use Amp\File\FilesystemDriver;
use Amp\File\FilesystemException;
use Amp\File\Internal;
use Amp\Future;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\DelegatingWorkerPool;
use Amp\Parallel\Worker\LimitedWorkerPool;
use Amp\Parallel\Worker\TaskFailureThrowable;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\WorkerException;
use Amp\Parallel\Worker\WorkerPool;
use function Amp\async;

final class ParallelFilesystemDriver implements FilesystemDriver
{
    public const DEFAULT_WORKER_LIMIT = 8;

    private readonly WorkerPool $pool;

    /** @var positive-int Maximum number of workers to use for open files. */
    private readonly int $workerLimit;

    /** @var \WeakMap<Worker, int> */
    private \WeakMap $workerStorage;

    /** @var Future<Worker>|null Pending worker request */
    private ?Future $pendingWorker = null;

    /**
     * @param WorkerPool|null $pool Custom worker pool to use for file workers. If null, a new pool is created.
     * @param int|null $workerLimit [Deprecated] Maximum number of workers to use from the pool for open files. Instead
     *      of using this parameter, provide a pool with a limited number using an instance of {@see LimitedWorkerPool}
     *      such as {@see ContextWorkerPool}.
     */
    public function __construct(?WorkerPool $pool = null, ?int $workerLimit = null)
    {
        /** @var \WeakMap<Worker, int> For Psalm. */
        $this->workerStorage = new \WeakMap();

        if ($workerLimit !== null) {
            \trigger_error(
                'The $workerLimit parameter is deprecated and will be removed in the next major version.' .
                ' To limit the number of workers used from the given pool, use an instance of ' .
                LimitedWorkerPool::class . ' instead, such as ' . ContextWorkerPool::class . ' or ' .
                DelegatingWorkerPool::class,
                \E_USER_DEPRECATED,
            );
        }

        $workerLimit ??= $pool instanceof LimitedWorkerPool ? $pool->getWorkerLimit() : self::DEFAULT_WORKER_LIMIT;
        if ($workerLimit <= 0) {
            throw new \ValueError("Worker limit must be a positive integer");
        }

        $this->pool = $pool ?? new ContextWorkerPool($workerLimit);
        $this->workerLimit = $workerLimit;
    }

    public function openFile(string $path, string $mode): ParallelFile
    {
        $worker = $this->selectWorker();

        $workerStorage = $this->workerStorage;
        $worker = new Internal\FileWorker($worker, static function (Worker $worker) use ($workerStorage): void {
            if (!isset($workerStorage[$worker])) {
                return;
            }

            if (($workerStorage[$worker] -= 1) === 0 || !$worker->isRunning()) {
                unset($workerStorage[$worker]);
            }
        });

        try {
            [$id, $size, $mode] = $worker->execute(new Internal\FileTask("fopen", [$path, $mode]));
        } catch (TaskFailureThrowable $exception) {
            throw new FilesystemException("Could not open file", $exception);
        } catch (WorkerException $exception) {
            throw new FilesystemException("Could not send open request to worker", $exception);
        }

        return new ParallelFile($worker, $id, $path, $size, $mode);
    }

    private function selectWorker(): Worker
    {
        $this->pendingWorker?->await(); // Wait for any currently pending request for a worker.

        if ($this->workerStorage->count() < $this->workerLimit) {
            $this->pendingWorker = async($this->pool->getWorker(...));
            $worker = $this->pendingWorker->await();

            $this->workerStorage[$worker] = 1;

            return $worker;
        }

        $max = \PHP_INT_MAX;
        foreach ($this->workerStorage as $storedWorker => $count) {
            if ($count <= $max) {
                $worker = $storedWorker;
                $max = $count;
            }
        }

        \assert(isset($worker) && $worker instanceof Worker);

        if (!$worker->isRunning()) {
            unset($this->workerStorage[$worker]);
            return $this->selectWorker();
        }

        $this->workerStorage[$worker] += 1;

        return $worker;
    }

    public function deleteFile(string $path): void
    {
        $this->runFileTask(new Internal\FileTask("deleteFile", [$path]));
    }

    public function getStatus(string $path): ?array
    {
        return $this->runFileTask(new Internal\FileTask("getStatus", [$path]));
    }

    public function move(string $from, string $to): void
    {
        $this->runFileTask(new Internal\FileTask("move", [$from, $to]));
    }

    public function createHardlink(string $target, string $link): void
    {
        $this->runFileTask(new Internal\FileTask("createHardlink", [$target, $link]));
    }

    public function createSymlink(string $target, string $link): void
    {
        $this->runFileTask(new Internal\FileTask("createSymlink", [$target, $link]));
    }

    public function resolveSymlink(string $target): string
    {
        return $this->runFileTask(new Internal\FileTask("resolveSymlink", [$target]));
    }

    public function createDirectory(string $path, int $mode = 0777): void
    {
        $this->runFileTask(new Internal\FileTask("createDirectory", [$path, $mode]));
    }

    public function createDirectoryRecursively(string $path, int $mode = 0777): void
    {
        $this->runFileTask(new Internal\FileTask("createDirectoryRecursively", [$path, $mode]));
    }

    public function listFiles(string $path): array
    {
        return $this->runFileTask(new Internal\FileTask("listFiles", [$path]));
    }

    public function deleteDirectory(string $path): void
    {
        $this->runFileTask(new Internal\FileTask("deleteDirectory", [$path]));
    }

    public function changePermissions(string $path, int $mode): void
    {
        $this->runFileTask(new Internal\FileTask("changePermissions", [$path, $mode]));
    }

    public function changeOwner(string $path, ?int $uid, ?int $gid): void
    {
        $this->runFileTask(new Internal\FileTask("changeOwner", [$path, $uid, $gid]));
    }

    public function getLinkStatus(string $path): ?array
    {
        return $this->runFileTask(new Internal\FileTask("getLinkStatus", [$path]));
    }

    public function touch(string $path, ?int $modificationTime, ?int $accessTime): void
    {
        $this->runFileTask(
            new Internal\FileTask(
                "touch",
                [$path, $modificationTime, $accessTime]
            )
        );
    }

    public function read(string $path): string
    {
        return $this->runFileTask(new Internal\FileTask("read", [$path]));
    }

    public function write(string $path, string $contents): void
    {
        $this->runFileTask(new Internal\FileTask("write", [$path, $contents]));
    }

    private function runFileTask(Internal\FileTask $task): mixed
    {
        try {
            return $this->pool->submit($task)->await();
        } catch (TaskFailureThrowable $exception) {
            throw new FilesystemException("The file operation failed", $exception);
        } catch (WorkerException $exception) {
            throw new FilesystemException("Could not send the file task to worker", $exception);
        }
    }
}
