<?php

namespace LCV\CombinedConstraintsBundle\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\InvalidArgumentException;

/**
 * Class Phone
 * @package LCV\DoctrineODMPaginatorBundle\Constraints
 * @Annotation
 */
class Phone extends Constraint
{
    const IS_BLANK_ERROR = 'c1051bb4-d103-4f74-8988-acbcafc7fdc3';
    const REGEX_FAILED_ERROR = 'de1e3db3-5ed4-4941-aae4-59f3667cc3a3';

    public $required = true;
    public $allowNull = false;

    public $normalizer;

    public $requiredMessage = 'lcv.required';
    public $invalidMessage = 'lcv.invalid_phone';


    public function __construct($options = null)
    {
        parent::__construct($options);

        if (null !== $this->normalizer && !\is_callable($this->normalizer)) {
            throw new InvalidArgumentException(sprintf('The "normalizer" option must be a valid callable ("%s" given).', \is_object($this->normalizer) ? \get_class($this->normalizer) : \gettype($this->normalizer)));
        }
    }

}
