<?php

namespace Hibernator\Tests;

use Hibernator\Activity\ActivityInterface;

class MockActivity implements ActivityInterface
{
    private string $input;

    public function __construct(string $input)
    {
        $this->input = $input;
    }

    public function handle(): mixed
    {
        return "Processed: " . $this->input;
    }
}
