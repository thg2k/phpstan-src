<?php declare(strict_types = 1);

namespace PHPStan\Type;

use PHPStan\Php\PhpVersion;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Reflection\ClassMemberAccessAnswerer;
use PHPStan\Reflection\ConstantReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Reflection\TrivialParametersAcceptor;
use PHPStan\Reflection\Type\UnresolvedMethodPrototypeReflection;
use PHPStan\Reflection\Type\UnresolvedPropertyPrototypeReflection;
use PHPStan\ShouldNotHappenException;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Traits\UndecidedComparisonCompoundTypeTrait;

/** @api */
class NeverType extends AnyType implements CompoundType
{

	use UndecidedComparisonCompoundTypeTrait;

	/** @api */
	public function __construct(private bool $isExplicit = false)
	{
	}

	public function describe(VerbosityLevel $level): string
	{
		return '*NEVER*';
	}

	public function toBoolean(): BooleanType
	{
		return new BooleanType();
	}

	public function toNumber(): Type
	{
		return $this;
	}

	public function toString(): Type
	{
		return $this;
	}

	public function toInteger(): Type
	{
		return $this;
	}

	public function toFloat(): Type
	{
		return $this;
	}

	public function toArray(): Type
	{
		return $this;
	}

	public function toArrayKey(): Type
	{
		return $this;
	}

	public function isExplicit(): bool
	{
		return $this->isExplicit;
	}

	public function getArrays(): array
	{
		return [];
	}

	public function getConstantArrays(): array
	{
		return [];
	}

	public function acceptsWithReason(Type $type, bool $strictTypes): AcceptsResult
	{
		return AcceptsResult::createYes();
	}

	public function isSuperTypeOf(Type $type): TrinaryLogic
	{
		if ($type instanceof self) {
			return TrinaryLogic::createYes();
		}

		return TrinaryLogic::createNo();
	}

	public function equals(Type $type): bool
	{
		return $type instanceof self;
	}

	public function isSubTypeOf(Type $otherType): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function isAcceptedBy(Type $acceptingType, bool $strictTypes): TrinaryLogic
	{
		return $this->isAcceptedWithReasonBy($acceptingType, $strictTypes)->result;
	}

	public function isAcceptedWithReasonBy(Type $acceptingType, bool $strictTypes): AcceptsResult
	{
		return new AcceptsResult($this->isSubTypeOf($acceptingType), []);
	}

	public function getTemplateType(string $ancestorClassName, string $templateTypeName): Type
	{
		return new NeverType();
	}

	public function canAccessProperties(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function hasProperty(string $propertyName): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function getProperty(string $propertyName, ClassMemberAccessAnswerer $scope): PropertyReflection
	{
		throw new ShouldNotHappenException();
	}

	public function getUnresolvedPropertyPrototype(string $propertyName, ClassMemberAccessAnswerer $scope): UnresolvedPropertyPrototypeReflection
	{
		throw new ShouldNotHappenException();
	}

	public function canCallMethods(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function hasMethod(string $methodName): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function getMethod(string $methodName, ClassMemberAccessAnswerer $scope): ExtendedMethodReflection
	{
		throw new ShouldNotHappenException();
	}

	public function getUnresolvedMethodPrototype(string $methodName, ClassMemberAccessAnswerer $scope): UnresolvedMethodPrototypeReflection
	{
		throw new ShouldNotHappenException();
	}

	public function canAccessConstants(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function hasConstant(string $constantName): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function getConstant(string $constantName): ConstantReflection
	{
		throw new ShouldNotHappenException();
	}

	public function isIterable(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function isIterableAtLeastOnce(): TrinaryLogic
	{
		return TrinaryLogic::createMaybe();
	}

	public function getArraySize(): Type
	{
		return new NeverType();
	}

	public function getIterableKeyType(): Type
	{
		return new NeverType();
	}

	public function getFirstIterableKeyType(): Type
	{
		return new NeverType();
	}

	public function getLastIterableKeyType(): Type
	{
		return new NeverType();
	}

	public function getIterableValueType(): Type
	{
		return new NeverType();
	}

	public function getFirstIterableValueType(): Type
	{
		return new NeverType();
	}

	public function getLastIterableValueType(): Type
	{
		return new NeverType();
	}

	public function isOffsetAccessible(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function isOffsetAccessLegal(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function hasOffsetValueType(Type $offsetType): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function getOffsetValueType(Type $offsetType): Type
	{
		return new NeverType();
	}

	public function setOffsetValueType(?Type $offsetType, Type $valueType, bool $unionValues = true): Type
	{
		return new ErrorType();
	}

	public function setExistingOffsetValueType(Type $offsetType, Type $valueType): Type
	{
		return new ErrorType();
	}

	public function unsetOffset(Type $offsetType): Type
	{
		return new NeverType();
	}

	public function getKeysArray(): Type
	{
		return new NeverType();
	}

	public function getValuesArray(): Type
	{
		return new NeverType();
	}

	public function fillKeysArray(Type $valueType): Type
	{
		return new NeverType();
	}

	public function flipArray(): Type
	{
		return new NeverType();
	}

	public function intersectKeyArray(Type $otherArraysType): Type
	{
		return new NeverType();
	}

	public function popArray(): Type
	{
		return new NeverType();
	}

	public function searchArray(Type $needleType): Type
	{
		return new NeverType();
	}

	public function shiftArray(): Type
	{
		return new NeverType();
	}

	public function shuffleArray(): Type
	{
		return new NeverType();
	}

	public function isCallable(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function getCallableParametersAcceptors(ClassMemberAccessAnswerer $scope): array
	{
		return [new TrivialParametersAcceptor()];
	}

	public function isCloneable(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function getClassStringObjectType(): Type
	{
		return new ErrorType();
	}

	public function getObjectTypeOrClassStringObjectType(): Type
	{
		return new ErrorType();
	}

	public function looseCompare(Type $type, PhpVersion $phpVersion): BooleanType
	{
		return new BooleanType();
	}

	public function exponentiate(Type $exponent): Type
	{
		return $this;
	}

	public function toPhpDocNode(): TypeNode
	{
		return new IdentifierTypeNode('never');
	}

	/**
	 * @param mixed[] $properties
	 */
	public static function __set_state(array $properties): Type
	{
		return new self($properties['isExplicit']);
	}

}
