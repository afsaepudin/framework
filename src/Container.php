<?php namespace Rakit\Framework;

use ArrayAccess;
use Iterator;
use Closure;
use ReflectionClass;
use ReflectionMethod;
use ReflectionFunction;
use InvalidArgumentException;

class Container implements ArrayAccess {

    protected $container = array();

    /**
     * Register a key into container
     * 
     * @param   string $key
     * @param   mixed $value
     * @return  Closure
     */
    public function register($key, $value)
    {
        $keys = explode(":", $key);

        // wrap non-closure value in a closure
        if(false === $value instanceof Closure) {
            // register classname into container (if value is object)
            if(is_object($value)) {
                $keys[] = get_class($value);
            }

            $value = function() use ($value) {
                return $value;
            };
        }

        // register keys in container
        foreach($keys as $k) {
            $this->container[$this->resolveKey($k)] = $value;
        }
    }

    /**
     * Protect anonymous function value
     * 
     * @param   Closure $fn
     * @return  Closure
     */
    public function protect(Closure $fn)
    {
        return function() use ($fn) {
            return $fn;
        };
    }

    /**
     * Wrap singleton service in a closure
     * 
     * @param   Closure $fn
     * @return  Closure
     */
    public function singleton(Closure $fn)
    {
        return function($container) use ($fn) {
            static $object;

            if(!$object) {
                $object = $fn($container);
            }

            return $object;
        };
    }

    /**
     * Check key
     * 
     * @param   string $key
     * @return  bool
     */
    public function has($key)
    {
        return isset($this->container[$key]);
    }

    /**
     * Get value for specified key in container
     * 
     * @param   string $key
     * @return  mixed
     */
    public function get($key)
    {
        if(!$this->has($key)) return null;

        $closure = $this->container[$key];
        $value = $closure($this);

        // register classname into container if not exists
        if(is_object($value)) {
            $class = get_class($value);
            if(!$this->has($class)) $this->register($class, $closure);
        }

        return $value;
    }

    /**
     * Remove a key in container
     * 
     * @param   string $key
     */
    public function remove($key)
    {
        unset($this->container[$key]);
    }

    /**
     * Make object with Injecting some dependencies into constructor
     *
     * @param   string $class
     * @param   array $args
     * @return  Object
     */
    public function make($class, array $args = array())
    {
        $class = $this->resolveKey($class);
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if(!empty($constructor)) {
            $parameters = $constructor->getParameters();
            $args = $this->resolveArguments($parameters, $args);
        }

        return $reflection->newInstanceArgs($args);
    }

    /**
     * Call closure or method with injecting parameters
     * 
     * @param   callable $callable
     * @param   array $args
     * @return  mixed
     */
    public function call($callable, array $args = array(), array $class_args = array())
    {
        if(!is_callable($callable)) {
            return new InvalidArgumentException("Callable must be callable, ".gettype($callable)." given");
        }

        if(is_array($callable)) {
            list($class, $method) = $callable;
            $object = is_object($class)? $class : $this->make($class, $class_args);
            $reflection = new ReflectionMethod($object, $method);
        } else {
            $reflection = new ReflectionFunction($callable);
        }

        $parameters = $reflection->getParameters();
        $args = $this->resolveArguments($parameters, $args);

        return call_user_func_array($callable, $args);
    }

    /**
     * Resolve key
     *
     * @param   string $key
     * @return  string resolved key
     */
    protected function resolveKey($key)
    {
        return trim($key, "\\");
    }

    /**
     * Build arguments from array ReflectionParameter
     *
     * @param   ReflectionParameter[] $parameters
     * @param   array $args
     * @return  array
     */
    public function resolveArguments(array $parameters, array $additional_args = array())
    {
        $resolved_args = [];
        $container = $this->container;
        foreach($additional_args as $arg_value) {
            if(is_object($arg_value)) {
                $container[get_class($arg_value)] = $arg_value;
            }
        }

        foreach($parameters as $i => $param) {
            $param_class = $param->getClass();
            if($param_class) {
                $classname = $param_class->getName();
                $resolved_args[] = $this->get($classname) ?: array_shift($additional_args);
            } elseif(!empty($additional_args)) {
                $resolved_args[] = array_shift($additional_args);
            }
        }

        return $resolved_args;
    }

    /**
     * ---------------------------------------------------------------
     * ArrayAccess interface methods
     * ---------------------------------------------------------------
     */
    public function offsetSet($key, $value) {
        return $this->register($key, $value);
    }

    public function offsetExists($key) {
        return $this->has($key);
    }

    public function offsetUnset($key) {
        return $this->remove($key);
    }

    public function offsetGet($key) {
        return $this->get($key);
    }

}