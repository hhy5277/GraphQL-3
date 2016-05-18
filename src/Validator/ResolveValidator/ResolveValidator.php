<?php
/**
 * Date: 01.12.15
 *
 * @author Portey Vasil <portey@gmail.com>
 */

namespace Youshido\GraphQL\Validator\ResolveValidator;

use Youshido\GraphQL\Field\AbstractField;
use Youshido\GraphQL\Field\InputField;
use Youshido\GraphQL\Parser\Ast\Argument;
use Youshido\GraphQL\Parser\Ast\ArgumentValue\Literal;
use Youshido\GraphQL\Parser\Ast\ArgumentValue\Variable;
use Youshido\GraphQL\Parser\Ast\Field as AstField;
use Youshido\GraphQL\Parser\Ast\Fragment;
use Youshido\GraphQL\Parser\Ast\FragmentReference;
use Youshido\GraphQL\Parser\Ast\Mutation;
use Youshido\GraphQL\Parser\Ast\Query;
use Youshido\GraphQL\Request;
use Youshido\GraphQL\Type\AbstractType;
use Youshido\GraphQL\Type\InterfaceType\AbstractInterfaceType;
use Youshido\GraphQL\Type\Object\AbstractObjectType;
use Youshido\GraphQL\Type\TypeMap;
use Youshido\GraphQL\Type\Union\AbstractUnionType;
use Youshido\GraphQL\Validator\ErrorContainer\ErrorContainerInterface;
use Youshido\GraphQL\Validator\ErrorContainer\ErrorContainerTrait;
use Youshido\GraphQL\Validator\Exception\ResolveException;

class ResolveValidator implements ResolveValidatorInterface, ErrorContainerInterface
{

    use ErrorContainerTrait;

    /**
     * @param AbstractObjectType      $objectType
     * @param Mutation|Query|AstField $field
     * @return null
     */
    public function objectHasField($objectType, $field)
    {
        if (!($objectType instanceof AbstractObjectType) || !$objectType->hasField($field->getName())) {
            $this->addError(new ResolveException(sprintf('Field "%s" not found in type "%s"', $field->getName(), $objectType->getNamedType()->getName())));

            return false;
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function validateArguments(AbstractField $field, $query, Request $request)
    {
        if (!count($field->getArguments())) return true;

        $requiredArguments = array_filter($field->getArguments(), function (InputField $argument) {
            return $argument->getType()->getKind() == TypeMap::KIND_NON_NULL;
        });

        $withDefaultArguments = array_filter($field->getArguments(), function (InputField $argument) {
            return $argument->getConfig()->get('default') !== null;
        });

        foreach ($query->getArguments() as $argument) {
            if (!$field->hasArgument($argument->getName())) {
                $this->addError(new ResolveException(sprintf('Unknown argument "%s" on field "%s"', $argument->getName(), $field->getName())));

                return false;
            }

            /** @var AbstractType $argumentType */
            $argumentType = $field->getArgument($argument->getName())->getType();
            if ($argument->getValue() instanceof Variable) {
                /** @var Variable $variable */
                $variable = $argument->getValue();

                if ($variable->getTypeName() !== $argumentType->getName()) {
                    $this->addError(new ResolveException(sprintf('Invalid variable "%s" type, allowed type is "%s"', $variable->getName(), $argumentType->getName())));

                    return false;
                }

                /** @var Variable $requestVariable */
                $requestVariable = $request->getVariable($variable->getName());
                if (!$requestVariable) {
                    $this->addError(new ResolveException(sprintf('Variable "%s" does not exist for query "%s"', $argument->getName(), $field->getName())));

                    return false;
                }
                $variable->setValue($requestVariable);

            }

            if (!$argumentType->isValidValue($argumentType->parseValue($argument->getValue()->getValue()))) {
                $this->addError(new ResolveException(sprintf('Not valid type for argument "%s" in query "%s"', $argument->getName(), $field->getName())));

                return false;
            }

            if (array_key_exists($argument->getName(), $requiredArguments)) {
                unset($requiredArguments[$argument->getName()]);
            }
            if (array_key_exists($argument->getName(), $withDefaultArguments)) {
                unset($withDefaultArguments[$argument->getName()]);
            }
        }

        if (count($requiredArguments)) {
            $this->addError(new ResolveException(sprintf('Require "%s" arguments to query "%s"', implode(', ', array_keys($requiredArguments)), $query->getName())));

            return false;
        }

        if (count($withDefaultArguments)) {
            foreach ($withDefaultArguments as $name => $argument) {
                $query->addArgument(new Argument($name, new Literal($argument->getConfig()->get('default'))));
            }
        }

        return true;
    }

    public function assertTypeImplementsInterface(AbstractType $type, AbstractInterfaceType $interface)
    {
        if (!$interface->isValidValue($type)) {
            throw new ResolveException('Type ' . $type->getName() . ' does not implement ' . $interface->getName());
        }
    }

    public function assertTypeInUnionTypes(AbstractType $type, AbstractUnionType $unionType)
    {
        $unionTypes = $unionType->getTypes();
        $valid      = false;

        foreach($unionTypes as $unionType) {
            if($unionType->getName() == $type->getName()) {
                $valid = true;

                break;
            }
        }

        if (!$valid) {
            throw new ResolveException('Type ' . $type->getName() . ' not exist in types of ' . $unionType->getName());
        }
    }

    /**
     * @param Fragment          $fragment
     * @param FragmentReference $fragmentReference
     * @param AbstractType      $queryType
     *
     * @throws \Exception
     */
    public function assertValidFragmentForField(Fragment $fragment, FragmentReference $fragmentReference, AbstractType $queryType)
    {
        if ($fragment->getModel() !== $queryType->getName()) {
            throw new ResolveException(sprintf('Fragment reference "%s" not found on model "%s"', $fragmentReference->getName(), $queryType->getName()));
        }
    }

    /**
     * @inheritdoc
     */
    public function validateResolvedValueType($value, $type)
    {
        switch ($type->getKind()) {
            case TypeMap::KIND_OBJECT:
            case TypeMap::KIND_INPUT_OBJECT:
            case TypeMap::KIND_INTERFACE:
                $isValid = is_object($value) || is_null($value) || is_array($value);
                break;
            case TypeMap::KIND_LIST:
                $isValid = is_null($value) || is_array($value) || (is_object($value) && in_array('IteratorAggregate', class_implements($value)));
                break;
            case TypeMap::KIND_SCALAR:
                $isValid = is_scalar($value);
                break;
            case TypeMap::KIND_NON_NULL:
            case TypeMap::KIND_ENUM:
            default:
                $isValid = $type->isValidValue($value);
        }

        if (!$isValid) {
            $this->addError(new ResolveException(sprintf('Not valid resolved value for "%s" type', $type->getName() ?: $type->getKind())));
        }

        return $isValid;
    }

}
