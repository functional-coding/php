<?php

namespace FunctionalCoding;

use ArrayObject;
use Closure;
use Illuminate\Support\Str;

abstract class Service
{
    public const BIND_NAME_EXP = '/\{\{([a-z0-9\_\.\*]+)\}\}/';
    protected ArrayObject $childs;
    protected ArrayObject $data;
    protected ArrayObject $errors;
    protected ArrayObject $inputs;
    protected ArrayObject $names;
    protected bool $processed;
    protected ArrayObject $validations;

    public function __construct(array $inputs = [], array $names = [])
    {
        $this->childs = new ArrayObject();
        $this->data = new ArrayObject();
        $this->errors = new ArrayObject();
        $this->inputs = new ArrayObject($inputs);
        $this->names = new ArrayObject($names);
        $this->validations = new ArrayObject();
        $this->processed = false;

        foreach ($this->inputs as $key => $value) {
            $this->validate($key);
        }
    }

    public static function getAllBindNames()
    {
        $arr = [];

        foreach ([...static::getAllTraits(), static::class] as $class) {
            $arr = array_merge($arr, $class::getBindNames());
        }

        return new ArrayObject($arr);
    }

    public static function getAllCallbacks()
    {
        $arr = [];

        foreach ([...static::getAllTraits(), static::class] as $class) {
            $arr = array_merge($arr, $class::getCallbacks());
        }

        return new ArrayObject($arr);
    }

    public static function getAllLoaders()
    {
        $arr = [];

        foreach ([...static::getAllTraits(), static::class] as $class) {
            $arr = array_merge($arr, $class::getLoaders());
        }

        return new ArrayObject($arr);
    }

    public static function getAllPromiseLists()
    {
        $arr = [];

        foreach ([...static::getAllTraits(), static::class] as $class) {
            $arr = array_merge_recursive($arr, $class::getPromiseLists());
        }

        return new ArrayObject($arr);
    }

    public static function getAllRuleLists()
    {
        $arr = [];

        foreach ([...static::getAllTraits(), static::class] as $class) {
            foreach ($class::getRuleLists() as $key => $ruleList) {
                foreach ($ruleList as $rule) {
                    if (isset($arr[$key][$rule])) {
                        throw new \Exception('duplicated rule exist in same key in '.static::class);
                    }

                    $arr[$key][$rule] = $class;
                }
            }
        }

        return new ArrayObject($arr);
    }

    public static function getAllTraits()
    {
        $arr = [];

        foreach (static::getTraits() as $class) {
            $arr = array_merge($arr, $class::getAllTraits()->getArrayCopy());
        }

        $arr = array_merge($arr, static::getTraits());
        $arr = array_unique($arr);

        return new ArrayObject($arr);
    }

    public static function getBindNames()
    {
        return [];
    }

    public static function getCallbacks()
    {
        return [];
    }

    public function getChilds()
    {
        return $this->childs;
    }

    public function getData()
    {
        $data = clone $this->data;

        $data->ksort();

        return $data;
    }

    public function getErrors()
    {
        return clone $this->errors;
    }

    public static function getLoaders()
    {
        return [];
    }

    public static function getPromiseLists()
    {
        return [];
    }

    public static function getRuleLists()
    {
        return [];
    }

    public function getTotalErrors()
    {
        $arr = $this->getErrors()->getArrayCopy();
        $errors = [];

        array_walk_recursive($arr, function ($value) use (&$errors) {
            $errors[] = $value;
        });

        foreach ($this->getChilds() as $child) {
            $errors = array_merge($errors, $child->getTotalErrors());
        }

        return $errors;
    }

    public static function getTraits()
    {
        return [];
    }

    public function getValidates()
    {
        $arr = $this->validations->getArrayCopy();

        ksort($arr);

        return new ArrayObject($arr);
    }

