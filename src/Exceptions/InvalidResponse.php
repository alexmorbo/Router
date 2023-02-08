<?php

namespace React\Router\Http\Exceptions;

class InvalidResponse extends \Exception {
    public function __construct() {
        parent::__construct("The route controller must return an implementation of \React\Promise\PromiseInterface");
    }
}