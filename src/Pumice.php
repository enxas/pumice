<?php

declare(strict_types=1);

namespace Enxas;

class Pumice
{
	private int $maxConcurrent = 0;
	private array $tasks = [];
	private array $processes = [];
	private array $pipes = [];
	private array $callbacks = [];
	private int $taskIndex = 0;

	const STDIN  = 0;
	const STDOUT = 1;
	const STDERR = 2;

	private array $descriptorSpec = [
		self::STDIN => ["pipe", "r"],
		self::STDOUT => ["pipe", "w"],
		self::STDERR => ["pipe", "w"],
	];

	private function __construct(int|null $maxConcurrent = null)
	{
		$this->maxConcurrent = $maxConcurrent ?? $this->getProcessorCores();
	}

	private function getProcessorCores(): int
	{
		return (int) match (PHP_OS_FAMILY) {
			'Windows' => shell_exec('echo %NUMBER_OF_PROCESSORS%'),
			'Linux' => shell_exec('nproc'),
			'Darwin' => shell_exec('sysctl -n hw.ncpu'), // MacOS
			default => 8,
		};
	}

	private function startNewTask(): void
	{
		if ($this->taskIndex >= count($this->tasks)) return;

		$task = $this->tasks[$this->taskIndex];
		$index = $this->taskIndex;

		$serializedTask = base64_encode(serialize($task));
		$cmd = ["php", "-f", __DIR__ . "/worker.php", $serializedTask];

		$process = proc_open($cmd, $this->descriptorSpec, $this->pipes[$index]);

		if (is_resource($process)) {
			stream_set_blocking($this->pipes[$index][self::STDOUT], false);
			stream_set_blocking($this->pipes[$index][self::STDERR], false);

			$this->processes[$index] = $process;
		}

		$this->taskIndex++;
	}

	public static function create(int|null $maxConcurrent = null): self
	{
		return new self($maxConcurrent);
	}

	public function add(callable $task): self
	{
		$index = count($this->tasks);
		$this->tasks[] = $task;
		$this->callbacks[$index] = ['then' => null, 'catch' => null];

		return $this;
	}

	public function then(callable $callback): self
	{
		$index = count($this->tasks) - 1;
		$this->callbacks[$index]['then'] = $callback;

		return $this;
	}

	public function catch(callable $callback): self
	{
		$index = count($this->tasks) - 1;
		$this->callbacks[$index]['catch'] = $callback;

		return $this;
	}

	public function wait(): void
	{
		// Start a batch of $maxConcurrent tasks
		while (count($this->processes) < $this->maxConcurrent && $this->taskIndex < count($this->tasks)) {
			$this->startNewTask();
		}

		while (!empty($this->processes)) {
			$readStreams = array_map(fn($pipes) => $pipes[self::STDOUT], $this->pipes);
			$writeStreams = null;
			$exceptStreams = null;

			// Wait until one or more streams are ready to read
			if (stream_select($readStreams, $writeStreams, $exceptStreams, null) > 0) {
				foreach ($this->processes as $i => $process) {
					if (in_array($this->pipes[$i][self::STDOUT], $readStreams, true)) {
						$encodedOutput = stream_get_contents($this->pipes[$i][self::STDOUT]);

						if ($encodedOutput !== false && $encodedOutput !== '') {
							$output = unserialize(base64_decode($encodedOutput));

							// Cleanup process & pipes
							fclose($this->pipes[$i][self::STDIN]);
							fclose($this->pipes[$i][self::STDOUT]);
							fclose($this->pipes[$i][self::STDERR]);
							proc_close($process);
							unset($this->processes[$i]);
							unset($this->pipes[$i]);

							// Handle success/failure callbacks
							if (isset($output['status']) && $output['status'] === 'error') {
								if ($this->callbacks[$i]['catch']) {
									call_user_func($this->callbacks[$i]['catch'], new \Exception($output['message']));
								}
							} elseif ($this->callbacks[$i]['then']) {
								call_user_func($this->callbacks[$i]['then'], $output['data']);
							}

							// Start a new task if available
							$this->startNewTask();
						}
					}
				}
			}
		}
	}
}
