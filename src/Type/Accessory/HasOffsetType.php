<?php declare(strict_types = 1);

namespace PHPStan\Type\Accessory;

use PHPStan\Php\PhpVersion;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\TrinaryLogic;
use PHPStan\Type\AcceptsResult;
use PHPStan\Type\AnyType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\CompoundType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectWithoutClassType;
use PHPStan\Type\Traits\MaybeArrayTypeTrait;
use PHPStan\Type\Traits\MaybeCallableTypeTrait;
use PHPStan\Type\Traits\MaybeIterableTypeTrait;
use PHPStan\Type\Traits\MaybeObjectTypeTrait;
use PHPStan\Type\Traits\UndecidedComparisonCompoundTypeTrait;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;
use function sprintf;

class HasOffsetType extends AnyType implements CompoundType, AccessoryType
{

	use MaybeArrayTypeTrait;
	use MaybeCallableTypeTrait;
	use MaybeIterableTypeTrait;
	use MaybeObjectTypeTrait;
	use UndecidedComparisonCompoundTypeTrait;

	/**
	 * @api
	 * @param ConstantStringType|ConstantIntegerType $offsetType
	 */
	public function __construct(private Type $offsetType)
	{
	}

	public function describe(VerbosityLevel $level): string
	{
		return sprintf('hasOffset(%s)', $this->offsetType->describe($level));
	}

	public function toBoolean(): BooleanType
	{
		return new ConstantBooleanType(true);
	}

	/**
	 * @return ConstantStringType|ConstantIntegerType
	 */
	public function getOffsetType(): Type
	{
		return $this->offsetType;
	}

	public function acceptsWithReason(Type $type, bool $strictTypes): AcceptsResult
	{
		if ($type instanceof CompoundType) {
			return $type->isAcceptedWithReasonBy($this, $strictTypes);
		}

		return new AcceptsResult($type->isOffsetAccessible()->and($type->hasOffsetValueType($this->offsetType)), []);
	}

	public function isSuperTypeOf(Type $type): TrinaryLogic
	{
		if ($this->equals($type)) {
			return TrinaryLogic::createYes();
		}
		return $type->isOffsetAccessible()
			->and($type->hasOffsetValueType($this->offsetType));
	}

	public function isSubTypeOf(Type $otherType): TrinaryLogic
	{
		if ($otherType instanceof UnionType || $otherType instanceof IntersectionType) {
			return $otherType->isSuperTypeOf($this);
		}

		return $otherType->isOffsetAccessible()
			->and($otherType->hasOffsetValueType($this->offsetType))
			->and($otherType instanceof self ? TrinaryLogic::createYes() : TrinaryLogic::createMaybe());
	}

	public function isAcceptedBy(Type $acceptingType, bool $strictTypes): TrinaryLogic
	{
		return $this->isAcceptedWithReasonBy($acceptingType, $strictTypes)->result;
	}

	public function isAcceptedWithReasonBy(Type $acceptingType, bool $strictTypes): AcceptsResult
	{
		return new AcceptsResult($this->isSubTypeOf($acceptingType), []);
	}

	public function equals(Type $type): bool
	{
		return $type instanceof self
			&& $this->offsetType->equals($type->offsetType);
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
		if ($offsetType->isConstantScalarValue()->yes() && $offsetType->equals($this->offsetType)) {
			return TrinaryLogic::createYes();
		}

		return TrinaryLogic::createMaybe();
	}

	public function getOffsetValueType(Type $offsetType): Type
	{
		return new MixedType();
	}

	public function setOffsetValueType(?Type $offsetType, Type $valueType, bool $unionValues = true): Type
	{
		return $this;
	}

	public function setExistingOffsetValueType(Type $offsetType, Type $valueType): Type
	{
		return $this;
	}

	public function unsetOffset(Type $offsetType): Type
	{
		if ($this->offsetType->isSuperTypeOf($offsetType)->yes()) {
			return new ErrorType();
		}
		return $this;
	}

	public function fillKeysArray(Type $valueType): Type
	{
		return new NonEmptyArrayType();
	}

	public function intersectKeyArray(Type $otherArraysType): Type
	{
		if ($otherArraysType->hasOffsetValueType($this->offsetType)->yes()) {
			return $this;
		}

		return new MixedType();
	}

	public function shuffleArray(): Type
	{
		return new NonEmptyArrayType();
	}

	public function isIterableAtLeastOnce(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function isList(): TrinaryLogic
	{
		if ($this->offsetType->isString()->yes()) {
			return TrinaryLogic::createNo();
		}

		return TrinaryLogic::createMaybe();
	}

	public function isString(): TrinaryLogic
	{
		return TrinaryLogic::createMaybe();
	}

	public function isNumericString(): TrinaryLogic
	{
		return TrinaryLogic::createMaybe();
	}

	public function isNonEmptyString(): TrinaryLogic
	{
		return TrinaryLogic::createMaybe();
	}

	public function isNonFalsyString(): TrinaryLogic
	{
		return TrinaryLogic::createMaybe();
	}

	public function isLiteralString(): TrinaryLogic
	{
		return TrinaryLogic::createMaybe();
	}

	public function isClassStringType(): TrinaryLogic
	{
		return TrinaryLogic::createMaybe();
	}

	public function getClassStringObjectType(): Type
	{
		return new ObjectWithoutClassType();
	}

	public function getObjectTypeOrClassStringObjectType(): Type
	{
		return new ObjectWithoutClassType();
	}

	public function isScalar(): TrinaryLogic
	{
		return TrinaryLogic::createMaybe();
	}

	public function looseCompare(Type $type, PhpVersion $phpVersion): BooleanType
	{
		return new BooleanType();
	}

	public function getKeysArray(): Type
	{
		return new NonEmptyArrayType();
	}

	public function getValuesArray(): Type
	{
		return new NonEmptyArrayType();
	}

	public function toNumber(): Type
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

	public function toString(): Type
	{
		return new ErrorType();
	}

	public function toArray(): Type
	{
		return new MixedType();
	}

	public function toArrayKey(): Type
	{
		return new ErrorType();
	}

	public function exponentiate(Type $exponent): Type
	{
		return new ErrorType();
	}

	public function toPhpDocNode(): TypeNode
	{
		return new IdentifierTypeNode(''); // no PHPDoc representation
	}

	public static function __set_state(array $properties): Type
	{
		return new self($properties['offsetType']);
	}

}
