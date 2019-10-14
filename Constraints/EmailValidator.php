<?php

namespace LCV\CombinedConstraintsBundle\Constraints;


use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class EmailValidator extends \Symfony\Component\Validator\Constraints\EmailValidator
{

    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof Email) {
            throw new UnexpectedTypeException($constraint, __NAMESPACE__.'\Email');
        }

        // Allow null
        if ($constraint->allowNull && null === $value) {
            return;
        }

        // Required
        if ($constraint->required && (false === $value || (empty($value) && '0' != $value))) {
            $this->context->buildViolation($constraint->requiredMessage)
                ->setParameter('{{ value }}', $this->formatValue($value))
                ->setCode(Text::IS_BLANK_ERROR)
                ->addViolation();

            return;
        }

        parent::validate($value, $constraint);
    }
}
