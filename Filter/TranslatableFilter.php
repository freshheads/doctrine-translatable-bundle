<?php

/*
 * (c) Prezent Internet B.V. <info@prezent.nl>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Prezent\Doctrine\TranslatableBundle\Filter;

use Doctrine\ORM\Query\Expr;
use Prezent\Doctrine\Translatable\EventListener\TranslatableListener;
use Sonata\AdminBundle\Filter\Model\FilterData;
use Sonata\AdminBundle\Form\Type\Operator\StringOperatorType;
use Sonata\DoctrineORMAdminBundle\Datagrid\ProxyQueryInterface;
use Sonata\DoctrineORMAdminBundle\Filter\StringFilter;

/**
 * TranslatableFilter
 *
 * @see StringFilter
 */
class TranslatableFilter extends StringFilter
{
    /**
     * @var TranslatableListener
     */
    private $listener;

    /**
     * Constructor
     *
     * @param TranslatableListener $listener
     */
    public function __construct(TranslatableListener $listener)
    {
        parent::__construct();
        $this->listener = $listener;
    }

    /**
     * {@inheritdoc}
     */
    public function filter(ProxyQueryInterface $query, string $alias, string $field, FilterData $data): void
    {
        if (!$data->hasValue()) {
            return;
        }

        $value = trim((string) ($data->getValue() ?? ''));

        if (strlen($value) == 0) {
            return;
        }

        $type = $data->getType() ?? StringOperatorType::TYPE_CONTAINS;
        $operator = $this->getOperator((int) $type);

        if (!$operator) {
            $operator = 'LIKE';
        }

        $queryBuilder = $query->getQueryBuilder();
        $entities = $queryBuilder->getRootEntities();
        $classMetadata = $this->listener->getTranslatableMetadata(current($entities));
        $transMetadata = $this->listener->getTranslatableMetadata($classMetadata->targetEntity);

        // Add inner join
        if (!$this->hasJoin($query, $alias)) {
            $parameterName = $this->getNewParameterName($query);

            $queryBuilder->innerJoin(
                sprintf('%s.%s', $alias, $classMetadata->translations->name),
                'trans',
                Expr\Join::WITH,
                sprintf('trans.%s = :%s', $transMetadata->locale->name, $parameterName)
            );

            $queryBuilder->setParameter($parameterName, $this->listener->getCurrentLocale());
        }

        // c.name > '1' => c.name OPERATOR :FIELDNAME
        $parameterName = $this->getNewParameterName($query);

        $or = $queryBuilder->expr()->orX();

        $or->add(sprintf('%s.%s %s :%s', 'trans', $field, $operator, $parameterName));

        if (StringOperatorType::TYPE_NOT_CONTAINS == $type) {
            $or->add($queryBuilder->expr()->isNull(sprintf('%s.%s', 'trans', $field)));
        }

        $this->applyWhere($query, $or);

        if ($type == StringOperatorType::TYPE_EQUAL) {
            $queryBuilder->setParameter($parameterName, $value);
        } else {
            $format = match ($type) {
                StringOperatorType::TYPE_STARTS_WITH => '%s%%',
                StringOperatorType::TYPE_ENDS_WITH => '%%%s',
                default => '%%%s%%',
            };
            $queryBuilder->setParameter($parameterName, sprintf($format, $value));
        }
    }

    /**
     * @param int $type
     *
     * @return string|false
     */
    private function getOperator(int $type)
    {
        $choices = array(
            StringOperatorType::TYPE_CONTAINS         => 'LIKE',
            StringOperatorType::TYPE_NOT_CONTAINS     => 'NOT LIKE',
            StringOperatorType::TYPE_EQUAL            => '=',
            StringOperatorType::TYPE_STARTS_WITH      => 'LIKE',
            StringOperatorType::TYPE_ENDS_WITH        => 'LIKE',
            StringOperatorType::TYPE_NOT_EQUAL        => '<>',
        );

        return isset($choices[$type]) ? $choices[$type] : false;
    }

    /**
     * Does the query builder have a translation join
     *
     * @param ProxyQueryInterface $query
     * @param string $alias
     * @return bool
     */
    private function hasJoin(ProxyQueryInterface $query, string $alias): bool
    {
        $queryBuilder = $query->getQueryBuilder();
        $joins = $queryBuilder->getDQLPart('join');

        if (!isset($joins[$alias])) {
            return false;
        }

        foreach ($joins[$alias] as $join) {
            if ('trans' === $join->getAlias()) {
                return true;
            }
        }

        return false;
    }
}
