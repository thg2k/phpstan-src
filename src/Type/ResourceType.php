<?php declare(strict_types = 1);

namespace PHPStan\Type;

use PHPStan\Php\PhpVersion;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantIntegerType;
use function get_class;

/** @api */
class ResourceType extends AnyType implements Type
{

	/** @api */
	public function __construct()
	{
	}

	public function describe(VerbosityLevel $level): string
	{
		return 'resource';
	}

	public function toBoolean(): BooleanType
	{
		return new ConstantBooleanType(true);
	}

	public function toNumber(): Type
	{
		return new ErrorType();
	}

	public function toInteger(): Type
	{
		return new IntegerType();
	}

	public function toFloat(): Type
	{
		return new ErrorType();
	}

	public function toString(): Type
	{
		return new StringType();
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
		return new ErrorType();
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

	public function exponentiate(Type $exponent): Type
	{
		return new ErrorType();
	}

	public function toPhpDocNode(): TypeNode
	{
		return new IdentifierTypeNode('resource');
	}

	/**
	 * @param mixed[] $properties
	 */
	public static function __set_state(array $properties): Type
	{
		return new self();
	}

}
