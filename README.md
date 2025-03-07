## About

Pumice is a lightweight, asynchronous PHP library with no dependencies. It allows you to specify the maximum number of tasks to run concurrently and provides responses once the tasks are completed.

## Installation

Add these values to your `composer.json` then run `composer update`.

```json
"repositories": [
	{
		"type": "vcs",
		"url": "https://github.com/enxas/pumice"
	}
],
"require": {
	"enxas/pumice": "dev-main"
}
```

## Usage

Create some worker class like this:

```php
class StuffCalculator
{
	public function calculate(): float
	{
		$result = 0;

		for ($i = 0; $i < 10_000_000; $i++) {
			$result += sqrt($i) * log($i + 1) * sin($i) * cos($i) * exp($i % 10);
		}

		return $result;
	}
}
```

Then call it like this:

```php
use Enxas\Pumice;

$pumice = Pumice::create();
$total = 0;

for ($i = 0; $i < 15; $i++) {
	$pumice
		->add([new StuffCalculator, 'calculate'])
		->then(function (mixed $output) use (&$total) {
			$total += $output;
		})->catch(function (Throwable $exception) {
			echo "Error: {$exception->getMessage()}\n";
		});
}

$pumice->wait();

echo $total;
```

`Pumice::create(<number>);` accepts an argument specifying the number of processes to run concurrently. If no value is provided, it defaults to utilizing all available CPU threads.
