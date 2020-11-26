<?php

namespace Doctrine\Common\Proxy;

use Doctrine\Common\Proxy\Exception\InvalidArgumentException;
use Doctrine\Common\Proxy\Exception\UnexpectedValueException;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\Persistence\Mapping\ClassMetadata;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;

use function array_combine;
use function array_diff;
use function array_key_exists;
use function array_map;
use function array_slice;
use function assert;
use function call_user_func;
use function chmod;
use function class_exists;
use function dirname;
use function explode;
use function file;
use function file_put_contents;
use function implode;
use function in_array;
use function interface_exists;
use function is_callable;
use function is_dir;
use function is_string;
use function is_writable;
use function lcfirst;
use function ltrim;
use function method_exists;
use function mkdir;
use function preg_match;
use function preg_match_all;
use function rename;
use function rtrim;
use function sprintf;
use function str_replace;
use function strrev;
use function strtolower;
use function strtr;
use function substr;
use function trim;
use function uniqid;
use function var_export;

use const DIRECTORY_SEPARATOR;

/**
 * This factory is used to generate proxy classes.
 * It builds proxies from given parameters, a template and class metadata.
 */
class ProxyGenerator
{
    /**
     * Used to match very simple id methods that don't need
     * to be decorated since the identifier is known.
     */
    public const PATTERN_MATCH_ID_METHOD = '((public\s+)?(function\s+%s\s*\(\)\s*)\s*(?::\s*\??\s*\\\\?[a-z_\x7f-\xff][\w\x7f-\xff]*(?:\\\\[a-z_\x7f-\xff][\w\x7f-\xff]*)*\s*)?{\s*return\s*\$this->%s;\s*})i';

    /**
     * The namespace that contains all proxy classes.
     *
     * @var string
     */
    private $proxyNamespace;

    /**
     * The directory that contains all proxy classes.
     *
     * @var string
     */
    private $proxyDirectory;

    /**
     * Map of callables used to fill in placeholders set in the template.
     *
     * @var string[]|callable[]
     */
    protected $placeholders = [
        'baseProxyInterface'   => Proxy::class,
        'additionalProperties' => '',
    ];

    /**
     * Template used as a blueprint to generate proxies.
     *
     * @var string
     */
    protected $proxyClassTemplate = '<?php

namespace <namespace>;

/**
 * DO NOT EDIT THIS FILE - IT WAS CREATED BY DOCTRINE\'S PROXY GENERATOR
 */
class <proxyShortClassName> extends \<className> implements \<baseProxyInterface>
{
    /**
     * @var \Closure the callback responsible for loading properties in the proxy object. This callback is called with
     *      three parameters, being respectively the proxy object to be initialized, the method that triggered the
     *      initialization process and an array of ordered parameters that were passed to that method.
     *
     * @see \Doctrine\Common\Proxy\Proxy::__setInitializer
     */
    public $__initializer__;

    /**
     * @var \Closure the callback responsible of loading properties that need to be copied in the cloned object
     *
     * @see \Doctrine\Common\Proxy\Proxy::__setCloner
     */
    public $__cloner__;

    /**
     * @var boolean flag indicating if this object was already initialized
     *
     * @see \Doctrine\Persistence\Proxy::__isInitialized
     */
    public $__isInitialized__ = false;

    /**
     * @var array<string, null> properties to be lazy loaded, indexed by property name
     */
    public static $lazyPropertiesNames = <lazyPropertiesNames>;

    /**
     * @var array<string, mixed> default values of properties to be lazy loaded, with keys being the property names
     *
     * @see \Doctrine\Common\Proxy\Proxy::__getLazyProperties
     */
    public static $lazyPropertiesDefaults = <lazyPropertiesDefaults>;

<additionalProperties>

<constructorImpl>

<magicGet>

<magicSet>

<magicIsset>

<sleepImpl>

<wakeupImpl>

<cloneImpl>