    public static function initService($value)
    {
        isset($value[1]) ?: $value[1] = [];
        isset($value[2]) ?: $value[2] = [];
        isset($value[3]) ?: $value[3] = null;

        $class = $value[0];
        $data = $value[1];
        $names = $value[2];
        $parent = $value[3];

        foreach ($data as $key => $value) {
            if ('' === $value) {
                unset($data[$key]);
            }
        }

        return new $class($data, $names, $parent);
    }

    public function inputs()
    {
        return clone $this->inputs;
    }

    public static function isInitable($value)
    {
        return is_array($value) && array_key_exists(0, $value) && is_string($value[0]) && is_a($value[0], Service::class, true);
    }

    public function run()
    {
        if (!$this->processed) {
            foreach (array_keys((array) $this->inputs()) as $key) {
                $this->validate($key);
            }

            foreach (array_keys((array) $this->getAllRuleLists()) as $key) {
                $this->validate(explode('.', $key)[0]);
            }

            foreach (array_keys((array) $this->getAllLoaders()) as $key) {
                $this->validate($key);
            }

            $this->processed = true;
        }

        if (empty($this->getTotalErrors()) && !$this->getData()->offsetExists('result')) {
            throw new \Exception('result data key is not exists in '.static::class);
        }

        if (!empty($this->getTotalErrors())) {
            return $this->resolveError();
        }

        return $this->getData()->offsetGet('result');
    }

    public function runAfterCommitCallbacks()
    {
        foreach ($this->childs as $child) {
            $child->runAfterCommitCallbacks();
        }

        $callbacks = array_filter($this->getAllCallbacks()->getArrayCopy(), function ($value) {
            return preg_match('/:after_commit$/', $value);
        }, ARRAY_FILTER_USE_KEY);

        foreach ($callbacks as $callback) {
            $this->resolve($callback);
        }
    }

    public static function setValidationErrorListResolver(Closure $resolver)
    {
        static::$validationErrorListResolver = $resolver;
    }

    protected function getAvailableData($key)
    {
        $key = explode('.', $key)[0];
        $data = $this->getData();
        $loader = $this->getAllLoaders()->offsetExists($key) ? $this->getAllLoaders()->offsetGet($key) : null;

        if ($data->offsetExists($key)) {
            return $data;
        }

        if ($this->inputs()->offsetExists($key)) {
            $value = $this->inputs()->offsetGet($key);
            $loader = function () use ($value) {
                return $value;
            };
        }

        if (empty($loader)) {
            return $data;
        }

        $value = $this->resolve($loader);

        if ($this->isResolveError($value)) {
            return $data;
        }

        $isBatchService = is_array($value) && array_values($value) === $value && !empty($value) && static::isInitable($value[0]);
        $values = $isBatchService ? $value : [$value];
        $hasError = false;

        foreach ($values as $i => $v) {
            if (!static::isInitable($v)) {
                break;
            }

            isset($v[2]) ?: $v[2] = [];
            isset($v[3]) ?: $v[3] = $this;

            foreach ($v[2] as $k => $name) {
                $v[2][$k] = $this->resolveBindName($name);
            }

            $service = static::initService($v);
            $resolved = $service->run();

            $this->childs->offsetSet($isBatchService ? $key.'.'.$i : $key, $service);

            $values[$i] = $resolved;

            if ($this->isResolveError($resolved)) {
                unset($values[$i]);
                $hasError = true;

                $this->validations->offsetSet($key, false);
            }
        }

        if (!$hasError) {
            $data->offsetSet($key, $isBatchService ? $values : $values[0]);
        }

        return $data;
    }

