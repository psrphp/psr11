<?php

declare(strict_types=1);

namespace PsrPHP\Psr11;

use OutOfBoundsException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;

class Container implements ContainerInterface
{
    private $item_list = [];
    private $item_cache_list = [];
    private $no_share_list = [];

    public function has(string $id): bool
    {
        if (array_key_exists($id, $this->item_list)) {
            return true;
        }
        if ($reflector = $this->getReflectionClass($id)) {
            if ($reflector->isInstantiable()) {
                return true;
            }
        }
        return false;
    }

    public function get(string $id, bool $new = false)
    {
        if (!$new) {
            if (array_key_exists($id, $this->item_cache_list)) {
                return $this->item_cache_list[$id];
            }
        }
        if (array_key_exists($id, $this->item_list)) {

            if (is_array($this->item_list[$id])) {
                $args = $this->reflectArguments(new ReflectionMethod(...$this->item_list[$id]));
            } elseif (is_object($this->item_list[$id])) {
                $args = $this->reflectArguments(new ReflectionMethod($this->item_list[$id], '__invoke'));
            } elseif (is_string($this->item_list[$id]) && strpos($this->item_list[$id], '::')) {
                $args = $this->reflectArguments(new ReflectionMethod($this->item_list[$id]));
            } else {
                $args = $this->reflectArguments(new ReflectionFunction($this->item_list[$id]));
            }

            $obj = call_user_func($this->item_list[$id], ...$args);
            if (!in_array($id, $this->no_share_list)) {
                $this->item_cache_list[$id] = $obj;
            }
            return $obj;
        }
        if ($reflector = $this->getReflectionClass($id)) {
            if ($reflector->isInstantiable()) {
                $construct = $reflector->getConstructor();
                $obj = $reflector->newInstanceArgs($construct === null ? [] : $this->reflectArguments($construct));
                if (!in_array($id, $this->no_share_list)) {
                    $this->item_cache_list[$id] = $obj;
                }
                return $obj;
            }
        }
        throw new NotFoundException(
            sprintf('Alias (%s) is not an existing class and therefore cannot be resolved', $id)
        );
    }

    public function set(string $id, callable $callback, bool $share = true): self
    {
        $this->item_list[$id] = $callback;
        unset($this->item_cache_list[$id]);
        if (!$share) {
            $this->noShare($id);
        }
        return $this;
    }

    public function noShare(string $id): self
    {
        unset($this->item_cache_list[$id]);
        if (!in_array($id, $this->no_share_list)) {
            $this->no_share_list[] = $id;
        }
        return $this;
    }

    public function reflectArguments(ReflectionFunctionAbstract $method, array $args = []): array
    {
        return array_map(function (ReflectionParameter $param) use ($method, $args) {

            $name = $param->getName();
            if (array_key_exists($name, $args)) {
                return $args[$name];
            }

            $type = $param->getType();

            if ($type === null) {
                if ($this->has($param->getName())) {
                    return $this->get($param->getName());
                }
            } else {
                /**
                 * @var ReflectionNamedType $type
                 */
                if (!$type->isBuiltin()) {
                    if ($this->has($type->getName())) {
                        $result = $this->get($type->getName());
                        $type_name = $type->getName();
                        if ($result instanceof $type_name) {
                            return $result;
                        }
                    }
                }
            }

            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }

            if ($param->isOptional()) {
                return;
            }

            throw new OutOfBoundsException(sprintf(
                'Unable to resolve a value for parameter `$%s` at %s::%s',
                $param->getName(),
                $method->getFileName(),
                $method->getName()
            ));
        }, $method->getParameters());
    }

    private function getReflectionClass(string $id): ?ReflectionClass
    {
        static $reflectors = [];
        if (!isset($reflectors[$id])) {
            if (class_exists($id)) {
                $reflectors[$id] = new ReflectionClass($id);
            } else {
                return null;
            }
        }
        return $reflectors[$id];
    }
}
