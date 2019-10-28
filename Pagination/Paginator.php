<?php

namespace LCV\DoctrineODMPaginatorBundle\Pagination;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\MongoDBException;
use Doctrine\ODM\MongoDB\Query\Builder;
use LCV\ExceptionPackBundle\Exception\ApiException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class Paginator
{
    // Skip constants
    const NO_SKIP = 0; // First page, or not pagination
    const STARTING_AFTER = 1; // "Next" direction
    const ENDING_BEFORE = 2; // "Previous" direction

    /** @var DocumentManager $dm */
    private $dm;

    /** @var ManagerRegistry $registry */
    private $registry;

    private $sortConfig;
    private $paginationConfig;
    private $softDeletedKey;

    /** @var Request $request */
    private $request;

    /**
     * Paginator constructor.
     * @param ManagerRegistry $registry
     * @param RequestStack $requestStack
     * @param $sortConfig
     * @param $paginationConfig
     * @param $softDeletedKey
     */
    public function __construct(
        ManagerRegistry $registry,
        RequestStack $requestStack,
        $sortConfig,
        $paginationConfig,
        $softDeletedKey
    )
    {
        $this->registry = $registry;
        $this->request = $requestStack->getCurrentRequest();
        $this->sortConfig = $sortConfig;
        $this->paginationConfig = $paginationConfig;
        $this->softDeletedKey = $softDeletedKey;
    }

    /**
     * @param $document
     */
    private function configureManager($document)
    {
        $this->dm = $this->registry->getManagerForClass($document);
    }

    /**
     * @param $field
     * @param ClassMetadata $metadata
     * @param $forbiddenFields
     * @return bool
     */
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

    /**
     * @param Builder $qb
     * @param ClassMetadata $metadata
     * @param $field
     * @param $value
     */
    private function applyFilter(Builder $qb, ClassMetadata $metadata, $field, $value)
    {
        if (!$metadata->hasField($field) && !$metadata->hasAssociation($field)) {
            throw new ApiException(500, 'lcv.invalid_pagination_filter', ['filter' => $field, 'document' => $metadata->getName()]);
        }

        if ($metadata->hasField($field)) {
            // Field
            $qb->addAnd($qb->expr()->field($field)->equals($value));
        } else {
            // Association
            $qb->addAnd($qb->expr()->field($field)->references($value));
        }
    }

    /**
     * @param Builder $qb
     */
    private function applyLimit(Builder $qb)
    {
        $limit = $this->request->query->get($this->paginationConfig['limit_key']);

        if ($limit) {
            $qb->limit($limit);
        }
    }

    /**
     * @param ClassMetadata $metadata
     * @return array
     */
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
                throw new ApiException(500, 'lcv.document_not_found', ['id' => $entryDocumentId]);
            }
        } else {
            $entryDocumentDirection = self::NO_SKIP;
        }

        return ['entryDocumentDirection' => $entryDocumentDirection, 'entryDocument' => $entryDocument];
    }

    /**
     * @param $metadata
     * @param $forbiddenFields
     * @param $entryDocumentDirection
     * @return array
     */
    public function getSortData($metadata, $forbiddenFields, $entryDocumentDirection)
    {
        // Order By
        $order_by = $this->request->query->get($this->sortConfig['order_by_key']);

        $isValidOrderBy = $this->validateSortField($order_by, $metadata, $forbiddenFields);

        if (!$isValidOrderBy) {
            $order_by = 'id';
        }

        // Order
        $order = $this->request->query->get($this->sortConfig['order_key']);

        if (in_array($order, $this->sortConfig['descendant_values'])) {
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

    /**
     * @param Builder $qb
     * @param $order_by
     * @param $order
     */
    public function applySort(Builder $qb, $order_by, $order)
    {
        if ($order_by != 'id') {
            $qb->sort($order_by, $order);
        }
        // Second order_by to pagination
        $qb->sort('id', $order);
    }

    public function getFirstDocument(Builder $qb, $order_by, $order)
    {
        $clone = clone $qb;

        $clone->limit(1);

        if ($order_by != 'id') {
            $clone->sort($order_by, $order);
        }
        // Second order_by to pagination
        $clone->sort('id', $order);

        return $clone->find()->getQuery()->getSingleResult();
    }

    /**
     * @param Builder $qb
     * @param $order_by
     * @param $order
     * @return array|object|null
     */
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

    private function getPaginationUrls($data, $hasLimit, $entryDocumentDirecction, $isFirst, $isLast)
    {
        $paginationUrls = [];
        $url = $this->request->getSchemeAndHttpHost() . $this->request->getPathInfo();
        $first = true;

        if(!empty($data)){
            foreach ($this->request->query->all() as $key => $value){

                if(($key == $this->paginationConfig['starting_after_key']) || ($key == $this->paginationConfig['ending_before_key'])){
                    continue;

                }

                if(is_array($value)) {
                    foreach ($value as $k => $v) {
                        $url .= (($first) ? '?' : '&') . $key . "[$k]=" . $v;
                    }

                }else if(is_object($value)) {
                    $url .= (($first) ? '?' : '&') . $key . '=' . $value->getId();
                }else{
                    $url .= (($first) ? '?' : '&') . $key . '=' . $value;
                }

                $first = false;
            }

            // NO SKIP AND LIMIT
            if($entryDocumentDirecction == self::NO_SKIP && $hasLimit){
                $paginationUrls['nextUrl'] = $url . '&' . $this->paginationConfig['starting_after_key'] . '=' . $data[count($data) - 1]->getId();
            }

            if($entryDocumentDirecction == self::STARTING_AFTER){
                if($hasLimit && !$isLast){
                    $paginationUrls['nextUrl'] = $url . '&' . $this->paginationConfig['starting_after_key'] . '=' . $data[count($data) - 1]->getId();
                }
                if(!$isFirst){
                    $paginationUrls['prevUrl'] = $url . '&' . $this->paginationConfig['ending_before_key'] . '=' . $data[0]->getId();
                }
            }

            if($entryDocumentDirecction == self::ENDING_BEFORE) {
                if($hasLimit && !$isFirst) {
                    $paginationUrls['prevUrl'] = $url . '&' . $this->paginationConfig['ending_before_key'] . '=' . $data[0]->getId();
                }

                if(!$isLast){
                    $paginationUrls['nextUrl'] = $url . '&' . $this->paginationConfig['starting_after_key'] . '=' . $data[count($data) - 1]->getId();
                }
            }
        }
        return $paginationUrls;
    }



    public function provideNextData($data, \ReflectionProperty $reflectionId, $entryDocumentDirection, $firstDocument, $lastDocument)
    {
        $result = ['has_next' => false, 'has_prev' => false];
        if(!empty($data)){

            if($entryDocumentDirection == self::ENDING_BEFORE){
                if($firstDocument != null){
                    $firstDataDocumentIndex = count($data) - 1;

                    if($reflectionId->getValue($firstDocument) != $reflectionId->getValue($data[$firstDataDocumentIndex])){
                        $result['has_next'] = true;

                    }
                }

                if($lastDocument != null){
                    $lastDataDocumentIndex =  0 ;
                    if($reflectionId->getValue($lastDocument) != $reflectionId->getValue($data[$lastDataDocumentIndex])){
                        $result['has_prev'] = true;
                    }
                }
            }else{
                if($lastDocument != null){
                    $lastDataDocumentIndex = count($data) - 1;
                    if($reflectionId->getValue($lastDocument) != $reflectionId->getValue($data[$lastDataDocumentIndex])){
                        $result['has_next'] = true;

                    }
                }

                if($firstDocument != null){
                    $firstDataDocumentIndex =  0 ;
                    if($reflectionId->getValue($firstDocument) != $reflectionId->getValue($data[$firstDataDocumentIndex])){
                        $result['has_prev'] = true;
                    }
                }
            }

            $has_limit = $this->request->query->has($this->paginationConfig['limit_key']);

            $paginationUrls = $this->getPaginationUrls(
                $data,
                $has_limit,
                $entryDocumentDirection,
                !$result['has_prev'],
                !$result['has_next']
            );

            if(isset($paginationUrls['nextUrl'])) $result['nextUrl'] = $paginationUrls['nextUrl'];
            if(isset($paginationUrls['prevUrl'])) $result['prevUrl'] = $paginationUrls['prevUrl'];
        }

        return $result;
    }


    /**
     * @param $document
     * @param array $filters
     * @param array $forbiddenSortFields
     * @param bool $excludeDeleted
     * @return array
     * @throws MongoDBException
     */
    public function simplePaginate($document, $filters = [], $forbiddenSortFields = [], $excludeDeleted = true)
    {
        $this->configureManager($document);
        $metadata = $this->dm->getClassMetadata($document);

        // Create Query Builder
        $qb = $this->dm->createQueryBuilder($document);

        // Filters
        foreach ($filters as $key => $value) {
            $this->applyFilter($qb, $metadata, $key, $value);
        }

        return $this->paginate($qb, $forbiddenSortFields, $excludeDeleted);
    }

    /**
     * @param Builder $qb
     * @param array $forbiddenSortFields
     * @param bool $excludeDeleted
     * @return array
     * @throws MongoDBException
     */
    public function paginate(Builder $qb, $forbiddenSortFields = [], $excludeDeleted = true)
    {

        $metadata = $qb->getQuery()->getClass();
        $this->configureManager($metadata->getName());

        // Reflection id. Reflection allow property access without depends of the get method availability
        $reflectionId = $metadata->getReflectionProperty('id');
        $reflectionId->setAccessible(true);

        // Soft delete
        if ($excludeDeleted) {
            $qb->addAnd($qb->expr()->field($this->softDeletedKey)->equals(null));
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

        // First and Last documents
        $firstDocument = null;
        $lastDocument = null;
        if($total > 0){
            $firstDocument = $this->getFirstDocument($qb, $orders['order_by'], $orders['order']);
            $lastDocument = $this->getLastDocument($qb, $orders['order_by'], $orders['order']);
        }


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

        $data = $qb->find()->getQuery()->execute()->toArray();

        // Ending_before needs reverse the array
        if ($skipData['entryDocumentDirection'] == self::ENDING_BEFORE) {
            $data = array_reverse($data);
        }


        $nextData = $this->provideNextData($data, $reflectionId, $skipData['entryDocumentDirection'], $firstDocument, $lastDocument);

        $returnStructure = [
            'data' => $data,
            'total' => $total,
            'has_next' => $nextData['has_next'],
            'has_prev' => $nextData['has_prev']
        ];

        if(isset($nextData['nextUrl'])) $returnStructure['next_url'] = $nextData['nextUrl'];
        if(isset($nextData['prevUrl'])) $returnStructure['prev_url'] = $nextData['prevUrl'];


        return $returnStructure;
    }
}