    protected function getAvailableRuleList($key)
    {
        $ruleLists = [
            $key => $this->getAllRuleLists()->offsetExists($key) ? $this->getAllRuleLists()->offsetGet($key) : [],
        ];
        $mainKey = explode('.', $key)[0];

        if (!$this->getAllLoaders()->offsetExists($mainKey) && !$this->inputs->offsetExists($mainKey)) {
            $ruleLists[$key] = array_filter($ruleLists[$key], function ($rule) {
                return $this->isRequiredRule($rule);
            }, ARRAY_FILTER_USE_KEY);
        }

        if (!empty($ruleLists[$key])) {
            if ($this->getAllRuleLists()->offsetExists($key.'.*')) {
                $ruleLists[$key.'.*'] = $this->getAllRuleLists()->offsetGet($key.'.*');
            }

            $this->names->offsetSet($key, $this->resolveBindName('{{'.$key.'}}'));
        }

        foreach ($ruleLists as $k => $ruleList) {
            foreach ($ruleList as $rule => $class) {
                $bindKeys = $this->getBindKeys($rule);

                foreach ($bindKeys as $bindKey) {
                    $this->names->offsetSet($bindKey, $this->resolveBindName('{{'.$bindKey.'}}'));

                    if (!$this->validate($bindKey)) {
                        $this->validations->offsetSet($mainKey, false);

                        unset($ruleList[$rule]);

                        continue;
                    }

                    if (!$this->isRequiredRule($rule) && !$this->getData()->offsetExists($bindKey)) {
                        throw new \Exception('"'.$bindKey.'" key required rule not exists in '.static::class);
                    }
                }

                if (array_key_exists($rule, $ruleList)) {
                    unset($ruleList[$rule]);
                    $ruleList[preg_replace(static::BIND_NAME_EXP, '$1', $rule)] = $class;
                }
            }
            $ruleLists[$k] = $ruleList;
        }

        return $ruleLists;
    }

    protected function getBindKeys(string $str)
    {
        $matches = [];

        preg_match_all(static::BIND_NAME_EXP, $str, $matches);

        return $matches[1];
    }

    protected function getClosureDependencies(Closure $func)
    {
        if (null == $func) {
            return [];
        }

        $deps = [];
        $params = (new \ReflectionFunction($func))->getParameters();

        foreach ($params as $i => $param) {
            $deps[] = Str::snake($param->name);
        }

        return $deps;
    }

    protected function getOrderedCallbackKeys($key)
    {
        $promiseKeys = array_filter(array_keys($this->getAllPromiseLists()->getArrayCopy()), function ($value) use ($key) {
            return preg_match('/^'.$key.'\\./', $value);
        });
        $allKeys = array_filter(array_keys($this->getAllCallbacks()->getArrayCopy()), function ($value) use ($key) {
            return preg_match('/^'.$key.'\\./', $value);
        });
        $orderedKeys = $this->getShouldOrderedCallbackKeys($promiseKeys);
        $restKeys = array_diff($allKeys, $orderedKeys);

        return array_merge($orderedKeys, $restKeys);
    }

    protected function getShouldOrderedCallbackKeys($keys)
    {
        $arr = [];
        $rtn = [];

        foreach ($keys as $key) {
            $deps = $this->getAllPromiseLists()->offsetExists($key) ? $this->getAllPromiseLists()->offsetGet($key) : [];
            $list = $this->getShouldOrderedCallbackKeys($deps);
            $list = array_merge($list, [$key]);
            $arr = array_merge($list, $arr);
        }

        foreach ($arr as $value) {
            $rtn[$value] = null;
        }

        return array_keys($rtn);
    }

    abstract protected function getValidationErrors($locale, $key, $data, $ruleLists, $names);

    protected function isRequiredRule($rule)
    {
        return preg_match('/^required/', $rule);
    }

    protected function isResolveError($value)
    {
        $errorClass = get_class($this->resolveError());

        return is_object($value) && $value instanceof $errorClass;
    }

    protected function resolve($func)
    {
        $resolver = Closure::bind($func, $this);
        $depNames = $this->getClosureDependencies($func);
        $depVals = [];
        $params = (new \ReflectionFunction($resolver))->getParameters();

        foreach ($depNames as $i => $depName) {
            if ($this->data->offsetExists($depName)) {
                $depVals[] = $this->data->offsetGet($depName);
            } elseif ($params[$i]->isDefaultValueAvailable()) {
                $depVals[] = $params[$i]->getDefaultValue();
            } else {
                // must not throw exception, but only return
                return $this->resolveError();
            }
        }

        return call_user_func_array($resolver, $depVals);
    }

