<?php

namespace LCV\DoctrineODMPaginatorBundle\Pagination;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Query\Builder;
use Doctrine\ODM\MongoDB\DocumentManager;
use LCV\ExceptionPackBundle\Exception\ApiException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class Paginator
{
    const NO_SKIP = 0;
    const STARTING_AFTER = 1;
    const ENDING_BEFORE = 2;

    private $dm;

    private $orderConfig;
    private $paginationConfig;
    private $softDeletedKey;

    /** @var Request $request */
    private $request;

    public function __construct(
        DocumentManager $manager,
        RequestStack $requestStack,
        $orderConfig,
        $paginationConfig,
        $softDeletedKey
    )
    {
        $this->dm = $manager;
        $this->request = $requestStack->getCurrentRequest();
        $this->orderConfig = $orderConfig;
        $this->paginationConfig = $paginationConfig;
        $this->softDeletedKey = $softDeletedKey;
    }


    // ['-1', 'desc', 'DESC', 'descendent', 'DESCENDENT']


    public function validateSortField($field, ClassMetadata $metadata, $forbiddenFields)
    {
        if (!$field) {
            return false;
        }

        if (!$metadata->hasField($field)) {
            return false;
        }

        if (in_array($field, $forbiddenFields)) {
            return false;
        }

        return true;
    }

    private function applyFilter(Builder $qb, ClassMetadata $metadata, $field, $value)
    {
        if (!$metadata->hasField($field) && !$metadata->hasAssociation($field)) {
            // TODO
            throw new ApiException(500, 'Invalid filter, field or association not found in entity');
        }

        if ($metadata->hasField($field)) {
            // Field
            $qb->addAnd($qb->expr()->field($field)->equals($value));
        } else {
            // Association
            $qb->addAnd($qb->expr()->references($value));
        }
    }

    private function applyLimit(Builder $qb)
    {
        $limit = $this->request->query->get($this->paginationConfig['limit_key']);

        if ($limit) {
            $qb->limit($limit);
        }
    }

    public function getSkipData(ClassMetadata $metadata)
    {
        $entryDocument = null;

        if (
            $this->request->query->has($this->paginationConfig['starting_after_key']) ||
            $this->request->query->has($this->paginationConfig['ending_before_key'])
        ) {
            if ($this->request->query->has($this->paginationConfig['starting_after_key'])) {
                $entryDocumentId = $this->request->query->get($this->paginationConfig['starting_after_key']);
                $entryDocumentDirection = self::STARTING_AFTER;
            } else {
                $entryDocumentId = $this->request->query->get($this->paginationConfig['ending_before_key']);
                $entryDocumentDirection = self::ENDING_BEFORE;
            }

            $entryDocument = $this->dm->find($metadata->getName(), $entryDocumentId);
            if (!$entryDocument) {
                throw new ApiException(500, "No Entry document found");
            }
        } else {
            $entryDocumentDirection = self::NO_SKIP;
        }

        return ['entryDocumentDirection' => $entryDocumentDirection, 'entryDocument' => $entryDocument];
    }

    public function getSortData($metadata, $forbiddenFields, $entryDocumentDirection)
    {
        // Order By
        $order_by = $this->request->query->get($this->orderConfig['order_by_key']);

        $isValidOrderBy = $this->validateSortField($order_by, $metadata, $forbiddenFields);

        if (!$isValidOrderBy) {
            $order_by = 'id';
        }

        // Order
        $order = $this->request->query->get($this->paginationConfig['order_key']);
        if (in_array($order, $this->orderConfig['descendant_values'])) {
            $order = 'desc';
        } else {
            $order = 'asc';
        }

        // Reverse order if ending before is set
        if ($entryDocumentDirection == self::ENDING_BEFORE) {
            $order = ($order == 'desc') ? 'asc' : 'desc';
        }

        return ['order' => $order, 'order_by' => $order_by];
    }

    public function applySort(Builder $qb, $order_by, $order)
    {
        if ($order_by != 'id') {
            $qb->sort($order_by, $order);
        }
        // Second order_by to pagination
        $qb->sort('id', $order);
    }

    public function getLastDocument(Builder $qb, $order_by, $order)
    {
        $clone = clone $qb;

        $clone->limit(1);

        // Reverse order
        $order = ($order == 'desc') ? 'asc' : 'desc';

        if ($order_by != 'id') {
            $clone->sort($order_by, $order);
        }
        // Second order_by to pagination
        $clone->sort('id', $order);

        return $clone->find()->getQuery()->getSingleResult();
    }


    public function simplePaginate($document, $filters = [], $forbiddenSortFields = [], $excludeDeleted = true)
    {
        $metadata = $this->dm->getClassMetadata($document);

        // Create Query Builder
        $qb = $this->dm->createQueryBuilder($document);

        // Filters
        foreach ($filters as $key => $value) {
            $this->applyFilter($qb, $metadata, $key, $value);
        }

        return $this->paginate($qb, $forbiddenSortFields, $excludeDeleted);
    }

    public function paginate(Builder $qb, $forbiddenSortFields = [], $excludeDeleted = true)
    {
        $metadata = $qb->getQuery()->getClass();

        // Reflection id. Reflection allow property access without depends of the get method availability
        $reflectionId = $metadata->getReflectionProperty('id');
        $reflectionId->setAccessible(true);

        // Soft delete
        if ($excludeDeleted) {
            $qb->addAnd($qb->expr()->field($this->softDeletedKey)->notEqual(true));
        }

        // Total count
        $total = $qb->count()->getQuery()->execute();

        // Get Skip Data
        $skipData = $this->getSkipData($metadata);

        // Get Sort Data
        $orders = $this->getSortData(
            $metadata,
            $forbiddenSortFields,
            $skipData['entryDocumentDirection']
        );

        // Last document
        $lastDocument = $this->getLastDocument($qb, $orders['order_by'], $orders['order']);

        // Skip
        if ($skipData['entryDocumentDirection'] != self::NO_SKIP) {
            // Reflection OrderBy
            $reflectionSort = $metadata->getReflectionProperty($orders['order_by']);
            $reflectionSort->setAccessible(true);

            $reflectionSortValue = $reflectionSort->getValue($skipData['entryDocument']);

            $reflectionIdValue = $reflectionId->getValue($skipData['entryDocument']);

            $orderFunction = ($orders['order'] == 'asc') ? 'gt' : 'lt';

            $qb->addAnd(
                $qb->expr()->addOr(
                    $qb->expr()->addAnd(
                        $qb->expr()->field($orders['order_by'])->equals($reflectionSortValue),
                        $qb->expr()->field('id')->$orderFunction($reflectionIdValue)
                    ),
                    $qb->expr()->addAnd($qb->expr()->field($orders['order_by'])->$orderFunction($reflectionSortValue))
                )
            );
        }

        // Limit
        $this->applyLimit($qb);

        // Apply Sort
        $this->applySort($qb, $orders['order_by'], $orders['order']);

        // Eager cursor returns a numeric array instead of an associative array
        $qb->eagerCursor(true);

        $data = $qb->find()->getQuery()->execute()->toArray();

        $hasMoreIndex = count($data) - 1;

        // Ending_before needs reverse the array
        if ($skipData['entryDocumentDirection'] == self::ENDING_BEFORE) {
            $data = array_reverse($data);
            $hasMoreIndex = 0;
        }

        return [
            'data' => $data,
            'total' => $total,
            'has_more' => ($reflectionId->getValue($lastDocument) != $reflectionId->getValue($data[$hasMoreIndex]))
        ];
    }
}
