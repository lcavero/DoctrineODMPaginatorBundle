<?php

namespace LCV\CombinedConstraintsBundle\Formulary;

use LCV\ExceptionPackBundle\Exception\EmptyFormularyException;
use LCV\ExceptionPackBundle\Exception\InvalidFormularyNameException;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintViolation;

class FormularyChecker
{
    private $errors;

    /**
     * FormularyValidator constructor.
     */
    public function __construct()
    {
        $this->errors = [];
    }

    /**
     * validate
     * @param FormInterface $form
     * @param Request $request
     * @return bool|array
     */
    public function validate(FormInterface $form, Request $request)
    {
        if($form->isSubmitted()&& $form->isValid()){
            return false;
        }else{
            $this->chekEmptyForm($form, $request);
            $this->checkFormErrors($form, true);
            return $this->errors ?: false;
        }
    }

    /**
     * @param FormInterface $form
     * @param Request $request
     */
    private function chekEmptyForm(FormInterface $form, Request $request)
    {
        $method = $form->getConfig()->getMethod();
        $name = $form->getName();

        if('GET' === $method || 'HEAD' === $method || 'TRACE' === $method){
            if('' === $name){
                if(empty($request->query->all())){
                    throw new EmptyFormularyException();
                }
            }else{
                if(!$request->query->has($name)){
                    throw new InvalidFormularyNameException($name);
                }
            }
        }else{
            if('' === $name){
                if(empty($request->request->all()) && empty($request->files->all())){
                    throw new EmptyFormularyException();
                }
            }elseif(!$request->request->has($name) && (!$request->files->has($name))){
                throw new InvalidFormularyNameException($name);
            }
        }
    }

    /**
     * @param Form $form
     * @param bool $first
     * @param string $parentName
     */
    private function checkFormErrors(Form $form, $first = false, $parentName = '')
    {
        if($first){
            $parentName .= $form->getName();
        }else{
            $parentName .= '.' . $form->getName();
        }

        foreach($form->getErrors() as $key => $error){
            $warn = false;
            if($first){
                if(($cause = $error->getCause()) instanceof ConstraintViolation){
                    if($this->isUniqueConstraint($cause->getConstraint())){
                        $warn = false;
                    }
                }
                if($warn){
                    $this->errors['FORM_WARNING'][$parentName] = "Looks like you have some validations in your entity which have not been added on your formulary or his childrens";
                }
            }

            if($error->getMessageTemplate() == 'This value is not valid.'){
                $cause = $error->getCause();
                if($cause instanceof ConstraintViolation){
                    $invalid_value = $cause->getInvalidValue();
                    if(is_array($cause->getInvalidValue())){
                        $invalid_value = join(", ", $cause->getInvalidValue());
                    }
                    $this->errors[$parentName] = $error->getMessage() . ' (' . $invalid_value . ')';
                }
            }else if($error->getMessageTemplate() == 'The CSRF token is invalid. Please try to resubmit the form.') {
                $this->errors['root'] = $error->getMessageTemplate();
            }else {
                $this->errors[$parentName] = $error->getMessage();
            }

        }

        if($form->count()){
            foreach ($form->getIterator() as $child) {
                if(!$child->isValid()){
                    $this->checkFormErrors($child, false, $parentName);
                }
            }
        }
    }

    private function isUniqueConstraint(Constraint $constraint)
    {
        switch (get_class($constraint)){
            case 'Doctrine\Bundle\MongoDBBundle\Validator\Constraints\Unique':
            case 'Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity':
            {
                return true;
            }
        }
        return false;
    }
}