    /**
     * Forces initialization of the proxy
     */
    public function __load()
    {
        $this->__initializer__ && $this->__initializer__->__invoke($this, \'__load\', []);
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     */
    public function __isInitialized()
    {
        return $this->__isInitialized__;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     */
    public function __setInitialized($initialized)
    {
        $this->__isInitialized__ = $initialized;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     */
    public function __setInitializer(\Closure $initializer = null)
    {
        $this->__initializer__ = $initializer;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     */
    public function __getInitializer()
    {
        return $this->__initializer__;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     */
    public function __setCloner(\Closure $cloner = null)
    {
        $this->__cloner__ = $cloner;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific cloning logic
     */
    public function __getCloner()
    {
        return $this->__cloner__;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     * @deprecated no longer in use - generated code now relies on internal components rather than generated public API
     * @static
     */
    public function __getLazyProperties()
    {
        return self::$lazyPropertiesDefaults;
    }

    <methods>
}
';

    /**
     * Initializes a new instance of the <tt>ProxyFactory</tt> class that is
     * connected to the given <tt>EntityManager</tt>.
     *
     * @param string $proxyDirectory The directory to use for the proxy classes. It must exist.
     * @param string $proxyNamespace The namespace to use for the proxy classes.
     *
     * @throws InvalidArgumentException
     */
    public function __construct($proxyDirectory, $proxyNamespace)
    {
        if (! $proxyDirectory) {
            throw InvalidArgumentException::proxyDirectoryRequired();
        }

        if (! $proxyNamespace) {
            throw InvalidArgumentException::proxyNamespaceRequired();
        }

        $this->proxyDirectory = $proxyDirectory;
        $this->proxyNamespace = $proxyNamespace;
    }

    /**
     * Sets a placeholder to be replaced in the template.
     *
     * @param string          $name
     * @param string|callable $placeholder
     *
     * @throws InvalidArgumentException
     */
    public function setPlaceholder($name, $placeholder)
    {
        if (! is_string($placeholder) && ! is_callable($placeholder)) {
            throw InvalidArgumentException::invalidPlaceholder($name);
        }

        $this->placeholders[$name] = $placeholder;
    }

    /**
     * Sets the base template used to create proxy classes.
     *
     * @param string $proxyClassTemplate
     */
    public function setProxyClassTemplate($proxyClassTemplate)
    {
        $this->proxyClassTemplate = (string) $proxyClassTemplate;
    }

    /**
     * Generates a proxy class file.
     *
     * @param ClassMetadata $class    Metadata for the original class.
     * @param string|bool   $fileName Filename (full path) for the generated class. If none is given, eval() is used.
     *
     * @throws InvalidArgumentException
     * @throws UnexpectedValueException
     */
    public function generateProxyClass(ClassMetadata $class, $fileName = false)
    {
        $this->verifyClassCanBeProxied($class);

        preg_match_all('(<([a-zA-Z]+)>)', $this->proxyClassTemplate, $placeholderMatches);

        $placeholderMatches = array_combine($placeholderMatches[0], $placeholderMatches[1]);
        $placeholders       = [];

        foreach ($placeholderMatches as $placeholder => $name) {
            $placeholders[$placeholder] = $this->placeholders[$name] ?? [$this, 'generate' . $name];
        }

        foreach ($placeholders as & $placeholder) {
            if (! is_callable($placeholder)) {
                continue;
            }

            $placeholder = call_user_func($placeholder, $class);
        }

        $proxyCode = strtr($this->proxyClassTemplate, $placeholders);

        if (! $fileName) {
            $proxyClassName = $this->generateNamespace($class) . '\\' . $this->generateProxyShortClassName($class);

            if (! class_exists($proxyClassName)) {
                eval(substr($proxyCode, 5));
            }

            return;
        }

        $parentDirectory = dirname($fileName);

        if (! is_dir($parentDirectory) && (@mkdir($parentDirectory, 0775, true) === false)) {
            throw UnexpectedValueException::proxyDirectoryNotWritable($this->proxyDirectory);
        }

        if (! is_writable($parentDirectory)) {
            throw UnexpectedValueException::proxyDirectoryNotWritable($this->proxyDirectory);
        }

        $tmpFileName = $fileName . '.' . uniqid('', true);

        file_put_contents($tmpFileName, $proxyCode);
        @chmod($tmpFileName, 0664);
        rename($tmpFileName, $fileName);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function verifyClassCanBeProxied(ClassMetadata $class)
    {
        if ($class->getReflectionClass()->isFinal()) {
            throw InvalidArgumentException::classMustNotBeFinal($class->getName());
        }

        if ($class->getReflectionClass()->isAbstract()) {
            throw InvalidArgumentException::classMustNotBeAbstract($class->getName());
        }
    }

    /**
     * Generates the proxy short class name to be used in the template.
     *
     * @return string
     */
    private function generateProxyShortClassName(ClassMetadata $class)
    {
        $proxyClassName = ClassUtils::generateProxyClassName($class->getName(), $this->proxyNamespace);
        $parts          = explode('\\', strrev($proxyClassName), 2);

        return strrev($parts[0]);
    }

    /**
     * Generates the proxy namespace.
     *
     * @return string
     */
    private function generateNamespace(ClassMetadata $class)
    {
        $proxyClassName = ClassUtils::generateProxyClassName($class->getName(), $this->proxyNamespace);
        $parts          = explode('\\', strrev($proxyClassName), 2);

        return strrev($parts[1]);
    }

    /**
     * Generates the original class name.
     *
     * @return string
     */
    private function generateClassName(ClassMetadata $class)
    {
        return ltrim($class->getName(), '\\');
    }

    /**
     * Generates the array representation of lazy loaded public properties and their default values.
     *
     * @return string
     */
    private function generateLazyPropertiesNames(ClassMetadata $class)
    {
        $lazyPublicProperties = $this->getLazyLoadedPublicPropertiesNames($class);
        $values               = [];

        foreach ($lazyPublicProperties as $name) {
            $values[$name] = null;
        }

        return var_export($values, true);
    }

    /**
     * Generates the array representation of lazy loaded public properties names.
     *
     * @return string
     */
    private function generateLazyPropertiesDefaults(ClassMetadata $class)
    {
        return var_export($this->getLazyLoadedPublicProperties($class), true);
    }

    /**
     * Generates the constructor code (un-setting public lazy loaded properties, setting identifier field values).
     *
     * @return string
     */
    private function generateConstructorImpl(ClassMetadata $class)
    {
        $constructorImpl = <<<'EOT'
    public function __construct(?\Closure $initializer = null, ?\Closure $cloner = null)
    {

EOT;

        $toUnset = array_map(static function (string $name): string {
            return '$this->' . $name;
        }, $this->getLazyLoadedPublicPropertiesNames($class));

        return $constructorImpl . ($toUnset === [] ? '' : '        unset(' . implode(', ', $toUnset) . ");\n")
            . <<<'EOT'

        $this->__initializer__ = $initializer;
        $this->__cloner__      = $cloner;
    }
EOT;
    }

    /**
     * Generates the magic getter invoked when lazy loaded public properties are requested.
     *
     * @return string
     */
    private function generateMagicGet(ClassMetadata $class)
    {
        $lazyPublicProperties = $this->getLazyLoadedPublicPropertiesNames($class);
        $reflectionClass      = $class->getReflectionClass();
        $hasParentGet         = false;
        $returnReference      = '';
        $inheritDoc           = '';
        $name                 = '$name';
        $parametersString     = '$name';
        $returnTypeHint       = null;

        if ($reflectionClass->hasMethod('__get')) {
            $hasParentGet     = true;
            $inheritDoc       = '{@inheritDoc}';
            $methodReflection = $reflectionClass->getMethod('__get');

            if ($methodReflection->returnsReference()) {
                $returnReference = '& ';
            }

            $methodParameters = $methodReflection->getParameters();
            $name             = '$' . $methodParameters[0]->getName();

            $parametersString = $this->buildParametersString($methodReflection->getParameters(), ['name']);
            $returnTypeHint   = $this->getMethodReturnType($methodReflection);
        }

        if (empty($lazyPublicProperties) && ! $hasParentGet) {
            return '';
        }

        $magicGet = <<<EOT
    /**
     * $inheritDoc
     * @param string \$name
     */
    public function {$returnReference}__get($parametersString)$returnTypeHint
    {

EOT;

        if (! empty($lazyPublicProperties)) {
            $magicGet .= <<<'EOT'
        if (\array_key_exists($name, self::$lazyPropertiesNames)) {
            $this->__initializer__ && $this->__initializer__->__invoke($this, '__get', [$name]);
EOT;

            if ($returnTypeHint === ': void') {
                $magicGet .= "\n            return;";
            } else {
                $magicGet .= "\n            return \$this->\$name;";
            }

            $magicGet .= <<<'EOT'

        }


EOT;
        }

        if ($hasParentGet) {
            $magicGet .= <<<'EOT'
        $this->__initializer__ && $this->__initializer__->__invoke($this, '__get', [$name]);
EOT;

            if ($returnTypeHint === ': void') {
                $magicGet .= <<<'EOT'

        parent::__get($name);
        return;
EOT;
            } else {
                $magicGet .= <<<'EOT'

        return parent::__get($name);
EOT;
            }
        } else {
            $magicGet .= sprintf(<<<EOT
        trigger_error(sprintf('Undefined property: %%s::$%%s', __CLASS__, %s), E_USER_NOTICE);

EOT
                , $name);
        }

        return $magicGet . "\n    }";
    }

    /**
     * Generates the magic setter (currently unused).
     *
     * @return string
     */
    private function generateMagicSet(ClassMetadata $class)
    {
        $lazyPublicProperties = $this->getLazyLoadedPublicPropertiesNames($class);
        $hasParentSet         = $class->getReflectionClass()->hasMethod('__set');
        $parametersString     = '$name, $value';
        $returnTypeHint       = null;

        if ($hasParentSet) {
            $methodReflection = $class->getReflectionClass()->getMethod('__set');
            $parametersString = $this->buildParametersString($methodReflection->getParameters(), ['name', 'value']);
            $returnTypeHint   = $this->getMethodReturnType($methodReflection);
        }

        if (empty($lazyPublicProperties) && ! $hasParentSet) {
            return '';
        }

        $inheritDoc = $hasParentSet ? '{@inheritDoc}' : '';
        $magicSet   = sprintf(<<<'EOT'
    /**
     * %s
     * @param string $name
     * @param mixed  $value
     */
    public function __set(%s)%s
    {

EOT
            , $inheritDoc, $parametersString, $returnTypeHint);

        if (! empty($lazyPublicProperties)) {
            $magicSet .= <<<'EOT'
        if (\array_key_exists($name, self::$lazyPropertiesNames)) {
            $this->__initializer__ && $this->__initializer__->__invoke($this, '__set', [$name, $value]);

            $this->$name = $value;

            return;
        }


EOT;
        }

        if ($hasParentSet) {
            $magicSet .= <<<'EOT'
        $this->__initializer__ && $this->__initializer__->__invoke($this, '__set', [$name, $value]);

        return parent::__set($name, $value);
EOT;
        } else {
            $magicSet .= '        $this->$name = $value;';
        }

        return $magicSet . "\n    }";
    }

    /**
     * Generates the magic issetter invoked when lazy loaded public properties are checked against isset().
     *
     * @return string
     */
    private function generateMagicIsset(ClassMetadata $class)
    {
        $lazyPublicProperties = $this->getLazyLoadedPublicPropertiesNames($class);
        $hasParentIsset       = $class->getReflectionClass()->hasMethod('__isset');
        $parametersString     = '$name';
        $returnTypeHint       = null;

        if ($hasParentIsset) {
            $methodReflection = $class->getReflectionClass()->getMethod('__isset');
            $parametersString = $this->buildParametersString($methodReflection->getParameters(), ['name']);
            $returnTypeHint   = $this->getMethodReturnType($methodReflection);
        }

        if (empty($lazyPublicProperties) && ! $hasParentIsset) {
            return '';
        }

        $inheritDoc = $hasParentIsset ? '{@inheritDoc}' : '';
        $magicIsset = <<<EOT
    /**
     * $inheritDoc
     * @param  string \$name
     * @return boolean
     */
    public function __isset($parametersString)$returnTypeHint
    {

EOT;

        if (! empty($lazyPublicProperties)) {
            $magicIsset .= <<<'EOT'
        if (\array_key_exists($name, self::$lazyPropertiesNames)) {
            $this->__initializer__ && $this->__initializer__->__invoke($this, '__isset', [$name]);

            return isset($this->$name);
        }


EOT;
        }

        if ($hasParentIsset) {
            $magicIsset .= <<<'EOT'
        $this->__initializer__ && $this->__initializer__->__invoke($this, '__isset', [$name]);

        return parent::__isset($name);
EOT;
        } else {
            $magicIsset .= '        return false;';
        }

        return $magicIsset . "\n    }";
    }

    /**
     * Generates implementation for the `__sleep` method of proxies.
     *
     * @return string
     */
    private function generateSleepImpl(ClassMetadata $class)
    {
        $reflectionClass = $class->getReflectionClass();

        $hasParentSleep = $reflectionClass->hasMethod('__sleep');
        $inheritDoc     = $hasParentSleep ? '{@inheritDoc}' : '';
        $returnTypeHint = $hasParentSleep ? $this->getMethodReturnType($reflectionClass->getMethod('__sleep')) : '';
        $sleepImpl      = <<<EOT
    /**
     * $inheritDoc
     * @return array
     */
    public function __sleep()$returnTypeHint
    {

EOT;

        if ($hasParentSleep) {
            return $sleepImpl . <<<'EOT'
        $properties = array_merge(['__isInitialized__'], parent::__sleep());

        if ($this->__isInitialized__) {
            $properties = array_diff($properties, array_keys(self::$lazyPropertiesNames));
        }

        return $properties;
    }
EOT;
        }

        $allProperties = ['__isInitialized__'];

        foreach ($class->getReflectionClass()->getProperties() as $prop) {
            assert($prop instanceof ReflectionProperty);
            if ($prop->isStatic()) {
                continue;
            }

            $allProperties[] = $prop->isPrivate()
                ? "\0" . $prop->getDeclaringClass()->getName() . "\0" . $prop->getName()
                : $prop->getName();
        }

        $lazyPublicProperties = $this->getLazyLoadedPublicPropertiesNames($class);
        $protectedProperties  = array_diff($allProperties, $lazyPublicProperties);

        foreach ($allProperties as &$property) {
            $property = var_export($property, true);
        }

        foreach ($protectedProperties as &$property) {
            $property = var_export($property, true);
        }

        $allProperties       = implode(', ', $allProperties);
        $protectedProperties = implode(', ', $protectedProperties);

        return $sleepImpl . <<<EOT
        if (\$this->__isInitialized__) {
            return [$allProperties];
        }

        return [$protectedProperties];
    }
EOT;
    }

    /**
     * Generates implementation for the `__wakeup` method of proxies.
     *
     * @return string
     */
    private function generateWakeupImpl(ClassMetadata $class)
    {
        $reflectionClass = $class->getReflectionClass();

        $hasParentWakeup = $reflectionClass->hasMethod('__wakeup');

        $unsetPublicProperties = [];
        foreach ($this->getLazyLoadedPublicPropertiesNames($class) as $lazyPublicProperty) {
            $unsetPublicProperties[] = '$this->' . $lazyPublicProperty;
        }

        $shortName      = $this->generateProxyShortClassName($class);
        $inheritDoc     = $hasParentWakeup ? '{@inheritDoc}' : '';
        $returnTypeHint = $hasParentWakeup ? $this->getMethodReturnType($reflectionClass->getMethod('__wakeup')) : '';
        $wakeupImpl     = <<<EOT
    /**
     * $inheritDoc
     */
    public function __wakeup()$returnTypeHint
    {
        if ( ! \$this->__isInitialized__) {
            \$this->__initializer__ = function ($shortName \$proxy) {
                \$proxy->__setInitializer(null);
                \$proxy->__setCloner(null);

                \$existingProperties = get_object_vars(\$proxy);

                foreach (\$proxy::\$lazyPropertiesDefaults as \$property => \$defaultValue) {
                    if ( ! array_key_exists(\$property, \$existingProperties)) {
                        \$proxy->\$property = \$defaultValue;
                    }
                }
            };

EOT;

        if (! empty($unsetPublicProperties)) {
            $wakeupImpl .= "\n            unset(" . implode(', ', $unsetPublicProperties) . ');';
        }

        $wakeupImpl .= "\n        }";

        if ($hasParentWakeup) {
            $wakeupImpl .= "\n        parent::__wakeup();";
        }

        $wakeupImpl .= "\n    }";

        return $wakeupImpl;
    }

    /**
     * Generates implementation for the `__clone` method of proxies.
     *
     * @return string
     */
    private function generateCloneImpl(ClassMetadata $class)
    {
        $hasParentClone  = $class->getReflectionClass()->hasMethod('__clone');
        $inheritDoc      = $hasParentClone ? '{@inheritDoc}' : '';
        $callParentClone = $hasParentClone ? "\n        parent::__clone();\n" : '';

        return <<<EOT
    /**
     * $inheritDoc
     */
    public function __clone()
    {
        \$this->__cloner__ && \$this->__cloner__->__invoke(\$this, '__clone', []);
$callParentClone    }
EOT;
    }

    /**
     * Generates decorated methods by picking those available in the parent class.
     *
     * @return string
     */
    private function generateMethods(ClassMetadata $class)
    {
        $methods           = '';
        $methodNames       = [];
        $reflectionMethods = $class->getReflectionClass()->getMethods(ReflectionMethod::IS_PUBLIC);
        $skippedMethods    = [
            '__sleep'   => true,
            '__clone'   => true,
            '__wakeup'  => true,
            '__get'     => true,
            '__set'     => true,
            '__isset'   => true,
        ];

        foreach ($reflectionMethods as $method) {
            $name = $method->getName();

            if (
                $method->isConstructor() ||
                isset($skippedMethods[strtolower($name)]) ||
                isset($methodNames[$name]) ||
                $method->isFinal() ||
                $method->isStatic() ||
                ( ! $method->isPublic())
            ) {
                continue;
            }

            $methodNames[$name] = true;
            $methods           .= "\n    /**\n"
                . "     * {@inheritDoc}\n"
                . "     */\n"
                . '    public function ';

            if ($method->returnsReference()) {
                $methods .= '&';
            }

            $methods .= $name . '(' . $this->buildParametersString($method->getParameters()) . ')';
            $methods .= $this->getMethodReturnType($method);
            $methods .= "\n" . '    {' . "\n";

            if ($this->isShortIdentifierGetter($method, $class)) {
                $identifier = lcfirst(substr($name, 3));
                $fieldType  = $class->getTypeOfField($identifier);
                $cast       = in_array($fieldType, ['integer', 'smallint']) ? '(int) ' : '';

                $methods .= '        if ($this->__isInitialized__ === false) {' . "\n";
                $methods .= '            ';
                $methods .= $this->shouldProxiedMethodReturn($method) ? 'return ' : '';
                $methods .= $cast . ' parent::' . $method->getName() . "();\n";
                $methods .= '        }' . "\n\n";
            }

            $invokeParamsString = implode(', ', $this->getParameterNamesForInvoke($method->getParameters()));
            $callParamsString   = implode(', ', $this->getParameterNamesForParentCall($method->getParameters()));

            $methods .= "\n        \$this->__initializer__ "
                . '&& $this->__initializer__->__invoke($this, ' . var_export($name, true)
                . ', [' . $invokeParamsString . ']);'
                . "\n\n        "
                . ($this->shouldProxiedMethodReturn($method) ? 'return ' : '')
                . 'parent::' . $name . '(' . $callParamsString . ');'
                . "\n" . '    }' . "\n";
        }

        return $methods;
    }

    /**
     * Generates the Proxy file name.
     *
     * @param string $className
     * @param string $baseDirectory Optional base directory for proxy file name generation.
     *                              If not specified, the directory configured on the Configuration of the
     *                              EntityManager will be used by this factory.
     *
     * @return string
     */
    public function getProxyFileName($className, $baseDirectory = null)
    {
        $baseDirectory = $baseDirectory ?: $this->proxyDirectory;

        return rtrim($baseDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . Proxy::MARKER
            . str_replace('\\', '', $className) . '.php';
    }

    /**
     * Checks if the method is a short identifier getter.
     *
     * What does this mean? For proxy objects the identifier is already known,
     * however accessing the getter for this identifier usually triggers the
     * lazy loading, leading to a query that may not be necessary if only the
     * ID is interesting for the userland code (for example in views that
     * generate links to the entity, but do not display anything else).
     *
     * @param ReflectionMethod $method
     *
     * @return bool
     */
    private function isShortIdentifierGetter($method, ClassMetadata $class)
    {
        $identifier = lcfirst(substr($method->getName(), 3));
        $startLine  = $method->getStartLine();
        $endLine    = $method->getEndLine();
        $cheapCheck = $method->getNumberOfParameters() === 0
            && substr($method->getName(), 0, 3) === 'get'
            && in_array($identifier, $class->getIdentifier(), true)
            && $class->hasField($identifier)
            && ($endLine - $startLine <= 4);

        if ($cheapCheck) {
            $code = file($method->getFileName());
            $code = trim(implode(' ', array_slice($code, $startLine - 1, $endLine - $startLine + 1)));

            $pattern = sprintf(self::PATTERN_MATCH_ID_METHOD, $method->getName(), $identifier);

            if (preg_match($pattern, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generates the list of public properties to be lazy loaded.
     *
     * @return array<int, string>
     */
    private function getLazyLoadedPublicPropertiesNames(ClassMetadata $class): array
    {
        $properties = [];

        foreach ($class->getReflectionClass()->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            if ((! $class->hasField($name) && ! $class->hasAssociation($name)) || $class->isIdentifier($name)) {
                continue;
            }

            $properties[] = $name;
        }

        return $properties;
    }

    /**
     * Generates the list of default values of public properties.
     *
     * @return mixed[]
     */
    private function getLazyLoadedPublicProperties(ClassMetadata $class)
    {
        $defaultProperties          = $class->getReflectionClass()->getDefaultProperties();
        $lazyLoadedPublicProperties = $this->getLazyLoadedPublicPropertiesNames($class);
        $defaultValues              = [];

        foreach ($class->getReflectionClass()->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            if (! in_array($name, $lazyLoadedPublicProperties, true)) {
                continue;
            }

            if (array_key_exists($name, $defaultProperties)) {
                $defaultValues[$name] = $defaultProperties[$name];
            } elseif (method_exists($property, 'getType')) {
                $propertyType = $property->getType();
                if ($propertyType !== null && $propertyType->allowsNull()) {
                    $defaultValues[$name] = null;
                }
            }
        }

        return $defaultValues;
    }

    /**
     * @param ReflectionParameter[] $parameters
     * @param string[]              $renameParameters
     *
     * @return string
     */
    private function buildParametersString(array $parameters, array $renameParameters = [])
    {
        $parameterDefinitions = [];

        /** @var ReflectionParameter $param */
        $i = -1;
        foreach ($parameters as $param) {
            $i++;
            $parameterDefinition = '';
            $parameterType       = $this->getParameterType($param);

            if ($parameterType !== null) {
                $parameterDefinition .= $parameterType . ' ';
            }

            if ($param->isPassedByReference()) {
                $parameterDefinition .= '&';
            }

            if ($param->isVariadic()) {
                $parameterDefinition .= '...';
            }

            $parameterDefinition .= '$' . ($renameParameters ? $renameParameters[$i] : $param->getName());

            if ($param->isDefaultValueAvailable()) {
                $parameterDefinition .= ' = ' . var_export($param->getDefaultValue(), true);
            }

            $parameterDefinitions[] = $parameterDefinition;
        }

        return implode(', ', $parameterDefinitions);
    }

    /**
     * @return string|null
     */
    private function getParameterType(ReflectionParameter $parameter)
    {
        if (! $parameter->hasType()) {
            return null;
        }

        return $this->formatType($parameter->getType(), $parameter->getDeclaringFunction(), $parameter);
    }

    /**
     * @param ReflectionParameter[] $parameters
     *
     * @return string[]
     */
    private function getParameterNamesForInvoke(array $parameters)
    {
        return array_map(
            static function (ReflectionParameter $parameter) {
                return '$' . $parameter->getName();
            },
            $parameters
        );
    }

    /**
     * @param ReflectionParameter[] $parameters
     *
     * @return string[]
     */
    private function getParameterNamesForParentCall(array $parameters)
    {
        return array_map(
            static function (ReflectionParameter $parameter) {
                $name = '';

                if ($parameter->isVariadic()) {
                    $name .= '...';
                }

                $name .= '$' . $parameter->getName();

                return $name;
            },
            $parameters
        );
    }

    /**
     * @return string
     */
    private function getMethodReturnType(ReflectionMethod $method)
    {
        if (! $method->hasReturnType()) {
            return '';
        }

        return ': ' . $this->formatType($method->getReturnType(), $method);
    }

    /**
     * @return bool
     */
    private function shouldProxiedMethodReturn(ReflectionMethod $method)
    {
        if (! $method->hasReturnType()) {
            return true;
        }

        return strtolower($this->formatType($method->getReturnType(), $method)) !== 'void';
    }

    /**
     * @return string
     */
    private function formatType(
        ReflectionType $type,
        ReflectionMethod $method,
        ?ReflectionParameter $parameter = null
    ) {
        $name      = $type->getName();
        $nameLower = strtolower($name);

        if ($nameLower === 'self') {
            $name = $method->getDeclaringClass()->getName();
        }

        if ($nameLower === 'parent') {
            $name = $method->getDeclaringClass()->getParentClass()->getName();
        }

        if (! $type->isBuiltin() && ! class_exists($name) && ! interface_exists($name)) {
            if ($parameter !== null) {
                throw UnexpectedValueException::invalidParameterTypeHint(
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                    $parameter->getName()
                );
            }

            throw UnexpectedValueException::invalidReturnTypeHint(
                $method->getDeclaringClass()->getName(),
                $method->getName()
            );
        }

        if (! $type->isBuiltin()) {
            $name = '\\' . $name;
        }

        if (
            $type->allowsNull()
            && ($parameter === null || ! $parameter->isDefaultValueAvailable() || $parameter->getDefaultValue() !== null)
        ) {
            $name = '?' . $name;
        }

        return $name;
    }
}

interface_exists(ClassMetadata::class);
