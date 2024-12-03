<?php declare(strict_types = 1);

namespace PHPStan\Type;

abstract class AnyType implements Type
{

	public function __toString(): string
	{
		return $this->describe(VerbosityLevel::precise());
	}

}
