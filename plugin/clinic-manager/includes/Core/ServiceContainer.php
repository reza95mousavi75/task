<?php

namespace MS\Core;

/**
 * Very small service container for sharing singletons across modules.
 */
class ServiceContainer
{
    /** @var array<string, callable|object> */
    private $bindings = [];

    /** @var array<string, object> */
    private $instances = [];

    /**
     * Bind a service factory.
     *
     * @param string        $id
     * @param callable|object $concrete
     */
    public function bind($id, $concrete)
    {
        $this->bindings[$id] = $concrete;
    }

    /**
     * Resolve a service by id.
     *
     * @param string $id
     *
     * @return mixed
     */
    public function get($id)
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (! isset($this->bindings[$id])) {
            return null;
        }

        $binding = $this->bindings[$id];

        if (is_callable($binding)) {
            $instance = $binding($this);
        } else {
            $instance = $binding;
        }

        $this->instances[$id] = $instance;

        return $instance;
    }
}
