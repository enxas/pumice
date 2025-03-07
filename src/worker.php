<?php

declare(strict_types=1);

$autoloadPaths = [
	__DIR__ . '/../../../autoload.php',
	__DIR__ . '/../vendor/autoload.php',
	__DIR__ . '/../../vendor/autoload.php',
	__DIR__ . '/../../../vendor/autoload.php',
];

// Attempt to find the Composer autoload file dynamically
foreach ($autoloadPaths as $autoloadPath) {
	if (file_exists($autoloadPath)) {
		require_once $autoloadPath;
		break;
	}
}

// Ensure autoload is loaded
if (!class_exists(\Composer\Autoload\ClassLoader::class)) {
	fwrite(STDERR, "Error: Composer autoload not found.\n");
	exit(1);
}

if ($argc >= 2) {
	try {
		$task = unserialize(base64_decode($argv[1]));

		if (!is_callable($task)) {
			throw new Exception("Task is not callable.");
		}

		// Execute task
		$output = $task();

		// Wrap successful output
		$response = [
			'status' => 'success',
			'data'   => $output
		];
	} catch (Throwable $e) {
		// Wrap error output
		$response = [
			'status'  => 'error',
			'message' => $e->getMessage()
		];
	}

	// Always return a structured response
	echo base64_encode(serialize($response));

	flush();
}
