<?php

namespace StarterTest36;

class Example
{
    public function __construct($parameter)
    {
        $this->parameter = $parameter;
    }

    public function multiply($value)
    {
        return $this->parameter * $value;
    }
}
