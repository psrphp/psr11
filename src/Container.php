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
    private $callback_list = [];
    private $cache_list = [];
    private $no_share_list = [];

    public function has(string $id): bool
    {
        if (array_key_exists($id, $this->item_list) || class_exists($id)) {
            return true;
        }
        return false;
    }

    public function get(string $id, bool $new = false)
    {
        if (!$new) {
            if (array_key_exists($id, $this->cache_list)) {
                return $this->cache_list[$id];
            }
        }

        if (isset($this->item_list[$id])) {
            $args = $this->reflectArguments($this->item_list[$id]);
            $obj = call_user_func($this->item_list[$id], ...$args);
        } elseif (class_exists($id)) {
            $reflector = new ReflectionClass($id);
            $args = $reflector->getConstructor() === null ? [] : $this->reflectArguments([$id, '__construct']);
            $obj = $reflector->newInstanceArgs($args);
        } else {
            throw new NotFoundException(
                sprintf('Alias (%s) is not an existing class and therefore cannot be resolved', $id)
            );
        }

        foreach ($this->callback_list[$id] ?? [] as $vo) {
            $args = $this->reflectArguments($vo, [$id => $obj]);
            $temp = call_user_func($vo, ...$args);
            $obj =  is_null($temp) ? $obj : $temp;
        }

        if (!in_array($id, $this->no_share_list)) {
            $this->cache_list[$id] = $obj;
        }

        return $obj;
    }

    public function set(string $id, callable $callable, bool $share = true): self
    {
        if (is_array($callable) && is_string($callable[0]) && class_exists($callable[0])) {
            $type = ReflectionClass::class;
            $reflector = new ReflectionClass($callable[0]);
            $params = $reflector->getMethod($callable[1])->getParameters();
        } else {
            $type = ReflectionFunction::class;
            $reflector = new ReflectionFunction(Closure::fromCallable($callable));
            $params = $reflector->getParameters();
        }

        $find = false;
        foreach ($params as $param) {
            if (null !== $type = $param->getType()) {
                if ($type->getName() === $id) {
                    $find = true;
                    break;
                }
            }
        }

        if ($find) {
            $this->callback_list[$id][] = $callable;
        } else {
            $this->item_list[$id] = $callable;
        }
        unset($this->cache_list[$id]);

        $this->setShare($id, $share);
        return $this;
    }

    public function setShare(string $id, bool $share): self
    {
        if ($share) {
            if (false !== $key = array_search($id, $this->no_share_list)) {
                unset($this->no_share_list[$key]);
            }
        } else {
            if (!in_array($id, $this->no_share_list)) {
                $this->no_share_list[] = $id;
            }
        }
        return $this;
    }

    public function reflectArguments($callable, array $default = []): array
    {
        if (is_array($callable) && is_string($callable[0]) && class_exists($callable[0])) {
            $reflector = new ReflectionClass($callable[0]);
            $params = $reflector->getMethod($callable[1])->getParameters();
        } else {
            $reflector = new ReflectionFunction(Closure::fromCallable($callable));
            $params = $reflector->getParameters();
        }

        $res = [];
        foreach ($params as $param) {
            $res[] = $this->getParam($param, $default);
        }
        return $res;
    }

    private function getParam(ReflectionParameter $param, array $default = [])
    {
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
            'Unable to resolve a value for parameter `$%s` at %s on line %s-%s',
            $param->getName(),
            $param->getDeclaringFunction()->getFileName(),
            $param->getDeclaringFunction()->getStartLine(),
            $param->getDeclaringFunction()->getEndLine(),
        ));
    }
}
