<?php

declare(strict_types=1);

namespace PsrPHP\Psr11;

use Closure;
use OutOfBoundsException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionParameter;

class Container implements ContainerInterface
{
    private $item_list = [];
    private $item_cache_list = [];
    private $no_share_list = [];
    private $callback_list = [];

    public function has(string $id): bool
    {
        if (array_key_exists($id, $this->item_list)) {
            return true;
        }
        if (!class_exists($id)) {
            return false;
        }
        return (new ReflectionClass($id))->isInstantiable();
    }

    public function get(string $id, bool $new = false)
    {
        if (!$new) {
            if (array_key_exists($id, $this->item_cache_list)) {
                return $this->item_cache_list[$id];
            }
        }
        if (array_key_exists($id, $this->item_list)) {
            $args = $this->reflectArguments($this->item_list[$id]);
            $obj = call_user_func($this->item_list[$id], ...$args);

            if (!in_array($id, $this->no_share_list)) {
                $this->item_cache_list[$id] = &$obj;
            }

            foreach ($this->callback_list[$id] ?? [] as $callback) {
                $args2 = $this->reflectArguments($callback, [$id => &$obj]);
                $obj = call_user_func($callback, ...$args2) ?: $obj;
            }

            return $obj;
        }

        if (class_exists($id)) {
            $reflector = new ReflectionClass($id);
            if ($reflector->isInstantiable()) {
                $arg = $reflector->getConstructor() === null ? [] : $this->reflectArguments([$id, '__construct']);
                $obj = $reflector->newInstanceArgs($arg);

                if (!in_array($id, $this->no_share_list)) {
                    $this->item_cache_list[$id] = &$obj;
                }

                foreach ($this->callback_list[$id] ?? [] as $callback) {
                    $args2 = $this->reflectArguments($callback, [$id => &$obj]);
                    $obj = call_user_func($callback, ...$args2) ?: $obj;
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

    public function onInstance(string $id, callable $callback)
    {
        if (!isset($this->callback_list[$id])) {
            $this->callback_list[$id] = [];
        }
        $this->callback_list[$id][] = $callback;
    }

    public function reflectArguments($callable, array $default = []): array
    {
        if (is_array($callable) && is_string($callable[0]) && class_exists($callable[0])) {
            $reflect = new ReflectionClass($callable[0]);
            $params = $reflect->getMethod($callable[1])->getParameters();
        } else {
            $reflect = new ReflectionFunction(Closure::fromCallable($callable));
            $params = $reflect->getParameters();
        }

        return array_map(function (ReflectionParameter $param) use ($reflect, $default) {
            $type = $param->getType();
            if ($type === null) {
                $param_name = $param->getName();

                if (array_key_exists($param_name, $default)) {
                    return $default[$param_name];
                }

                if ($this->has($param_name)) {
                    return $this->get($param_name);
                }
            } else {
                $type_name = $type->getName();

                if (array_key_exists($type_name, $default)) {
                    return $default[$type_name];
                }

                if (!$type->isBuiltin()) {
                    if ($this->has($type_name)) {
                        $result = $this->get($type_name);
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
                $reflect->getFileName(),
                $reflect->getName()
            ));
        }, $params);
    }
}