    protected function resolveBindName(string $name)
    {
        while ($boundKeys = $this->getBindKeys($name)) {
            $key = $boundKeys[0];
            $pattern = '/\{\{'.$key.'\}\}/';
            $bindNames = new ArrayObject(array_merge(
                $this->getAllBindNames()->getArrayCopy(),
                $this->names->getArrayCopy(),
            ));
            $bindName = $bindNames->offsetExists($key) ? $bindNames->offsetGet($key) : null;

            if (null == $bindName) {
                throw new \Exception('"'.$key.'" name not exists in '.static::class);
            }

            $replace = $this->resolveBindName($bindName);
            $name = preg_replace($pattern, $replace, $name, 1);
        }

        return $name;
    }

    protected function resolveError()
    {
        return new \Error('can\'t be resolve');
    }

    protected function validate($key)
    {
        if (count(explode('.', $key)) > 1) {
            throw new \Exception('does not support validation with child key in '.static::class);
        }

        if ($this->validations->offsetExists($key)) {
            return $this->validations->offsetGet($key);
        }

        $promiseList = $this->getAllPromiseLists()->offsetExists($key) ? $this->getAllPromiseLists()->offsetGet($key) : [];

        foreach ($promiseList as $promise) {
            $segs = explode(':', $promise);
            $promiseKey = $segs[0];
            $isStrict = isset($segs[1]) && 'strict' == $segs[1];

            // isStrict mode is deprecated
            // if (!$this->validate($promiseKey) && $isStrict) {
            if (!$this->validate($promiseKey)) {
                $this->validations->offsetSet($key, false);

                return false;
            }
        }

        $loader = $this->getAllLoaders()->offsetExists($key) ? $this->getAllLoaders()->offsetGet($key) : null;
        $deps = $loader ? $this->getClosureDependencies($loader) : [];

        foreach ($deps as $dep) {
            if (!$this->validate($dep)) {
                $this->validations->offsetSet($key, false);
            }
        }

        if ($this->validations->offsetExists($key) && false === $this->validations->offsetGet($key)) {
            return false;
        }

        $ruleLists = $this->getAvailableRuleList($key);
        $data = $this->getAvailableData($key);

        foreach ($ruleLists as $ruleKey => $ruleList) {
            $errors = $this->getValidationErrors($ruleKey, $data->getArrayCopy(), $ruleList, $this->names->getArrayCopy());

            if (!empty($errors->messages())) {
                $this->validations->offsetSet($ruleKey, false);

                foreach ($errors->messages() as $messageList) {
                    $errors = $this->errors->offsetExists($ruleKey) ? $this->errors->offsetGet($ruleKey) : [];
                    $this->errors->offsetSet($ruleKey, array_merge($errors, $messageList));
                }

                $this->validations->offsetSet($key, false);

                return false;
            }
        }

        if ($this->validations->offsetExists($key) && false === $this->validations->offsetGet($key)) {
            return false;
        }

        if ($data->offsetExists($key)) {
            $this->data->offsetSet($key, $data->offsetGet($key));
        }

        $this->validations->offsetSet($key, true);

        $orderedCallbackKeys = $this->getOrderedCallbackKeys($key);

        foreach ($orderedCallbackKeys as $callbackKey) {
            $callback = $this->getAllCallbacks()->offsetGet($callbackKey);
            $deps = $this->getClosureDependencies($callback);

            foreach ($deps as $dep) {
                if (!$this->validate($dep)) {
                    $this->validations->offsetSet($key, false);
                }
            }

            if (!preg_match('/:after_commit$/', $callbackKey)) {
                $this->resolve($callback);
            }
        }

        if (false === $this->validations->offsetGet($key)) {
            return false;
        }

        return true;
    }
}
