<?php declare(strict_types = 1);

namespace PHPStan\Type;

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

}
