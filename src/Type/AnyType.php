<?php declare(strict_types = 1);

namespace PHPStan\Type;

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

}
