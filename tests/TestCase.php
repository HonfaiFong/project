<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

abstract class TestCase extends PhpUnitTestCase
{
    protected function validationFactory(): Factory
    {
        return new Factory(new Translator(new ArrayLoader(), 'en'));
    }
}
