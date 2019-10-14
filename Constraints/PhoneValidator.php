<?php


namespace LCV\CombinedConstraintsBundle\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class PhoneValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof Phone) {
            throw new UnexpectedTypeException($constraint, __NAMESPACE__.'\Phone');
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

        if (null !== $constraint->normalizer) {
            $value = call_user_func($constraint->normalizer)($value);
        }

        if (!is_scalar($value) && !(\is_object($value) && method_exists($value, '__toString'))) {
            throw new UnexpectedValueException($value, 'string');
        }

        $value = (string) $value;

        if (null !== $constraint->normalizer) {
            $value = call_user_func($constraint->normalizer)($value);
        }

        if (!preg_match("/^(\+?\(?\d+-?\)?\s?){1}(\d+-?\s?)+$/", $value)) {
            $this->context->buildViolation($constraint->invalidMessage)
                ->setParameter('{{ value }}', $this->formatValue($value))
                ->setCode(Phone::REGEX_FAILED_ERROR)
                ->addViolation();
        }
    }
}
