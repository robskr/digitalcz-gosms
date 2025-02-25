<?php

declare(strict_types=1);

namespace DigitalCz\GoSms\Resource;

use DateTimeImmutable;
use DateTimeInterface;
use DigitalCz\GoSms\Exception\RuntimeException;
use JsonSerializable;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Represents an API resource
 */
class BaseResource implements ResourceInterface
{
    /** @var array<string, array<string, string>> Cache of resolved mapping types */
    protected static array $_mapping = []; // phpcs:ignore

    /** Original API response */
    protected ?ResponseInterface $_response = null; // phpcs:ignore

    /** @var mixed[] Original values from API response */
    protected array $_result; // phpcs:ignore

    /** @var mixed[] Dynamic properties */
    protected array $_data = []; // phpcs:ignore

    /**
     * @param mixed[] $result
     */
    public function __construct(array $result)
    {
        $this->_result = $result;
        $this->hydrate($result);
    }

    /** @inheritDoc */
    public function getResult(): array
    {
        return $this->_result;
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        $values = get_object_vars($this) + $this->_data;
        $result = [];
        foreach ($values as $property => $value) {
            // skip internal properties
            if (in_array($property, ['_mapping', '_response', '_result', '_data'], true)) {
                continue;
            }

            if ($value instanceof DateTimeInterface) {
                $value = $value->format(DateTimeInterface::ATOM);
            }

            if ($value instanceof Collection) {
                $value = $value->toArray();
            }

            if ($value instanceof JsonSerializable) {
                $value = $value->jsonSerialize();
            }

            $result[$property] = $value;
        }

        return $result;
    }

    public function link(): string
    {
        if (!isset($this->_result['link'])) {
            throw new RuntimeException('Resource has no link');
        }

        if (!is_string($this->_result['link'])) {
            throw new RuntimeException('Invalid link');
        }

        return $this->_result['link'];
    }

    public function id(): string
    {
        if (isset($this->_result['id']) && is_string($this->_result['id'])) {
            return $this->_result['id'];
        }

        $link = $this->_result['link'] ?? null;

        if ($link === null && is_array($this->_result['links'])) {
            $link = $this->_result['links']['self'] ?? null;
        }

        if (!is_string($link)) {
            throw new RuntimeException('Cannot extract ID from link');
        }

        return $this->getIdFromString($link);
    }

    /**
     * @return array<string, string>
     */
    public function links(): array
    {
        if (!isset($this->_result['links'])) {
            throw new RuntimeException('Resource has no links');
        }

        if (!is_array($this->_result['links'])) {
            throw new RuntimeException('Invalid links');
        }

        return $this->_result['links'];
    }

    /**
     * @return mixed[]
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function getResponse(): ResponseInterface
    {
        if (!isset($this->_response)) {
            throw new RuntimeException('Only resource returned from client has API response set');
        }

        return $this->_response;
    }

    public function setResponse(ResponseInterface $response): void
    {
        $this->_response = $response;
    }

    /**
     * @param mixed[] $values
     */
    protected function hydrate(array $values): void
    {
        foreach ($values as $property => $value) {
            $this->setProperty($property, $value);
        }
    }

    protected function setProperty(string $property, mixed $value): void
    {
        $property = lcfirst(str_replace('_', '', ucwords($property, '_')));

        if ($value !== null) {
            $type = $this->getMappingType($property);

            // is Resource class
            if (is_a($type, self::class, true)) {
                $value = new $type($value);
            }

            // is Collection<Resource>
            if (is_array($value) && strpos($type, 'Collection') === 0) {
                // parse Resource class from type
                preg_match('/Collection<(.+)>/', $type, $matches);
                /** @var class-string<ResourceInterface> $resourceClass */
                $resourceClass = $matches[1] ?? null;

                if (!class_exists($resourceClass)) {
                    $reflection = new ReflectionClass($this::class);
                    $namespace = $reflection->getNamespaceName();
                    /** @var class-string<ResourceInterface> $resourceClass */
                    $resourceClass = "$namespace\\$resourceClass";
                }

                $value = new Collection($value, $resourceClass);
            }

            if ($type === DateTimeImmutable::class) {
                if (!is_string($value)) {
                    throw new RuntimeException('Unexpected value for DateTime field');
                }

                $value = new DateTimeImmutable($value);
            }
        }

        $this->$property = $value; // @phpstan-ignore-line
    }

    protected function getMappingType(string $property): string
    {
        // cache resolved mapping types
        if (!isset(static::$_mapping[static::class][$property])) {
            static::$_mapping[static::class] ??= [];
            static::$_mapping[static::class][$property] = $this->resolveMappingType($property);
        }

        return static::$_mapping[static::class][$property];
    }

    protected function resolveMappingType(string $property): string
    {
        try {
            $reflection = new ReflectionProperty($this, $property);

            $nativeType = $reflection->getType();

            if ($nativeType instanceof ReflectionNamedType && !is_a($nativeType->getName(), Collection::class, true)) {
                return $nativeType->getName();
            }

            $phpDoc = $reflection->getDocComment();
        } catch (ReflectionException) {
            return 'mixed'; // property may not exist
        }

        if ($phpDoc === false) {
            return 'mixed'; // no doc comment
        }

        if (preg_match('/@var\s+(?<type>[^\s]+)/', $phpDoc, $matches) !== 1) {
            return 'mixed'; // doc comment without @var type
        }

        $type = $matches['type'];

        // remove |null suffix
        $type = str_replace('|null', '', $type);

        if (class_exists($type)) {
            return $type; // type is FQCN
        }

        if (class_exists(__NAMESPACE__ . '\\' . $type)) {
            return __NAMESPACE__ . '\\' . $type; // type is class in same namespace
        }

        if (strpos($type, 'Collection') === 0) {
            $collectionType = $this->resolveCollectionMappingType($phpDoc);

            return "Collection<$collectionType>"; // type is collection
        }

        return $type;
    }

    protected function getIdFromString(string $string): string
    {
        if (preg_match('/\/(\d+)/', $string, $matches) !== 1) {
            throw new LogicException('Cannot id ' . static::class . ' from ' . $string);
        }

        return $matches[1];
    }

    protected function resolveCollectionMappingType(string $phpDoc): string
    {
        if (preg_match('/@var\s+Collection<(?<type>[^\s]+)>/', $phpDoc, $matches) !== 1) {
            throw new LogicException('Cannot resolve Collection type on ' . static::class . ' from ' . $phpDoc);
        }

        $type = $matches['type'];

        if (class_exists(__NAMESPACE__ . '\\' . $type)) {
            return __NAMESPACE__ . '\\' . $type; // type is class in same namespace
        }

        return $type;
    }

    public function __get(string $name): mixed
    {
        return $this->_data[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->_data[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->_data[$name]);
    }
}
