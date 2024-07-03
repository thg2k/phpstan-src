<?php declare(strict_types = 1);

namespace PHPStan\Type;

use PHPStan\Php\PhpVersion;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Reflection\ClassMemberAccessAnswerer;
use PHPStan\Reflection\ConstantReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Reflection\Type\UnresolvedMethodPrototypeReflection;
use PHPStan\Reflection\Type\UnresolvedPropertyPrototypeReflection;
use PHPStan\ShouldNotHappenException;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Constant\ConstantBooleanType;
use function get_class;

class NonexistentParentClassType extends AnyType implements Type
{

	public function acceptsWithReason(Type $type, bool $strictTypes): AcceptsResult
	{
		if ($type instanceof static) {
			return AcceptsResult::createYes();
		}

		if ($type instanceof CompoundType) {
			return $type->isAcceptedWithReasonBy($this, $strictTypes);
		}

		return AcceptsResult::createNo();
	}

	public function isSuperTypeOf(Type $type): TrinaryLogic
	{
		if ($type instanceof self) {
			return TrinaryLogic::createYes();
		}

		if ($type instanceof CompoundType) {
			return $type->isSubTypeOf($this);
		}

		return TrinaryLogic::createNo();
	}

	public function equals(Type $type): bool
	{
		return get_class($type) === static::class;
	}

	public function getClassStringObjectType(): Type
	{
		return new ErrorType();
	}

	public function getObjectTypeOrClassStringObjectType(): Type
	{
		return new ErrorType();
	}

	public function describe(VerbosityLevel $level): string
	{
		return 'parent';
	}

	public function toBoolean(): BooleanType
	{
		return new ConstantBooleanType(true);
	}

	public function getTemplateType(string $ancestorClassName, string $templateTypeName): Type
	{
		return new ErrorType();
	}

	public function isObject(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
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

	public function isCloneable(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function toNumber(): Type
	{
		return new ErrorType();
	}

	public function toString(): Type
	{
		return new ErrorType();
	}

	public function toInteger(): Type
	{
		return new ErrorType();
	}

	public function toFloat(): Type
	{
		return new ErrorType();
	}

	public function toArray(): Type
	{
		return new ErrorType();
	}

	public function toArrayKey(): Type
	{
		return new ErrorType();
	}

	public function isOffsetAccessLegal(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function isScalar(): TrinaryLogic
	{
		return TrinaryLogic::createNo();
	}

	public function looseCompare(Type $type, PhpVersion $phpVersion): BooleanType
	{
		return new BooleanType();
	}

	public function exponentiate(Type $exponent): Type
	{
		return new ErrorType();
	}

	public function toPhpDocNode(): TypeNode
	{
		return new IdentifierTypeNode('parent');
	}

	/**
	 * @param mixed[] $properties
	 */
	public static function __set_state(array $properties): Type
	{
		return new self();
	}

}
