<?php

namespace Oro\Bundle\EntityBundle\Provider;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\Common\Util\Inflector;

class EntityNameProvider implements EntityNameProviderInterface
{
    /** @var string[] */
    protected $fieldGuesses = ['firstName', 'name', 'title', 'subject'];

    /** @var ManagerRegistry */
    protected $doctrine;

    /**
     * @param ManagerRegistry $doctrine
     */
    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    /**
     * {@inheritdoc}
     */
    public function getName($format, $locale, $entity)
    {
        if ($format === self::SHORT) {
            return $this->getConstructedName($entity, [$this->getFieldName(ClassUtils::getClass($entity))]);
        }

        if ($format === self::FULL) {
            return $this->getConstructedName($entity, $this->getFieldNames(ClassUtils::getClass($entity)));
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getNameDQL($format, $locale, $className, $alias)
    {
        if ($format === self::SHORT) {
            $fieldName = $this->getFieldName($className);
            if ($fieldName) {
                return $alias . '.' . $fieldName;
            }
        }

        if ($format === self::FULL) {
            $fieldNames = $this->getFieldNames($className);
            if (0 === count($fieldNames)) {
                return false;
            }

            // prepend table alias
            $fieldNames = array_map(function ($fieldName) use ($alias) {
                return $alias . '.' . $fieldName;
            }, $fieldNames);

            if (1 === count($fieldNames)) {
                return reset($fieldNames);
            }

            // more than one field name
            return sprintf("CONCAT_WS(' ', %s)", implode(', ', $fieldNames));
        }

        return false;
    }

    /**
     * @param string $className
     *
     * @return string|null
     */
    protected function getFieldName($className)
    {
        $manager = $this->doctrine->getManagerForClass($className);
        if (null === $manager) {
            return null;
        }

        $metadata = $manager->getClassMetadata($className);
        foreach ($this->fieldGuesses as $fieldName) {
            if ($metadata->hasField($fieldName) && $metadata->getTypeOfField($fieldName) === 'string') {
                return $fieldName;
            }
        }

        $identifierFieldNames = $metadata->getIdentifierFieldNames();
        if (count($identifierFieldNames) === 1) {
            return reset($identifierFieldNames);
        }

        return null;
    }

    /**
     * @param object $entity
     * @param string $fieldName
     *
     * @return mixed
     */
    protected function getFieldValue($entity, $fieldName)
    {
        $getterName = 'get' . Inflector::classify($fieldName);

        if (method_exists($entity, $getterName)) {
            return $entity->$getterName();
        }

        return null;
    }

    /**
     * Return string field names of className
     * @param  string $className
     * @return array
     */
    protected function getFieldNames($className)
    {
        if (null === $manager = $this->doctrine->getManagerForClass($className)) {
            return [];
        }

        $metadata = $manager->getClassMetadata($className);
        $fieldNames = (array)$metadata->getFieldNames();

        return array_filter($fieldNames, function ($fieldName) use ($metadata) {
            return 'string' === $metadata->getTypeOfField($fieldName);
        });
    }

    /**
     * Constructs and returns a name from the values of the fieldNames
     *
     * @param $entity
     * @param $fieldNames
     *
     * @return string|bool Constructed Name or FALSE if fails
     */
    protected function getConstructedName($entity, $fieldNames)
    {
        $fieldValues = [];
        foreach ($fieldNames as $field) {
            $fieldValues[] = $this->getFieldValue($entity, $field);
        }

        $fieldValues = array_filter($fieldValues);

        if (0 === count($fieldValues)) {
            return false;
        }

        return implode(' ', $fieldValues);
    }
}
