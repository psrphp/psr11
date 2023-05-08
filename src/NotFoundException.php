<?php

declare(strict_types=1);

namespace PsrPHP\Psr11;

use Psr\Container\NotFoundExceptionInterface;

class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}
