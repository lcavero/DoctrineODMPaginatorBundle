<?php

namespace LCV\CombinedConstraintsBundle\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class TextValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof Text) {
            throw new UnexpectedTypeException($constraint, __NAMESPACE__.'\Text');
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

        // Text settings

        if (!is_scalar($value) && !(\is_object($value) && method_exists($value, '__toString'))) {
            throw new UnexpectedValueException($value, 'string');
        }

        $stringValue = (string) $value;

        if (null !== $constraint->normalizer) {
            $stringValue = call_user_func($constraint->normalizer)($stringValue);
        }

        // Charset
        $invalidCharset = !@mb_check_encoding($stringValue, $constraint->charset);

        if ($invalidCharset) {
            $this->context->buildViolation($constraint->charsetMessage)
                ->setParameter('{{ value }}', $this->formatValue($stringValue))
                ->setParameter('{{ charset }}', $constraint->charset)
                ->setInvalidValue($value)
                ->setCode(Text::INVALID_CHARACTERS_ERROR)
                ->addViolation();

            return;
        }

        $length = mb_strlen($stringValue, $constraint->charset);

        // Max
        if (null !== $constraint->max && $length > $constraint->max) {
            $this->context->buildViolation($constraint->min == $constraint->max ? $constraint->exactMessage : $constraint->maxMessage)
                ->setParameter('{{ value }}', $this->formatValue($stringValue))
                ->setParameter('{{ limit }}', $constraint->max)
                ->setInvalidValue($value)
                ->setPlural((int) $constraint->max)
                ->setCode(Text::TOO_LONG_ERROR)
                ->addViolation();

            return;
        }

        // Min
        if (null !== $constraint->min && $length < $constraint->min) {
            $this->context->buildViolation($constraint->min == $constraint->max ? $constraint->exactMessage : $constraint->minMessage)
                ->setParameter('{{ value }}', $this->formatValue($stringValue))
                ->setParameter('{{ limit }}', $constraint->min)
                ->setInvalidValue($value)
                ->setPlural((int) $constraint->min)
                ->setCode(Text::TOO_SHORT_ERROR)
                ->addViolation();

            return;
        }
    }
}
