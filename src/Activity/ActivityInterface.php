<?php

namespace Hibernator\Activity;

interface ActivityInterface
{
    /**
     * Execute the activity logic.
     * Use a serializable return value (array, string, int, bool).
     *
     * @return mixed
     */
    public function handle(): mixed;
}
