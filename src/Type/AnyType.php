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

	public function tryRemove(Type $typeToRemove): ?Type
	{
		return null;
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

	public function isCallable(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
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

	public function isArray(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isConstantArray(): TrinaryLogic
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

	public function isObject(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isEnum(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
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

	public function isCloneable(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function getEnumCases(): array
	{
		return [];
	}

	public function getTemplateType(string $ancestorClassName, string $templateTypeName): Type
	{
		return new ErrorType();
	}

	public function isIterable(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isIterableAtLeastOnce(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
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

}
