<?php declare(strict_types = 1);

namespace PHPStan\Type;

use PHPStan\Reflection\ClassMemberAccessAnswerer;
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

}
