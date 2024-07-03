<?php declare(strict_types = 1);

namespace PHPStan\Type;

use PHPStan\Php\PhpVersion;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Accessory\AccessoryNumericStringType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantIntegerType;
use function get_class;

/** @api */
class IntegerType extends AnyType implements Type
{

	/** @api */
	public function __construct()
	{
	}

	public function describe(VerbosityLevel $level): string
	{
		return 'int';
	}

	public function isInteger(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function isScalar(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function toBoolean(): BooleanType
	{
		return new BooleanType();
	}

	public function toNumber(): Type
	{
		return $this;
	}

	public function toFloat(): Type
	{
		return new FloatType();
	}

	public function toInteger(): Type
	{
		return $this;
	}

	public function toString(): Type
	{
		return new IntersectionType([
			new StringType(),
			new AccessoryNumericStringType(),
		]);
	}

	public function toArray(): Type
	{
		return new ConstantArrayType(
			[new ConstantIntegerType(0)],
			[$this],
			[1],
			[],
			TrinaryLogic::createYes(),
		);
	}

	public function toArrayKey(): Type
	{
		return $this;
	}

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

	public function getConstantScalarTypes(): array
	{
		return [];
	}

	public function getConstantScalarValues(): array
	{
		return [];
	}

	public function getClassStringObjectType(): Type
	{
		return new ErrorType();
	}

	public function getObjectTypeOrClassStringObjectType(): Type
	{
		return new ErrorType();
	}

	public function isOffsetAccessLegal(): TrinaryLogic
	{
		return TrinaryLogic::createYes();
	}

	public function looseCompare(Type $type, PhpVersion $phpVersion): BooleanType
	{
		return new BooleanType();
	}

	public function tryRemove(Type $typeToRemove): ?Type
	{
		if ($typeToRemove instanceof IntegerRangeType || $typeToRemove instanceof ConstantIntegerType) {
			if ($typeToRemove instanceof IntegerRangeType) {
				$removeValueMin = $typeToRemove->getMin();
				$removeValueMax = $typeToRemove->getMax();
			} else {
				$removeValueMin = $typeToRemove->getValue();
				$removeValueMax = $typeToRemove->getValue();
			}
			$lowerPart = $removeValueMin !== null ? IntegerRangeType::fromInterval(null, $removeValueMin, -1) : null;
			$upperPart = $removeValueMax !== null ? IntegerRangeType::fromInterval($removeValueMax, null, +1) : null;
			if ($lowerPart !== null && $upperPart !== null) {
				return new UnionType([$lowerPart, $upperPart]);
			}
			return $lowerPart ?? $upperPart ?? new NeverType();
		}

		return null;
	}

	public function exponentiate(Type $exponent): Type
	{
		return ExponentiateHelper::exponentiate($this, $exponent);
	}

	public function toPhpDocNode(): TypeNode
	{
		return new IdentifierTypeNode('int');
	}

	/**
	 * @param mixed[] $properties
	 */
	public static function __set_state(array $properties): Type
	{
		return new self();
	}

}
