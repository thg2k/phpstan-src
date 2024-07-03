<?php declare(strict_types = 1);

namespace PHPStan\Type;

use PHPStan\Reflection\ClassMemberAccessAnswerer;
use PHPStan\Reflection\ConstantReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Reflection\Type\UnresolvedMethodPrototypeReflection;
use PHPStan\Reflection\Type\UnresolvedPropertyPrototypeReflection;
use PHPStan\ShouldNotHappenException;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\Generic\TemplateTypeVariance;

abstract class AnyType implements Type
{

	public function isVoid(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isNull(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isFalse(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isTrue(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isBoolean(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isInteger(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isFloat(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isString(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isNumericString(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isNonEmptyString(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isNonFalsyString(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isLiteralString(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isClassStringType(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isCallable(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isIterable(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isIterableAtLeastOnce(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isArray(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isOversizedArray(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isList(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isObject(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isCloneable(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isEnum(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isConstantValue(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isConstantScalarValue(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isConstantArray(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isOffsetAccessible(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isScalar(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function tryRemove(Type $typeToRemove): ?Type
	{
		return null;
	}

	public function traverse(callable $cb): Type
	{
		return $this;
	}

	public function traverseSimultaneously(Type $right, callable $cb): Type
	{
		return $this;
	}

	public function generalize(GeneralizePrecision $precision): Type
	{
		return $this->traverse(static fn (Type $type) => $type->generalize($precision));
	}

	public function inferTemplateTypes(Type $receivedType): TemplateTypeMap
	{
		return TemplateTypeMap::createEmpty();
	}

	public function getReferencedTemplateTypes(TemplateTypeVariance $positionVariance): array
	{
		return [];
	}

	public function getCallableParametersAcceptors(ClassMemberAccessAnswerer $scope): array
	{
		throw new ShouldNotHappenException();
	}

	public function getArrays(): array
	{
		return [];
	}

	public function getConstantArrays(): array
	{
		return [];
	}

	public function getKeysArray(): Type
	{
		return new ErrorType();
	}

	public function getValuesArray(): Type
	{
		return new ErrorType();
	}

	public function fillKeysArray(Type $valueType): Type
	{
		return new ErrorType();
	}

	public function flipArray(): Type
	{
		return new ErrorType();
	}

	public function intersectKeyArray(Type $otherArraysType): Type
	{
		return new ErrorType();
	}

	public function popArray(): Type
	{
		return new ErrorType();
	}

	public function searchArray(Type $needleType): Type
	{
		return new ErrorType();
	}

	public function shiftArray(): Type
	{
		return new ErrorType();
	}

	public function shuffleArray(): Type
	{
		return new ErrorType();
	}

	public function canAccessProperties(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
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
		return TrinaryLogic::createNo();
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
		return TrinaryLogic::createNo();
	}

	public function hasConstant(string $constantName): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function getConstant(string $constantName): ConstantReflection
	{
		throw new ShouldNotHappenException();
	}

	public function getConstantStrings(): array
	{
		return [];
	}

	public function getEnumCases(): array
	{
		return [];
	}

	public function getTemplateType(string $ancestorClassName, string $templateTypeName): Type
	{
		return new ErrorType();
	}

	public function getArraySize(): Type
	{
		return new ErrorType();
	}

	public function getIterableKeyType(): Type
	{
		return new ErrorType();
	}

	public function getFirstIterableKeyType(): Type
	{
		return new ErrorType();
	}

	public function getLastIterableKeyType(): Type
	{
		return new ErrorType();
	}

	public function getIterableValueType(): Type
	{
		return new ErrorType();
	}

	public function getFirstIterableValueType(): Type
	{
		return new ErrorType();
	}

	public function getLastIterableValueType(): Type
	{
		return new ErrorType();
	}

	public function hasOffsetValueType(Type $offsetType): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function getOffsetValueType(Type $offsetType): Type
	{
		return new ErrorType();
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
		return new ErrorType();
	}

	public function isSmallerThan(Type $otherType): TrinaryLogic
	{
		return TrinaryLogic::createMaybe();
	}

	public function isSmallerThanOrEqual(Type $otherType): TrinaryLogic
	{
		return TrinaryLogic::createMaybe();
	}

	public function getSmallerType(): Type
	{
		return new MixedType();
	}

	public function getSmallerOrEqualType(): Type
	{
		return new MixedType();
	}

	public function getGreaterType(): Type
	{
		return new MixedType();
	}

	public function getGreaterOrEqualType(): Type
	{
		return new MixedType();
	}

	public function getFiniteTypes(): array
	{
		return [];
	}

	public function accepts(Type $type, bool $strictTypes): TrinaryLogic
	{
		return $this->acceptsWithReason($type, $strictTypes)->result;
	}

	public function getReferencedClasses(): array
	{
		return [];
	}

	public function getObjectClassNames(): array
	{
		return [];
	}

	public function getObjectClassReflections(): array
	{
		return [];
	}

	public function getConstantScalarTypes(): array
	{
		return [];
	}

	public function getConstantScalarValues(): array
	{
		return [];
	}

}
