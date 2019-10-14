<?php

namespace LCV\CombinedConstraintsBundle\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\InvalidArgumentException;

/**
 * Class Text
 * @package LCV\DoctrineODMPaginatorBundle\Constraints
 * @Annotation
 */
class Text extends Constraint
{
    const IS_BLANK_ERROR = 'c1051bb4-d103-4f74-8988-acbcafc7fdc3';
    const TOO_SHORT_ERROR = '9ff3fdc4-b214-49db-8718-39c315e33d45';
    const TOO_LONG_ERROR = 'd94b19cc-114f-4f44-9cc4-4138e80a87b9';
    const INVALID_CHARACTERS_ERROR = '35e6a710-aa2e-4719-b58e-24b35749b767';

    public $required = true;
    public $allowNull = false;
    public $max;
    public $min;

    public $normalizer;
    public $charset = 'UTF-8';

    public $requiredMessage = 'lcv.required';
    public $maxMessage = 'lcv.max_length_limit_mismatch';
    public $minMessage = 'lcv.min_length_limit_mismatch';
    public $exactMessage = 'lcv.exactly_length_limit_mismatch';
    public $charsetMessage = 'lcv.charset_mismatch';


    public function __construct($options = null)
    {
        if (\is_array($options) && isset($options['length']) && !isset($options['min']) && !isset($options['max'])) {
            $options['min'] = $options['max'] = $options['length'];
            unset($options['length']);
        }

        parent::__construct($options);

        if (null !== $this->normalizer && !\is_callable($this->normalizer)) {
            throw new InvalidArgumentException(sprintf('The "normalizer" option must be a valid callable ("%s" given).', \is_object($this->normalizer) ? \get_class($this->normalizer) : \gettype($this->normalizer)));
        }
    }

}
