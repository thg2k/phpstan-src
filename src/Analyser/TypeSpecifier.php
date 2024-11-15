<?php declare(strict_types = 1);

namespace PHPStan\Analyser;

use Countable;
use PhpParser\Node;
use PHPStan\Node\Expr\AlwaysRememberedExpr;
use PHPStan\Node\IssetExpr;
use PHPStan\Node\Printer\ExprPrinter;
use PHPStan\Php\PhpVersion;
use PHPStan\Reflection\Assertions;
use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Reflection\ResolvedFunctionVariant;
use PHPStan\Rules\Arrays\AllowedArrayKeysTypes;
use PHPStan\ShouldNotHappenException;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\Accessory\AccessoryNonEmptyStringType;
use PHPStan\Type\Accessory\AccessoryNonFalsyStringType;
use PHPStan\Type\Accessory\HasOffsetType;
use PHPStan\Type\Accessory\HasPropertyType;
use PHPStan\Type\Accessory\NonEmptyArrayType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\ConditionalTypeForParameter;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantFloatType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\ConstantScalarType;
use PHPStan\Type\FloatType;
use PHPStan\Type\FunctionTypeSpecifyingExtension;
use PHPStan\Type\Generic\GenericClassStringType;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\Generic\TemplateTypeHelper;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\Generic\TemplateTypeVarianceMap;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MethodTypeSpecifyingExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\NonexistentParentClassType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ObjectWithoutClassType;
use PHPStan\Type\ResourceType;
use PHPStan\Type\StaticMethodTypeSpecifyingExtension;
use PHPStan\Type\StaticType;
use PHPStan\Type\StaticTypeFactory;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\UnionType;
use function array_key_exists;
use function array_map;
use function array_merge;
use function array_reverse;
use function array_shift;
use function count;
use function in_array;
use function is_string;
use function strtolower;
use function substr;
use const COUNT_NORMAL;

final class TypeSpecifier
{

	/** @var MethodTypeSpecifyingExtension[][]|null */
	private ?array $methodTypeSpecifyingExtensionsByClass = null;

	/** @var StaticMethodTypeSpecifyingExtension[][]|null */
	private ?array $staticMethodTypeSpecifyingExtensionsByClass = null;

	/**
	 * @param FunctionTypeSpecifyingExtension[] $functionTypeSpecifyingExtensions
	 * @param MethodTypeSpecifyingExtension[] $methodTypeSpecifyingExtensions
	 * @param StaticMethodTypeSpecifyingExtension[] $staticMethodTypeSpecifyingExtensions
	 */
	public function __construct(
		private ExprPrinter $exprPrinter,
		private ReflectionProvider $reflectionProvider,
		private PhpVersion $phpVersion,
		private array $functionTypeSpecifyingExtensions,
		private array $methodTypeSpecifyingExtensions,
		private array $staticMethodTypeSpecifyingExtensions,
		private bool $rememberPossiblyImpureFunctionValues,
	)
	{
		foreach (array_merge($functionTypeSpecifyingExtensions, $methodTypeSpecifyingExtensions, $staticMethodTypeSpecifyingExtensions) as $extension) {
			if (!($extension instanceof TypeSpecifierAwareExtension)) {
				continue;
			}

			$extension->setTypeSpecifier($this);
		}
	}

	/** @api */
	public function specifyTypesInCondition(
		Scope $scope,
		Node\Expr $expr,
		TypeSpecifierContext $context,
	): SpecifiedTypes
	{
		if ($expr instanceof Node\Expr\CallLike && $expr->isFirstClassCallable()) {
			return (new SpecifiedTypes([], []))->setRootExpr($expr);
		}

		// $a instanceof $b
		if ($expr instanceof Node\Expr\Instanceof_) {
			$exprNode = $expr->expr;
			if ($expr->class instanceof Node\Name) {
				$className = (string) $expr->class;
				$lowercasedClassName = strtolower($className);
				if ($lowercasedClassName === 'self' && $scope->isInClass()) {
					$type = new ObjectType($scope->getClassReflection()->getName());
				} elseif ($lowercasedClassName === 'static' && $scope->isInClass()) {
					$type = new StaticType($scope->getClassReflection());
				} elseif ($lowercasedClassName === 'parent') {
					if (
						$scope->isInClass()
						&& $scope->getClassReflection()->getParentClass() !== null
					) {
						$type = new ObjectType($scope->getClassReflection()->getParentClass()->getName());
					} else {
						$type = new NonexistentParentClassType();
					}
				} else {
					$type = new ObjectType($className);
				}
				return $this->create($exprNode, $type, $context, $scope)->setRootExpr($expr);
			}

			$classType = $scope->getType($expr->class);
			$type = TypeTraverser::map($classType, static function (Type $type, callable $traverse): Type {
				if ($type instanceof UnionType || $type instanceof IntersectionType) {
					return $traverse($type);
				}
				if ($type->getObjectClassNames() !== []) {
					return $type;
				}
				if ($type instanceof GenericClassStringType) {
					return $type->getGenericType();
				}
				if ($type instanceof ConstantStringType) {
					return new ObjectType($type->getValue());
				}
				return new MixedType();
			});

			if (!$type->isSuperTypeOf(new MixedType())->yes()) {
				if ($context->true()) {
					$type = TypeCombinator::intersect(
						$type,
						new ObjectWithoutClassType(),
					);
					return $this->create($exprNode, $type, $context, $scope)->setRootExpr($expr);
				} elseif ($context->false()) {
					$exprType = $scope->getType($expr->expr);
					if (!$type->isSuperTypeOf($exprType)->yes()) {
						return $this->create($exprNode, $type, $context, $scope)->setRootExpr($expr);
					}
				}
			}
			if ($context->true()) {
				return $this->create($exprNode, new ObjectWithoutClassType(), $context, $scope)->setRootExpr($exprNode);
			}

			return (new SpecifiedTypes([], []))->setRootExpr($expr);
		}

		// $a === $b
		if ($expr instanceof Node\Expr\BinaryOp\Identical) {
			return $this->resolveIdentical($expr, $scope, $context);
		}

		// $a !== $b
		if ($expr instanceof Node\Expr\BinaryOp\NotIdentical) {
			// convert to: !($a === $b)
			return $this->specifyTypesInCondition(
				$scope,
				new Node\Expr\BooleanNot(new Node\Expr\BinaryOp\Identical($expr->left, $expr->right)),
				$context,
			)->setRootExpr($expr);
		}

		// (bool) $a
		if ($expr instanceof Node\Expr\Cast\Bool_) {
			// convert to: ($a == true)
			return $this->specifyTypesInCondition(
				$scope,
				new Node\Expr\BinaryOp\Equal($expr->expr, new Node\Expr\ConstFetch(new Node\Name\FullyQualified('true'))),
				$context,
			)->setRootExpr($expr);
		}

		// (string) $a
		if ($expr instanceof Node\Expr\Cast\String_) {
			// convert to: ($a != "")
			return $this->specifyTypesInCondition(
				$scope,
				new Node\Expr\BinaryOp\NotEqual($expr->expr, new Node\Scalar\String_('')),
				$context,
			)->setRootExpr($expr);
		}

		// (int) $a
		if ($expr instanceof Node\Expr\Cast\Int_) {
			// convert to: ($a != 0)
			return $this->specifyTypesInCondition(
				$scope,
				new Node\Expr\BinaryOp\NotEqual($expr->expr, new Node\Scalar\LNumber(0)),
				$context,
			)->setRootExpr($expr);
		}

		// (double) $a
		if ($expr instanceof Node\Expr\Cast\Double) {
			// convert to: ($a != 0.0)
			return $this->specifyTypesInCondition(
				$scope,
				new Node\Expr\BinaryOp\NotEqual($expr->expr, new Node\Scalar\DNumber(0.0)),
				$context,
			)->setRootExpr($expr);
		}

		// $a == $b
		if ($expr instanceof Node\Expr\BinaryOp\Equal) {
			return $this->resolveEqual($expr, $scope, $context);
		}

		// $a != $b
		if ($expr instanceof Node\Expr\BinaryOp\NotEqual) {
			// convert to: !($b == $a)
			return $this->specifyTypesInCondition(
				$scope,
				new Node\Expr\BooleanNot(new Node\Expr\BinaryOp\Equal($expr->left, $expr->right)),
				$context,
			)->setRootExpr($expr);
		}

		// $a < $b
		// $a <= $b
		if ($expr instanceof Node\Expr\BinaryOp\Smaller || $expr instanceof Node\Expr\BinaryOp\SmallerOrEqual) {
			if (
				$expr->left instanceof Node\Expr\FuncCall
				&& count($expr->left->getArgs()) >= 1
				&& $expr->left->name instanceof Node\Name
				&& in_array(strtolower((string) $expr->left->name), ['count', 'sizeof', 'strlen', 'mb_strlen', 'preg_match'], true)
				&& (
					!$expr->right instanceof Node\Expr\FuncCall
					|| !$expr->right->name instanceof Node\Name
					|| !in_array(strtolower((string) $expr->right->name), ['count', 'sizeof', 'strlen', 'mb_strlen', 'preg_match'], true)
				)
			) {
				$inverseOperator = $expr instanceof Node\Expr\BinaryOp\Smaller
					? new Node\Expr\BinaryOp\SmallerOrEqual($expr->right, $expr->left)
					: new Node\Expr\BinaryOp\Smaller($expr->right, $expr->left);

				return $this->specifyTypesInCondition(
					$scope,
					new Node\Expr\BooleanNot($inverseOperator),
					$context,
				)->setRootExpr($expr);
			}

			$orEqual = $expr instanceof Node\Expr\BinaryOp\SmallerOrEqual;
			$offset = $orEqual ? 0 : 1;
			$leftType = $scope->getType($expr->left);
			$result = (new SpecifiedTypes([], []))->setRootExpr($expr);

			if (
				!$context->null()
				&& $expr->right instanceof Node\Expr\FuncCall
				&& count($expr->right->getArgs()) >= 1
				&& $expr->right->name instanceof Node\Name
				&& in_array(strtolower((string) $expr->right->name), ['count', 'sizeof'], true)
				&& $leftType->isInteger()->yes()
			) {
				$argType = $scope->getType($expr->right->getArgs()[0]->value);

				if ($argType instanceof UnionType) {
					$sizeType = null;
					if ($leftType instanceof ConstantIntegerType) {
						if ($orEqual) {
							$sizeType = IntegerRangeType::createAllGreaterThanOrEqualTo($leftType->getValue());
						} else {
							$sizeType = IntegerRangeType::createAllGreaterThan($leftType->getValue());
						}
					} elseif ($leftType instanceof IntegerRangeType) {
						$sizeType = $leftType;
					}

					$narrowed = $this->narrowUnionByArraySize($expr->right, $argType, $sizeType, $context, $scope, $expr);
					if ($narrowed !== null) {
						return $narrowed;
					}
				}

				if (
					$context->true() && (IntegerRangeType::createAllGreaterThanOrEqualTo(1 - $offset)->isSuperTypeOf($leftType)->yes())
					|| ($context->false() && (new ConstantIntegerType(1 - $offset))->isSuperTypeOf($leftType)->yes())
				) {
					if ($context->truthy() && $argType->isArray()->maybe()) {
						$countables = [];
						if ($argType instanceof UnionType) {
							$countableInterface = new ObjectType(Countable::class);
							foreach ($argType->getTypes() as $innerType) {
								if ($innerType->isArray()->yes()) {
									$innerType = TypeCombinator::intersect(new NonEmptyArrayType(), $innerType);
									$countables[] = $innerType;
								}

								if (!$countableInterface->isSuperTypeOf($innerType)->yes()) {
									continue;
								}

								$countables[] = $innerType;
							}
						}

						if (count($countables) > 0) {
							$countableType = TypeCombinator::union(...$countables);

							return $this->create($expr->right->getArgs()[0]->value, $countableType, $context, $scope)->setRootExpr($expr);
						}
					}

					if ($argType->isArray()->yes()) {
						$newType = new NonEmptyArrayType();
						if ($context->true() && $argType->isList()->yes()) {
							$newType = TypeCombinator::intersect($newType, new AccessoryArrayListType());
						}

						$result = $result->unionWith(
							$this->create($expr->right->getArgs()[0]->value, $newType, $context, $scope)->setRootExpr($expr),
						);
					}
				}
			}

			if (
				!$context->null()
				&& $expr->right instanceof Node\Expr\FuncCall
				&& count($expr->right->getArgs()) >= 3
				&& $expr->right->name instanceof Node\Name
				&& in_array(strtolower((string) $expr->right->name), ['preg_match'], true)
				&& IntegerRangeType::fromInterval(0, null)->isSuperTypeOf($leftType)->yes()
			) {
				return $this->specifyTypesInCondition(
					$scope,
					new Node\Expr\BinaryOp\NotIdentical($expr->right, new Node\Expr\ConstFetch(new Node\Name('false'))),
					$context,
				)->setRootExpr($expr);
			}

			if (
				!$context->null()
				&& $expr->right instanceof Node\Expr\FuncCall
				&& count($expr->right->getArgs()) === 1
				&& $expr->right->name instanceof Node\Name
				&& in_array(strtolower((string) $expr->right->name), ['strlen', 'mb_strlen'], true)
				&& $leftType->isInteger()->yes()
			) {
				if (
					$context->true() && (IntegerRangeType::createAllGreaterThanOrEqualTo(1 - $offset)->isSuperTypeOf($leftType)->yes())
					|| ($context->false() && (new ConstantIntegerType(1 - $offset))->isSuperTypeOf($leftType)->yes())
				) {
					$argType = $scope->getType($expr->right->getArgs()[0]->value);
					if ($argType->isString()->yes()) {
						$accessory = new AccessoryNonEmptyStringType();

						if (IntegerRangeType::createAllGreaterThanOrEqualTo(2 - $offset)->isSuperTypeOf($leftType)->yes()) {
							$accessory = new AccessoryNonFalsyStringType();
						}

						$result = $result->unionWith($this->create($expr->right->getArgs()[0]->value, $accessory, $context, $scope)->setRootExpr($expr));
					}
				}
			}

			if ($leftType instanceof ConstantIntegerType) {
				if ($expr->right instanceof Node\Expr\PostInc) {
					$result = $result->unionWith($this->createRangeTypes(
						$expr,
						$expr->right->var,
						IntegerRangeType::fromInterval($leftType->getValue(), null, $offset + 1),
						$context,
					));
				} elseif ($expr->right instanceof Node\Expr\PostDec) {
					$result = $result->unionWith($this->createRangeTypes(
						$expr,
						$expr->right->var,
						IntegerRangeType::fromInterval($leftType->getValue(), null, $offset - 1),
						$context,
					));
				} elseif ($expr->right instanceof Node\Expr\PreInc || $expr->right instanceof Node\Expr\PreDec) {
					$result = $result->unionWith($this->createRangeTypes(
						$expr,
						$expr->right->var,
						IntegerRangeType::fromInterval($leftType->getValue(), null, $offset),
						$context,
					));
				}
			}

			$rightType = $scope->getType($expr->right);
			if ($rightType instanceof ConstantIntegerType) {
				if ($expr->left instanceof Node\Expr\PostInc) {
					$result = $result->unionWith($this->createRangeTypes(
						$expr,
						$expr->left->var,
						IntegerRangeType::fromInterval(null, $rightType->getValue(), -$offset + 1),
						$context,
					));
				} elseif ($expr->left instanceof Node\Expr\PostDec) {
					$result = $result->unionWith($this->createRangeTypes(
						$expr,
						$expr->left->var,
						IntegerRangeType::fromInterval(null, $rightType->getValue(), -$offset - 1),
						$context,
					));
				} elseif ($expr->left instanceof Node\Expr\PreInc || $expr->left instanceof Node\Expr\PreDec) {
					$result = $result->unionWith($this->createRangeTypes(
						$expr,
						$expr->left->var,
						IntegerRangeType::fromInterval(null, $rightType->getValue(), -$offset),
						$context,
					));
				}
			}

			if ($context->true()) {
				if (!$expr->left instanceof Node\Scalar) {
					$result = $result->unionWith(
						$this->create(
							$expr->left,
							$orEqual ? $rightType->getSmallerOrEqualType($this->phpVersion) : $rightType->getSmallerType($this->phpVersion),
							TypeSpecifierContext::createTruthy(),
							$scope,
						)->setRootExpr($expr),
					);
				}
				if (!$expr->right instanceof Node\Scalar) {
					$result = $result->unionWith(
						$this->create(
							$expr->right,
							$orEqual ? $leftType->getGreaterOrEqualType($this->phpVersion) : $leftType->getGreaterType($this->phpVersion),
							TypeSpecifierContext::createTruthy(),
							$scope,
						)->setRootExpr($expr),
					);
				}
			} elseif ($context->false()) {
				if (!$expr->left instanceof Node\Scalar) {
					$result = $result->unionWith(
						$this->create(
							$expr->left,
							$orEqual ? $rightType->getGreaterType($this->phpVersion) : $rightType->getGreaterOrEqualType($this->phpVersion),
							TypeSpecifierContext::createTruthy(),
							$scope,
						)->setRootExpr($expr),
					);
				}
				if (!$expr->right instanceof Node\Scalar) {
					$result = $result->unionWith(
						$this->create(
							$expr->right,
							$orEqual ? $leftType->getSmallerType($this->phpVersion) : $leftType->getSmallerOrEqualType($this->phpVersion),
							TypeSpecifierContext::createTruthy(),
							$scope,
						)->setRootExpr($expr),
					);
				}
			}

			return $result;
		}

		// $a > $b
		if ($expr instanceof Node\Expr\BinaryOp\Greater) {
			// convert to: ($b < $a)
			return $this->specifyTypesInCondition($scope, new Node\Expr\BinaryOp\Smaller($expr->right, $expr->left), $context)->setRootExpr($expr);
		}

		// $a >= $b
		if ($expr instanceof Node\Expr\BinaryOp\GreaterOrEqual) {
			// convert to: ($b <= $a)
			return $this->specifyTypesInCondition($scope, new Node\Expr\BinaryOp\SmallerOrEqual($expr->right, $expr->left), $context)->setRootExpr($expr);
		}

		// foo()
		if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name) {
			if ($this->reflectionProvider->hasFunction($expr->name, $scope)) {
				$functionReflection = $this->reflectionProvider->getFunction($expr->name, $scope);
				foreach ($this->getFunctionTypeSpecifyingExtensions() as $extension) {
					if (!$extension->isFunctionSupported($functionReflection, $expr, $context)) {
						continue;
					}

					return $extension->specifyTypes($functionReflection, $expr, $scope, $context);
				}

				// lazy create parametersAcceptor, as creation can be expensive
				$parametersAcceptor = null;
				if (count($expr->getArgs()) > 0) {
					$parametersAcceptor = ParametersAcceptorSelector::selectFromArgs($scope, $expr->getArgs(), $functionReflection->getVariants(), $functionReflection->getNamedArgumentsVariants());

					$specifiedTypes = $this->specifyTypesFromConditionalReturnType($context, $expr, $parametersAcceptor, $scope);
					if ($specifiedTypes !== null) {
						return $specifiedTypes;
					}
				}

				$assertions = $functionReflection->getAsserts();
				if ($assertions->getAll() !== []) {
					$parametersAcceptor ??= ParametersAcceptorSelector::selectFromArgs($scope, $expr->getArgs(), $functionReflection->getVariants(), $functionReflection->getNamedArgumentsVariants());

					$asserts = $assertions->mapTypes(static fn (Type $type) => TemplateTypeHelper::resolveTemplateTypes(
						$type,
						$parametersAcceptor->getResolvedTemplateTypeMap(),
						$parametersAcceptor instanceof ExtendedParametersAcceptor ? $parametersAcceptor->getCallSiteVarianceMap() : TemplateTypeVarianceMap::createEmpty(),
						TemplateTypeVariance::createInvariant(),
					));
					$specifiedTypes = $this->specifyTypesFromAsserts($context, $expr, $asserts, $parametersAcceptor, $scope);
					if ($specifiedTypes !== null) {
						return $specifiedTypes;
					}
				}
			}

			return $this->handleDefaultTruthyOrFalseyContext($context, $expr, $scope);
		}

		// $a->foo()
		if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier) {
			$methodCalledOnType = $scope->getType($expr->var);
			$methodReflection = $scope->getMethodReflection($methodCalledOnType, $expr->name->name);
			if ($methodReflection !== null) {
				$referencedClasses = $methodCalledOnType->getObjectClassNames();
				if (
					count($referencedClasses) === 1
					&& $this->reflectionProvider->hasClass($referencedClasses[0])
				) {
					$methodClassReflection = $this->reflectionProvider->getClass($referencedClasses[0]);
					foreach ($this->getMethodTypeSpecifyingExtensionsForClass($methodClassReflection->getName()) as $extension) {
						if (!$extension->isMethodSupported($methodReflection, $expr, $context)) {
							continue;
						}

						return $extension->specifyTypes($methodReflection, $expr, $scope, $context);
					}
				}

				// lazy create parametersAcceptor, as creation can be expensive
				$parametersAcceptor = null;
				if (count($expr->getArgs()) > 0) {
					$parametersAcceptor = ParametersAcceptorSelector::selectFromArgs($scope, $expr->getArgs(), $methodReflection->getVariants(), $methodReflection->getNamedArgumentsVariants());

					$specifiedTypes = $this->specifyTypesFromConditionalReturnType($context, $expr, $parametersAcceptor, $scope);
					if ($specifiedTypes !== null) {
						return $specifiedTypes;
					}
				}

				$assertions = $methodReflection->getAsserts();
				if ($assertions->getAll() !== []) {
					$parametersAcceptor ??= ParametersAcceptorSelector::selectFromArgs($scope, $expr->getArgs(), $methodReflection->getVariants(), $methodReflection->getNamedArgumentsVariants());

					$asserts = $assertions->mapTypes(static fn (Type $type) => TemplateTypeHelper::resolveTemplateTypes(
						$type,
						$parametersAcceptor->getResolvedTemplateTypeMap(),
						$parametersAcceptor instanceof ExtendedParametersAcceptor ? $parametersAcceptor->getCallSiteVarianceMap() : TemplateTypeVarianceMap::createEmpty(),
						TemplateTypeVariance::createInvariant(),
					));
					$specifiedTypes = $this->specifyTypesFromAsserts($context, $expr, $asserts, $parametersAcceptor, $scope);
					if ($specifiedTypes !== null) {
						return $specifiedTypes;
					}
				}
			}

			return $this->handleDefaultTruthyOrFalseyContext($context, $expr, $scope);
		}

		// $a::foo()
		if ($expr instanceof Node\Expr\StaticCall && $expr->name instanceof Node\Identifier) {
			if ($expr->class instanceof Node\Name) {
				$calleeType = $scope->resolveTypeByName($expr->class);
			} else {
				$calleeType = $scope->getType($expr->class);
			}

			$staticMethodReflection = $scope->getMethodReflection($calleeType, $expr->name->name);
			if ($staticMethodReflection !== null) {
				$referencedClasses = $calleeType->getObjectClassNames();
				if (
					count($referencedClasses) === 1
					&& $this->reflectionProvider->hasClass($referencedClasses[0])
				) {
					$staticMethodClassReflection = $this->reflectionProvider->getClass($referencedClasses[0]);
					foreach ($this->getStaticMethodTypeSpecifyingExtensionsForClass($staticMethodClassReflection->getName()) as $extension) {
						if (!$extension->isStaticMethodSupported($staticMethodReflection, $expr, $context)) {
							continue;
						}

						return $extension->specifyTypes($staticMethodReflection, $expr, $scope, $context);
					}
				}

				// lazy create parametersAcceptor, as creation can be expensive
				$parametersAcceptor = null;
				if (count($expr->getArgs()) > 0) {
					$parametersAcceptor = ParametersAcceptorSelector::selectFromArgs($scope, $expr->getArgs(), $staticMethodReflection->getVariants(), $staticMethodReflection->getNamedArgumentsVariants());

					$specifiedTypes = $this->specifyTypesFromConditionalReturnType($context, $expr, $parametersAcceptor, $scope);
					if ($specifiedTypes !== null) {
						return $specifiedTypes;
					}
				}

				$assertions = $staticMethodReflection->getAsserts();
				if ($assertions->getAll() !== []) {
					$parametersAcceptor ??= ParametersAcceptorSelector::selectFromArgs($scope, $expr->getArgs(), $staticMethodReflection->getVariants(), $staticMethodReflection->getNamedArgumentsVariants());

					$asserts = $assertions->mapTypes(static fn (Type $type) => TemplateTypeHelper::resolveTemplateTypes(
						$type,
						$parametersAcceptor->getResolvedTemplateTypeMap(),
						$parametersAcceptor instanceof ExtendedParametersAcceptor ? $parametersAcceptor->getCallSiteVarianceMap() : TemplateTypeVarianceMap::createEmpty(),
						TemplateTypeVariance::createInvariant(),
					));
					$specifiedTypes = $this->specifyTypesFromAsserts($context, $expr, $asserts, $parametersAcceptor, $scope);
					if ($specifiedTypes !== null) {
						return $specifiedTypes;
					}
				}
			}

			return $this->handleDefaultTruthyOrFalseyContext($context, $expr, $scope);
		}

		// $a && $b
		// $a and $b
		if ($expr instanceof Node\Expr\BinaryOp\BooleanAnd || $expr instanceof Node\Expr\BinaryOp\LogicalAnd) {
			if (!$scope instanceof MutatingScope) {
				throw new ShouldNotHappenException();
			}
			$leftTypes = $this->specifyTypesInCondition($scope, $expr->left, $context)->setRootExpr($expr);
			$rightScope = $scope->filterByTruthyValue($expr->left);
			$rightTypes = $this->specifyTypesInCondition($rightScope, $expr->right, $context)->setRootExpr($expr);
			$types = $context->true() ? $leftTypes->unionWith($rightTypes) : $leftTypes->normalize($scope)->intersectWith($rightTypes->normalize($rightScope));
			if ($context->false()) {
				return (new SpecifiedTypes(
					$types->getSureTypes(),
					$types->getSureNotTypes(),
				))->setNewConditionalExpressionHolders(array_merge(
					$this->processBooleanNotSureConditionalTypes($scope, $leftTypes, $rightTypes),
					$this->processBooleanNotSureConditionalTypes($scope, $rightTypes, $leftTypes),
					$this->processBooleanSureConditionalTypes($scope, $leftTypes, $rightTypes),
					$this->processBooleanSureConditionalTypes($scope, $rightTypes, $leftTypes),
				))->setRootExpr($expr);
			}

			return $types;
		}

		// $a || $b
		// $a or $b
		if ($expr instanceof Node\Expr\BinaryOp\BooleanOr || $expr instanceof Node\Expr\BinaryOp\LogicalOr) {
			if (!$scope instanceof MutatingScope) {
				throw new ShouldNotHappenException();
			}
			$leftTypes = $this->specifyTypesInCondition($scope, $expr->left, $context)->setRootExpr($expr);
			$rightScope = $scope->filterByFalseyValue($expr->left);
			$rightTypes = $this->specifyTypesInCondition($rightScope, $expr->right, $context)->setRootExpr($expr);
			$types = $context->true() ? $leftTypes->normalize($scope)->intersectWith($rightTypes->normalize($rightScope)) : $leftTypes->unionWith($rightTypes);
			if ($context->true()) {
				return (new SpecifiedTypes(
					$types->getSureTypes(),
					$types->getSureNotTypes(),
				))->setNewConditionalExpressionHolders(array_merge(
					$this->processBooleanNotSureConditionalTypes($scope, $leftTypes, $rightTypes),
					$this->processBooleanNotSureConditionalTypes($scope, $rightTypes, $leftTypes),
					$this->processBooleanSureConditionalTypes($scope, $leftTypes, $rightTypes),
					$this->processBooleanSureConditionalTypes($scope, $rightTypes, $leftTypes),
				))->setRootExpr($expr);
			}

			return $types;
		}

		// !$a
		if ($expr instanceof Node\Expr\BooleanNot && !$context->null()) {
			return $this->specifyTypesInCondition($scope, $expr->expr, $context->negate())->setRootExpr($expr);
		}

		// $a = $b
		if ($expr instanceof Node\Expr\Assign) {
			if (!$scope instanceof MutatingScope) {
				throw new ShouldNotHappenException();
			}
			if ($context->null()) {
				return $this->specifyTypesInCondition($scope->exitFirstLevelStatements(), $expr->expr, $context)->setRootExpr($expr);
			}

			return $this->specifyTypesInCondition($scope->exitFirstLevelStatements(), $expr->var, $context)->setRootExpr($expr);
		}

		// isset($a)
		if ($expr instanceof Node\Expr\Isset_ && count($expr->vars) > 0 && !$context->null()) {
			// rewrite multi param isset() to and-chained single param isset()
			if (count($expr->vars) > 1) {
				$issets = [];
				foreach ($expr->vars as $var) {
					$issets[] = new Node\Expr\Isset_([$var], $expr->getAttributes());
				}

				$first = array_shift($issets);
				$andChain = null;
				foreach ($issets as $isset) {
					if ($andChain === null) {
						$andChain = new Node\Expr\BinaryOp\BooleanAnd($first, $isset);
						continue;
					}

					$andChain = new Node\Expr\BinaryOp\BooleanAnd($andChain, $isset);
				}

				if ($andChain === null) {
					throw new ShouldNotHappenException();
				}

				return $this->specifyTypesInCondition($scope, $andChain, $context)->setRootExpr($expr);
			}

			$issetExpr = $expr->vars[0];

			if (!$context->true()) {
				if (!$scope instanceof MutatingScope) {
					throw new ShouldNotHappenException();
				}

				$isset = $scope->issetCheck($issetExpr, static fn () => true);

				if ($isset === false) {
					return new SpecifiedTypes();
				}

				$type = $scope->getType($issetExpr);
				$isNullable = !$type->isNull()->no();
				$exprType = $this->create(
					$issetExpr,
					new NullType(),
					$context->negate(),
					$scope,
				)->setRootExpr($expr);

				if ($issetExpr instanceof Node\Expr\Variable && is_string($issetExpr->name)) {
					if ($isset === true) {
						if ($isNullable) {
							return $exprType;
						}

						// variable cannot exist in !isset()
						return $exprType->unionWith($this->create(
							new IssetExpr($issetExpr),
							new NullType(),
							$context,
							$scope,
						))->setRootExpr($expr);
					}

					if ($isNullable) {
						// reduces variable certainty to maybe
						return $exprType->unionWith($this->create(
							new IssetExpr($issetExpr),
							new NullType(),
							$context->negate(),
							$scope,
						))->setRootExpr($expr);
					}

					// variable cannot exist in !isset()
					return $this->create(
						new IssetExpr($issetExpr),
						new NullType(),
						$context,
						$scope,
					)->setRootExpr($expr);
				}

				if ($isNullable && $isset === true) {
					return $exprType;
				}

				return new SpecifiedTypes();
			}

			$tmpVars = [$issetExpr];
			while (
				$issetExpr instanceof Node\Expr\ArrayDimFetch
				|| $issetExpr instanceof Node\Expr\PropertyFetch
				|| (
					$issetExpr instanceof Node\Expr\StaticPropertyFetch
					&& $issetExpr->class instanceof Node\Expr
				)
			) {
				if ($issetExpr instanceof Node\Expr\StaticPropertyFetch) {
					/** @var Expr $issetExpr */
					$issetExpr = $issetExpr->class;
				} else {
					$issetExpr = $issetExpr->var;
				}
				$tmpVars[] = $issetExpr;
			}
			$vars = array_reverse($tmpVars);

			$types = new SpecifiedTypes();
			foreach ($vars as $var) {

				if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
					if ($scope->hasVariableType($var->name)->no()) {
						return (new SpecifiedTypes([], []))->setRootExpr($expr);
					}
				}

				if (
					$var instanceof Node\Expr\ArrayDimFetch
					&& $var->dim !== null
					&& !$scope->getType($var->var) instanceof MixedType
				) {
					$dimType = $scope->getType($var->dim);

					if ($dimType instanceof ConstantIntegerType || $dimType instanceof ConstantStringType) {
						$types = $types->unionWith(
							$this->create(
								$var->var,
								new HasOffsetType($dimType),
								$context,
								$scope,
							)->setRootExpr($expr),
						);
					} else {
						$varType = $scope->getType($var->var);
						$narrowedKey = AllowedArrayKeysTypes::narrowOffsetKeyType($varType, $dimType);
						if ($narrowedKey !== null) {
							$types = $types->unionWith(
								$this->create(
									$var->dim,
									$narrowedKey,
									$context,
									$scope,
								)->setRootExpr($expr),
							);
						}
					}
				}

				if (
					$var instanceof Node\Expr\PropertyFetch
					&& $var->name instanceof Node\Identifier
				) {
					$types = $types->unionWith(
						$this->create($var->var, new IntersectionType([
							new ObjectWithoutClassType(),
							new HasPropertyType($var->name->toString()),
						]), TypeSpecifierContext::createTruthy(), $scope)->setRootExpr($expr),
					);
				} elseif (
					$var instanceof Node\Expr\StaticPropertyFetch
					&& $var->class instanceof Node\Expr
					&& $var->name instanceof Node\VarLikeIdentifier
				) {
					$types = $types->unionWith(
						$this->create($var->class, new IntersectionType([
							new ObjectWithoutClassType(),
							new HasPropertyType($var->name->toString()),
						]), TypeSpecifierContext::createTruthy(), $scope)->setRootExpr($expr),
					);
				}

				$types = $types->unionWith(
					$this->create($var, new NullType(), TypeSpecifierContext::createFalse(), $scope)->setRootExpr($expr),
				);
			}

			return $types;
		}

		// $a ?? $b
		if ($expr instanceof Node\Expr\BinaryOp\Coalesce && !$context->null()) {
			if (!$context->true()) {
				if (!$scope instanceof MutatingScope) {
					throw new ShouldNotHappenException();
				}

				$isset = $scope->issetCheck($expr->left, static fn () => true);

				if ($isset !== true) {
					return new SpecifiedTypes();
				}

				return $this->create(
					$expr->left,
					new NullType(),
					$context->negate(),
					$scope,
				)->setRootExpr($expr);
			}

			if ((new ConstantBooleanType(false))->isSuperTypeOf($scope->getType($expr->right)->toBoolean())->yes()) {
				return $this->create(
					$expr->left,
					new NullType(),
					TypeSpecifierContext::createFalse(),
					$scope,
				)->setRootExpr($expr);
			}

			return (new SpecifiedTypes([], []))->setRootExpr($expr);
		}

		// empty($a)
		if ($expr instanceof Node\Expr\Empty_) {
			if (!$scope instanceof MutatingScope) {
				throw new ShouldNotHappenException();
			}

			$isset = $scope->issetCheck($expr->expr, static fn () => true);
			if ($isset === false) {
				return new SpecifiedTypes();
			}

			return $this->specifyTypesInCondition($scope, new Node\Expr\BinaryOp\BooleanOr(
				new Node\Expr\BooleanNot(new Node\Expr\Isset_([$expr->expr])),
				new Node\Expr\BooleanNot($expr->expr),
			), $context)->setRootExpr($expr);
		}

		// @$a
		if ($expr instanceof Node\Expr\ErrorSuppress) {
			return $this->specifyTypesInCondition($scope, $expr->expr, $context)->setRootExpr($expr);
		}

		// $a ? $b : $c
		if ($expr instanceof Node\Expr\Ternary && !$context->null() && $scope->getType($expr->else)->isFalse()->yes()) {
			$conditionExpr = $expr->cond;
			if ($expr->if !== null) {
				$conditionExpr = new Node\Expr\BinaryOp\BooleanAnd($conditionExpr, $expr->if);
			}

			return $this->specifyTypesInCondition($scope, $conditionExpr, $context)->setRootExpr($expr);
		}

		// $a?->foo
		if ($expr instanceof Node\Expr\NullsafePropertyFetch && !$context->null()) {
			// convert to: ($a !== null && $a->foo)
			$types = $this->specifyTypesInCondition(
				$scope,
				new Node\Expr\BinaryOp\BooleanAnd(
					new Node\Expr\BinaryOp\NotIdentical($expr->var, new Node\Expr\ConstFetch(new Node\Name('null'))),
					new Node\Expr\PropertyFetch($expr->var, $expr->name),
				),
				$context,
			)->setRootExpr($expr);

			$nullSafeTypes = $this->handleDefaultTruthyOrFalseyContext($context, $expr, $scope);
			return $context->true() ? $types->unionWith($nullSafeTypes) : $types->normalize($scope)->intersectWith($nullSafeTypes->normalize($scope));
		}

		// $a?->foo()
		if ($expr instanceof Node\Expr\NullsafeMethodCall && !$context->null()) {
			// convert to: ($a !== null && $a->foo())
			$types = $this->specifyTypesInCondition(
				$scope,
				new Node\Expr\BinaryOp\BooleanAnd(
					new Node\Expr\BinaryOp\NotIdentical($expr->var, new Node\Expr\ConstFetch(new Node\Name('null'))),
					new Node\Expr\MethodCall($expr->var, $expr->name, $expr->args),
				),
				$context,
			)->setRootExpr($expr);

			$nullSafeTypes = $this->handleDefaultTruthyOrFalseyContext($context, $expr, $scope);
			return $context->true() ? $types->unionWith($nullSafeTypes) : $types->normalize($scope)->intersectWith($nullSafeTypes->normalize($scope));
		}

		// new Foo()
		if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name && $this->reflectionProvider->hasClass($expr->class->toString())) {
			$classReflection = $this->reflectionProvider->getClass($expr->class->toString());

			if ($classReflection->hasConstructor()) {
				$methodReflection = $classReflection->getConstructor();
				$asserts = $methodReflection->getAsserts();

				if ($asserts->getAll() !== []) {
					$parametersAcceptor = ParametersAcceptorSelector::selectFromArgs($scope, $expr->getArgs(), $methodReflection->getVariants(), $methodReflection->getNamedArgumentsVariants());

					$asserts = $asserts->mapTypes(static fn (Type $type) => TemplateTypeHelper::resolveTemplateTypes(
						$type,
						$parametersAcceptor->getResolvedTemplateTypeMap(),
						$parametersAcceptor instanceof ExtendedParametersAcceptor ? $parametersAcceptor->getCallSiteVarianceMap() : TemplateTypeVarianceMap::createEmpty(),
						TemplateTypeVariance::createInvariant(),
					));

					$specifiedTypes = $this->specifyTypesFromAsserts($context, $expr, $asserts, $parametersAcceptor, $scope);

					if ($specifiedTypes !== null) {
						return $specifiedTypes;
					}
				}
			}

			return (new SpecifiedTypes([], []))->setRootExpr($expr);
		}

		if (!$context->null()) {
			return $this->handleDefaultTruthyOrFalseyContext($context, $expr, $scope);
		}

		return (new SpecifiedTypes([], []))->setRootExpr($expr);
	}

	private function narrowUnionByArraySize(Node\Expr\FuncCall $countFuncCall, UnionType $argType, ?Type $sizeType, TypeSpecifierContext $context, Scope $scope, ?Node\Expr $rootExpr): ?SpecifiedTypes
	{
		if ($sizeType === null) {
			return null;
		}

		if (count($countFuncCall->getArgs()) === 1) {
			$isNormalCount = TrinaryLogic::createYes();
		} else {
			$mode = $scope->getType($countFuncCall->getArgs()[1]->value);
			$isNormalCount = (new ConstantIntegerType(COUNT_NORMAL))->isSuperTypeOf($mode)->result->or($argType->getIterableValueType()->isArray()->negate());
		}

		if (
			$isNormalCount->yes()
			&& $argType->isConstantArray()->yes()
		) {
			$result = [];
			foreach ($argType->getTypes() as $innerType) {
				$arraySize = $innerType->getArraySize();
				$isSize = $sizeType->isSuperTypeOf($arraySize);
				if ($context->truthy()) {
					if ($isSize->no()) {
						continue;
					}

					$constArray = $this->turnListIntoConstantArray($countFuncCall, $innerType, $sizeType, $scope);
					if ($constArray !== null) {
						$innerType = $constArray;
					}
				}
				if ($context->falsey()) {
					if (!$isSize->yes()) {
						continue;
					}
				}

				$result[] = $innerType;
			}

			return $this->create($countFuncCall->getArgs()[0]->value, TypeCombinator::union(...$result), $context, $scope)->setRootExpr($rootExpr);
		}

		return null;
	}

	private function turnListIntoConstantArray(Node\Expr\FuncCall $countFuncCall, Type $type, Type $sizeType, Scope $scope): ?Type
	{
		$argType = $scope->getType($countFuncCall->getArgs()[0]->value);

		if (count($countFuncCall->getArgs()) === 1) {
			$isNormalCount = TrinaryLogic::createYes();
		} else {
			$mode = $scope->getType($countFuncCall->getArgs()[1]->value);
			$isNormalCount = (new ConstantIntegerType(COUNT_NORMAL))->isSuperTypeOf($mode)->result->or($argType->getIterableValueType()->isArray()->negate());
		}

		if (
			$isNormalCount->yes()
			&& $type->isList()->yes()
			&& $sizeType instanceof ConstantIntegerType
			&& $sizeType->getValue() < ConstantArrayTypeBuilder::ARRAY_COUNT_LIMIT
		) {
			// turn optional offsets non-optional
			$valueTypesBuilder = ConstantArrayTypeBuilder::createEmpty();
			for ($i = 0; $i < $sizeType->getValue(); $i++) {
				$offsetType = new ConstantIntegerType($i);
				$valueTypesBuilder->setOffsetValueType($offsetType, $type->getOffsetValueType($offsetType));
			}
			return $valueTypesBuilder->getArray();
		}

		if (
			$isNormalCount->yes()
			&& $type->isList()->yes()
			&& $sizeType instanceof IntegerRangeType
			&& $sizeType->getMin() !== null
		) {
			// turn optional offsets non-optional
			$valueTypesBuilder = ConstantArrayTypeBuilder::createEmpty();
			for ($i = 0; $i < $sizeType->getMin(); $i++) {
				$offsetType = new ConstantIntegerType($i);
				$valueTypesBuilder->setOffsetValueType($offsetType, $type->getOffsetValueType($offsetType));
			}
			if ($sizeType->getMax() !== null) {
				for ($i = $sizeType->getMin(); $i < $sizeType->getMax(); $i++) {
					$offsetType = new ConstantIntegerType($i);
					$valueTypesBuilder->setOffsetValueType($offsetType, $type->getOffsetValueType($offsetType), true);
				}
			} elseif ($type->isConstantArray()->yes()) {
				for ($i = $sizeType->getMin();; $i++) {
					$offsetType = new ConstantIntegerType($i);
					$hasOffset = $type->hasOffsetValueType($offsetType);
					if ($hasOffset->no()) {
						break;
					}
					$valueTypesBuilder->setOffsetValueType($offsetType, $type->getOffsetValueType($offsetType), !$hasOffset->yes());
				}
			} else {
				return null;
			}

			$arrayType = $valueTypesBuilder->getArray();
			if ($arrayType->isIterableAtLeastOnce()->yes()) {
				return $arrayType;
			}
		}

		return null;
	}

	private function specifyTypesForConstantBinaryExpression(
		Node\Expr $exprNode,
		Type $constantType,
		TypeSpecifierContext $context,
		Scope $scope,
		Node\Expr $rootExpr,
	): ?SpecifiedTypes
	{
		if (!$context->null() && $constantType->isFalse()->yes()) {
			$types = $this->create($exprNode, $constantType, $context, $scope)->setRootExpr($rootExpr);
			if ($exprNode instanceof Node\Expr\NullsafeMethodCall || $exprNode instanceof Node\Expr\NullsafePropertyFetch) {
				return $types;
			}

			return $types->unionWith($this->specifyTypesInCondition(
				$scope,
				$exprNode,
				$context->true() ? TypeSpecifierContext::createFalse() : TypeSpecifierContext::createFalse()->negate(),
			)->setRootExpr($rootExpr));
		}

		if (!$context->null() && $constantType->isTrue()->yes()) {
			$types = $this->create($exprNode, $constantType, $context, $scope)->setRootExpr($rootExpr);
			if ($exprNode instanceof Node\Expr\NullsafeMethodCall || $exprNode instanceof Node\Expr\NullsafePropertyFetch) {
				return $types;
			}

			return $types->unionWith($this->specifyTypesInCondition(
				$scope,
				$exprNode,
				$context->true() ? TypeSpecifierContext::createTrue() : TypeSpecifierContext::createTrue()->negate(),
			)->setRootExpr($rootExpr));
		}

		return null;
	}

	private function specifyTypesForConstantStringBinaryExpression(
		Node\Expr $exprNode,
		Type $constantType,
		TypeSpecifierContext $context,
		Scope $scope,
		Node\Expr $rootExpr,
	): ?SpecifiedTypes
	{
		$scalarValues = $constantType->getConstantScalarValues();
		if (count($scalarValues) !== 1 || !is_string($scalarValues[0])) {
			return null;
		}
		$constantStringValue = $scalarValues[0];

		if (
			$exprNode instanceof Node\Expr\FuncCall
			&& $exprNode->name instanceof Node\Name
			&& strtolower($exprNode->name->toString()) === 'gettype'
			&& isset($exprNode->getArgs()[0])
		) {
			$type = null;
			if ($constantStringValue === 'string') {
				$type = new StringType();
			}
			if ($constantStringValue === 'array') {
				$type = new ArrayType(new MixedType(), new MixedType());
			}
			if ($constantStringValue === 'boolean') {
				$type = new BooleanType();
			}
			if (in_array($constantStringValue, ['resource', 'resource (closed)'], true)) {
				$type = new ResourceType();
			}
			if ($constantStringValue === 'integer') {
				$type = new IntegerType();
			}
			if ($constantStringValue === 'double') {
				$type = new FloatType();
			}
			if ($constantStringValue === 'NULL') {
				$type = new NullType();
			}
			if ($constantStringValue === 'object') {
				$type = new ObjectWithoutClassType();
			}

			if ($type !== null) {
				$callType = $this->create($exprNode, $constantType, $context, $scope)->setRootExpr($rootExpr);
				$argType = $this->create($exprNode->getArgs()[0]->value, $type, $context, $scope)->setRootExpr($rootExpr);
				return $callType->unionWith($argType);
			}
		}

		if (
			$context->true()
			&& $exprNode instanceof Node\Expr\FuncCall
			&& $exprNode->name instanceof Node\Name
			&& strtolower((string) $exprNode->name) === 'get_parent_class'
			&& isset($exprNode->getArgs()[0])
		) {
			$argType = $scope->getType($exprNode->getArgs()[0]->value);
			$objectType = new ObjectType($constantStringValue);
			$classStringType = new GenericClassStringType($objectType);

			if ($argType->isString()->yes()) {
				return $this->create(
					$exprNode->getArgs()[0]->value,
					$classStringType,
					$context,
					$scope,
				)->setRootExpr($rootExpr);
			}

			if ($argType->isObject()->yes()) {
				return $this->create(
					$exprNode->getArgs()[0]->value,
					$objectType,
					$context,
					$scope,
				)->setRootExpr($rootExpr);
			}

			return $this->create(
				$exprNode->getArgs()[0]->value,
				TypeCombinator::union($objectType, $classStringType),
				$context,
				$scope,
			)->setRootExpr($rootExpr);
		}

		return null;
	}

	private function handleDefaultTruthyOrFalseyContext(TypeSpecifierContext $context, Node\Expr $expr, Scope $scope): SpecifiedTypes
	{
		if ($context->null()) {
			return (new SpecifiedTypes([], []))->setRootExpr($expr);
		}
		if (!$context->truthy()) {
			$type = StaticTypeFactory::truthy();
			return $this->create($expr, $type, TypeSpecifierContext::createFalse(), $scope)->setRootExpr($expr);
		} elseif (!$context->falsey()) {
			$type = StaticTypeFactory::falsey();
			return $this->create($expr, $type, TypeSpecifierContext::createFalse(), $scope)->setRootExpr($expr);
		}

		return (new SpecifiedTypes([], []))->setRootExpr($expr);
	}

	private function specifyTypesFromConditionalReturnType(
		TypeSpecifierContext $context,
		Node\Expr\CallLike $call,
		ParametersAcceptor $parametersAcceptor,
		Scope $scope,
	): ?SpecifiedTypes
	{
		if (!$parametersAcceptor instanceof ResolvedFunctionVariant) {
			return null;
		}

		$returnType = $parametersAcceptor->getOriginalParametersAcceptor()->getReturnType();
		if (!$returnType instanceof ConditionalTypeForParameter) {
			return null;
		}

		if ($context->true()) {
			$leftType = new ConstantBooleanType(true);
			$rightType = new ConstantBooleanType(false);
		} elseif ($context->false()) {
			$leftType = new ConstantBooleanType(false);
			$rightType = new ConstantBooleanType(true);
		} elseif ($context->null()) {
			$leftType = new MixedType();
			$rightType = new NeverType();
		} else {
			return null;
		}

		$argsMap = [];
		$parameters = $parametersAcceptor->getParameters();
		foreach ($call->getArgs() as $i => $arg) {
			if ($arg->unpack) {
				continue;
			}

			if ($arg->name !== null) {
				$paramName = $arg->name->toString();
			} elseif (isset($parameters[$i])) {
				$paramName = $parameters[$i]->getName();
			} else {
				continue;
			}

			$argsMap['$' . $paramName] = $arg->value;
		}

		return $this->getConditionalSpecifiedTypes($returnType, $leftType, $rightType, $scope, $argsMap);
	}

	/**
	 * @param array<string, Node\Expr> $argsMap
	 */
	public function getConditionalSpecifiedTypes(
		ConditionalTypeForParameter $conditionalType,
		Type $leftType,
		Type $rightType,
		Scope $scope,
		array $argsMap,
	): ?SpecifiedTypes
	{
		$parameterName = $conditionalType->getParameterName();
		if (!array_key_exists($parameterName, $argsMap)) {
			return null;
		}

		$targetType = $conditionalType->getTarget();
		$ifType = $conditionalType->getIf();
		$elseType = $conditionalType->getElse();

		if ($leftType->isSuperTypeOf($ifType)->yes() && $rightType->isSuperTypeOf($elseType)->yes()) {
			$context = $conditionalType->isNegated() ? TypeSpecifierContext::createFalse() : TypeSpecifierContext::createTrue();
		} elseif ($leftType->isSuperTypeOf($elseType)->yes() && $rightType->isSuperTypeOf($ifType)->yes()) {
			$context = $conditionalType->isNegated() ? TypeSpecifierContext::createTrue() : TypeSpecifierContext::createFalse();
		} else {
			return null;
		}

		$specifiedTypes = $this->create(
			$argsMap[$parameterName],
			$targetType,
			$context,
			$scope,
		);

		if ($targetType instanceof ConstantBooleanType) {
			if (!$targetType->getValue()) {
				$context = $context->negate();
			}

			$specifiedTypes = $specifiedTypes->unionWith($this->specifyTypesInCondition($scope, $argsMap[$parameterName], $context));
		}

		return $specifiedTypes;
	}

	private function specifyTypesFromAsserts(TypeSpecifierContext $context, Node\Expr\CallLike $call, Assertions $assertions, ParametersAcceptor $parametersAcceptor, Scope $scope): ?SpecifiedTypes
	{
		if ($context->null()) {
			$asserts = $assertions->getAsserts();
		} elseif ($context->true()) {
			$asserts = $assertions->getAssertsIfTrue();
		} elseif ($context->false()) {
			$asserts = $assertions->getAssertsIfFalse();
		} else {
			throw new ShouldNotHappenException();
		}

		if (count($asserts) === 0) {
			return null;
		}

		$argsMap = [];
		$parameters = $parametersAcceptor->getParameters();
		foreach ($call->getArgs() as $i => $arg) {
			if ($arg->unpack) {
				continue;
			}

			if ($arg->name !== null) {
				$paramName = $arg->name->toString();
			} elseif (isset($parameters[$i])) {
				$paramName = $parameters[$i]->getName();
			} elseif (count($parameters) > 0 && $parametersAcceptor->isVariadic()) {
				$lastParameter = $parameters[count($parameters) - 1];
				$paramName = $lastParameter->getName();
			} else {
				continue;
			}

			$argsMap[$paramName][] = $arg->value;
		}

		if ($call instanceof Node\Expr\MethodCall) {
			$argsMap['this'] = [$call->var];
		}

		/** @var SpecifiedTypes|null $types */
		$types = null;

		foreach ($asserts as $assert) {
			foreach ($argsMap[substr($assert->getParameter()->getParameterName(), 1)] ?? [] as $parameterExpr) {
				$assertedType = TypeTraverser::map($assert->getType(), static function (Type $type, callable $traverse) use ($argsMap, $scope): Type {
					if ($type instanceof ConditionalTypeForParameter) {
						$parameterName = substr($type->getParameterName(), 1);
						if (array_key_exists($parameterName, $argsMap)) {
							$argType = TypeCombinator::union(...array_map(static fn (Node\Expr $expr) => $scope->getType($expr), $argsMap[$parameterName]));
							$type = $type->toConditional($argType);
						}
					}

					return $traverse($type);
				});

				$assertExpr = $assert->getParameter()->getExpr($parameterExpr);

				$templateTypeMap = $parametersAcceptor->getResolvedTemplateTypeMap();
				$containsUnresolvedTemplate = false;
				TypeTraverser::map(
					$assert->getOriginalType(),
					static function (Type $type, callable $traverse) use ($templateTypeMap, &$containsUnresolvedTemplate) {
						if ($type instanceof TemplateType && $type->getScope()->getClassName() !== null) {
							$resolvedType = $templateTypeMap->getType($type->getName());
							if ($resolvedType === null || $type->getBound()->equals($resolvedType)) {
								$containsUnresolvedTemplate = true;
								return $type;
							}
						}

						return $traverse($type);
					},
				);

				$newTypes = $this->create(
					$assertExpr,
					$assertedType,
					$assert->isNegated() ? TypeSpecifierContext::createFalse() : TypeSpecifierContext::createTrue(),
					$scope,
				)->setRootExpr($containsUnresolvedTemplate || $assert->isEquality() ? $call : null);
				$types = $types !== null ? $types->unionWith($newTypes) : $newTypes;

				if (!$context->null() || !$assertedType instanceof ConstantBooleanType) {
					continue;
				}

				$subContext = $assertedType->getValue() ? TypeSpecifierContext::createTrue() : TypeSpecifierContext::createFalse();
				if ($assert->isNegated()) {
					$subContext = $subContext->negate();
				}

				$types = $types->unionWith($this->specifyTypesInCondition(
					$scope,
					$assertExpr,
					$subContext,
				));
			}
		}

		return $types;
	}

	/**
	 * @return array<string, ConditionalExpressionHolder[]>
	 */
	private function processBooleanSureConditionalTypes(Scope $scope, SpecifiedTypes $leftTypes, SpecifiedTypes $rightTypes): array
	{
		$conditionExpressionTypes = [];
		foreach ($leftTypes->getSureTypes() as $exprString => [$expr, $type]) {
			if (!$expr instanceof Node\Expr\Variable) {
				continue;
			}
			if (!is_string($expr->name)) {
				continue;
			}

			$conditionExpressionTypes[$exprString] = ExpressionTypeHolder::createYes(
				$expr,
				TypeCombinator::remove($scope->getType($expr), $type),
			);
		}

		if (count($conditionExpressionTypes) > 0) {
			$holders = [];
			foreach ($rightTypes->getSureTypes() as $exprString => [$expr, $type]) {
				if (!$expr instanceof Node\Expr\Variable) {
					continue;
				}
				if (!is_string($expr->name)) {
					continue;
				}

				if (!isset($holders[$exprString])) {
					$holders[$exprString] = [];
				}

				$conditions = $conditionExpressionTypes;
				foreach ($conditions as $conditionExprString => $conditionExprTypeHolder) {
					$conditionExpr = $conditionExprTypeHolder->getExpr();
					if (!$conditionExpr instanceof Node\Expr\Variable) {
						continue;
					}
					if (!is_string($conditionExpr->name)) {
						continue;
					}
					if ($conditionExpr->name !== $expr->name) {
						continue;
					}

					unset($conditions[$conditionExprString]);
				}

				if (count($conditions) === 0) {
					continue;
				}

				$holder = new ConditionalExpressionHolder(
					$conditions,
					new ExpressionTypeHolder($expr, TypeCombinator::intersect($scope->getType($expr), $type), TrinaryLogic::createYes()),
				);
				$holders[$exprString][$holder->getKey()] = $holder;
			}

			return $holders;
		}

		return [];
	}

	/**
	 * @return array<string, ConditionalExpressionHolder[]>
	 */
	private function processBooleanNotSureConditionalTypes(Scope $scope, SpecifiedTypes $leftTypes, SpecifiedTypes $rightTypes): array
	{
		$conditionExpressionTypes = [];
		foreach ($leftTypes->getSureNotTypes() as $exprString => [$expr, $type]) {
			if (!$expr instanceof Node\Expr\Variable) {
				continue;
			}
			if (!is_string($expr->name)) {
				continue;
			}

			$conditionExpressionTypes[$exprString] = ExpressionTypeHolder::createYes(
				$expr,
				TypeCombinator::intersect($scope->getType($expr), $type),
			);
		}

		if (count($conditionExpressionTypes) > 0) {
			$holders = [];
			foreach ($rightTypes->getSureNotTypes() as $exprString => [$expr, $type]) {
				if (!$expr instanceof Node\Expr\Variable) {
					continue;
				}
				if (!is_string($expr->name)) {
					continue;
				}

				if (!isset($holders[$exprString])) {
					$holders[$exprString] = [];
				}

				$conditions = $conditionExpressionTypes;
				foreach ($conditions as $conditionExprString => $conditionExprTypeHolder) {
					$conditionExpr = $conditionExprTypeHolder->getExpr();
					if (!$conditionExpr instanceof Node\Expr\Variable) {
						continue;
					}
					if (!is_string($conditionExpr->name)) {
						continue;
					}
					if ($conditionExpr->name !== $expr->name) {
						continue;
					}

					unset($conditions[$conditionExprString]);
				}

				if (count($conditions) === 0) {
					continue;
				}

				$holder = new ConditionalExpressionHolder(
					$conditions,
					new ExpressionTypeHolder($expr, TypeCombinator::remove($scope->getType($expr), $type), TrinaryLogic::createYes()),
				);
				$holders[$exprString][$holder->getKey()] = $holder;
			}

			return $holders;
		}

		return [];
	}

	/**
	 * @return array{Node\Expr, ConstantScalarType, Type}|null
	 */
	private function findTypeExpressionsFromBinaryOperation(Scope $scope, Node\Expr\BinaryOp $binaryOperation): ?array
	{
		$leftType = $scope->getType($binaryOperation->left);
		$rightType = $scope->getType($binaryOperation->right);

		$rightExpr = $binaryOperation->right;
		if ($rightExpr instanceof AlwaysRememberedExpr) {
			$rightExpr = $rightExpr->getExpr();
		}

		$leftExpr = $binaryOperation->left;
		if ($leftExpr instanceof AlwaysRememberedExpr) {
			$leftExpr = $leftExpr->getExpr();
		}

		if (
			$leftType instanceof ConstantScalarType
			&& !$rightExpr instanceof Node\Expr\ConstFetch
			&& !$rightExpr instanceof Node\Expr\ClassConstFetch
		) {
			return [$binaryOperation->right, $leftType, $rightType];
		} elseif (
			$rightType instanceof ConstantScalarType
			&& !$leftExpr instanceof Node\Expr\ConstFetch
			&& !$leftExpr instanceof Node\Expr\ClassConstFetch
		) {
			return [$binaryOperation->left, $rightType, $leftType];
		}

		return null;
	}

	/** @api */
	public function create(
		Node\Expr $expr,
		Type $type,
		TypeSpecifierContext $context,
		Scope $scope,
	): SpecifiedTypes
	{
		if ($expr instanceof Node\Expr\Instanceof_ || $expr instanceof Node\Expr\List_) {
			return (new SpecifiedTypes([], []))->setRootExpr($expr);
		}

		$specifiedExprs = [];
		if ($expr instanceof AlwaysRememberedExpr) {
			$specifiedExprs[] = $expr;
			$expr = $expr->expr;
		}

		if ($expr instanceof Node\Expr\Assign) {
			$specifiedExprs[] = $expr->var;
			$specifiedExprs[] = $expr->expr;

			while ($expr->expr instanceof Node\Expr\Assign) {
				$specifiedExprs[] = $expr->expr->var;
				$expr = $expr->expr;
			}
		} elseif ($expr instanceof Node\Expr\AssignOp\Coalesce) {
			$specifiedExprs[] = $expr->var;
		} else {
			$specifiedExprs[] = $expr;
		}

		$types = null;

		foreach ($specifiedExprs as $specifiedExpr) {
			$newTypes = $this->createForExpr($specifiedExpr, $type, $context, $scope);

			if ($types === null) {
				$types = $newTypes;
			} else {
				$types = $types->unionWith($newTypes);
			}
		}

		return $types;
	}

	private function createForExpr(
		Node\Expr $expr,
		Type $type,
		TypeSpecifierContext $context,
		Scope $scope,
	): SpecifiedTypes
	{
		if ($context->true()) {
			$containsNull = !$type->isNull()->no() && !$scope->getType($expr)->isNull()->no();
		} elseif ($context->false()) {
			$containsNull = !TypeCombinator::containsNull($type) && !$scope->getType($expr)->isNull()->no();
		}

		$originalExpr = $expr;
		if (isset($containsNull) && !$containsNull) {
			$expr = NullsafeOperatorHelper::getNullsafeShortcircuitedExpr($expr);
		}

		if (
			!$context->null()
			&& $expr instanceof Node\Expr\BinaryOp\Coalesce
		) {
			$rightIsSuperType = $type->isSuperTypeOf($scope->getType($expr->right));
			if (($context->true() && $rightIsSuperType->no()) || ($context->false() && $rightIsSuperType->yes())) {
				$expr = $expr->left;
			}
		}

		if (
			$expr instanceof Node\Expr\FuncCall
			&& $expr->name instanceof Node\Name
		) {
			$has = $this->reflectionProvider->hasFunction($expr->name, $scope);
			if (!$has) {
				// backwards compatibility with previous behaviour
				return new SpecifiedTypes([], []);
			}

			$functionReflection = $this->reflectionProvider->getFunction($expr->name, $scope);
			$hasSideEffects = $functionReflection->hasSideEffects();
			if ($hasSideEffects->yes()) {
				return new SpecifiedTypes([], []);
			}

			if (!$this->rememberPossiblyImpureFunctionValues && !$hasSideEffects->no()) {
				return new SpecifiedTypes([], []);
			}
		}

		if (
			$expr instanceof Node\Expr\MethodCall
			&& $expr->name instanceof Node\Identifier
		) {
			$methodName = $expr->name->toString();
			$calledOnType = $scope->getType($expr->var);
			$methodReflection = $scope->getMethodReflection($calledOnType, $methodName);
			if (
				$methodReflection === null
				|| $methodReflection->hasSideEffects()->yes()
				|| (!$this->rememberPossiblyImpureFunctionValues && !$methodReflection->hasSideEffects()->no())
			) {
				if (isset($containsNull) && !$containsNull) {
					return $this->createNullsafeTypes($originalExpr, $scope, $context, $type);
				}

				return new SpecifiedTypes([], []);
			}
		}

		if (
			$expr instanceof Node\Expr\StaticCall
			&& $expr->name instanceof Node\Identifier
		) {
			$methodName = $expr->name->toString();
			if ($expr->class instanceof Node\Name) {
				$calledOnType = $scope->resolveTypeByName($expr->class);
			} else {
				$calledOnType = $scope->getType($expr->class);
			}

			$methodReflection = $scope->getMethodReflection($calledOnType, $methodName);
			if (
				$methodReflection === null
				|| $methodReflection->hasSideEffects()->yes()
				|| (!$this->rememberPossiblyImpureFunctionValues && !$methodReflection->hasSideEffects()->no())
			) {
				if (isset($containsNull) && !$containsNull) {
					return $this->createNullsafeTypes($originalExpr, $scope, $context, $type);
				}

				return new SpecifiedTypes([], []);
			}
		}

		$sureTypes = [];
		$sureNotTypes = [];
		$exprString = $this->exprPrinter->printExpr($expr);
		$originalExprString = $this->exprPrinter->printExpr($originalExpr);
		if ($context->false()) {
			$sureNotTypes[$exprString] = [$expr, $type];
			if ($exprString !== $originalExprString) {
				$sureNotTypes[$originalExprString] = [$originalExpr, $type];
			}
		} elseif ($context->true()) {
			$sureTypes[$exprString] = [$expr, $type];
			if ($exprString !== $originalExprString) {
				$sureTypes[$originalExprString] = [$originalExpr, $type];
			}
		}

		$types = new SpecifiedTypes($sureTypes, $sureNotTypes);
		if (isset($containsNull) && !$containsNull) {
			return $this->createNullsafeTypes($originalExpr, $scope, $context, $type)->unionWith($types);
		}

		return $types;
	}

	private function createNullsafeTypes(Node\Expr $expr, Scope $scope, TypeSpecifierContext $context, ?Type $type): SpecifiedTypes
	{
		if ($expr instanceof Node\Expr\NullsafePropertyFetch) {
			if ($type !== null) {
				$propertyFetchTypes = $this->create(new Node\Expr\PropertyFetch($expr->var, $expr->name), $type, $context, $scope);
			} else {
				$propertyFetchTypes = $this->create(new Node\Expr\PropertyFetch($expr->var, $expr->name), new NullType(), TypeSpecifierContext::createFalse(), $scope);
			}

			return $propertyFetchTypes->unionWith(
				$this->create($expr->var, new NullType(), TypeSpecifierContext::createFalse(), $scope),
			);
		}

		if ($expr instanceof Node\Expr\NullsafeMethodCall) {
			if ($type !== null) {
				$methodCallTypes = $this->create(new Node\Expr\MethodCall($expr->var, $expr->name, $expr->args), $type, $context, $scope);
			} else {
				$methodCallTypes = $this->create(new Node\Expr\MethodCall($expr->var, $expr->name, $expr->args), new NullType(), TypeSpecifierContext::createFalse(), $scope);
			}

			return $methodCallTypes->unionWith(
				$this->create($expr->var, new NullType(), TypeSpecifierContext::createFalse(), $scope),
			);
		}

		if ($expr instanceof Node\Expr\PropertyFetch) {
			return $this->createNullsafeTypes($expr->var, $scope, $context, null);
		}

		if ($expr instanceof Node\Expr\MethodCall) {
			return $this->createNullsafeTypes($expr->var, $scope, $context, null);
		}

		if ($expr instanceof Node\Expr\ArrayDimFetch) {
			return $this->createNullsafeTypes($expr->var, $scope, $context, null);
		}

		if ($expr instanceof Node\Expr\StaticPropertyFetch && $expr->class instanceof Node\Expr) {
			return $this->createNullsafeTypes($expr->class, $scope, $context, null);
		}

		if ($expr instanceof Node\Expr\StaticCall && $expr->class instanceof Node\Expr) {
			return $this->createNullsafeTypes($expr->class, $scope, $context, null);
		}

		return new SpecifiedTypes([], []);
	}

	private function createRangeTypes(?Node\Expr $rootExpr, Node\Expr $expr, Type $type, TypeSpecifierContext $context): SpecifiedTypes
	{
		$sureNotTypes = [];

		if ($type instanceof IntegerRangeType || $type instanceof ConstantIntegerType) {
			$exprString = $this->exprPrinter->printExpr($expr);
			if ($context->false()) {
				$sureNotTypes[$exprString] = [$expr, $type];
			} elseif ($context->true()) {
				$inverted = TypeCombinator::remove(new IntegerType(), $type);
				$sureNotTypes[$exprString] = [$expr, $inverted];
			}
		}

		return (new SpecifiedTypes([], $sureNotTypes))->setRootExpr($rootExpr);
	}

	/**
	 * @return FunctionTypeSpecifyingExtension[]
	 */
	private function getFunctionTypeSpecifyingExtensions(): array
	{
		return $this->functionTypeSpecifyingExtensions;
	}

	/**
	 * @return MethodTypeSpecifyingExtension[]
	 */
	private function getMethodTypeSpecifyingExtensionsForClass(string $className): array
	{
		if ($this->methodTypeSpecifyingExtensionsByClass === null) {
			$byClass = [];
			foreach ($this->methodTypeSpecifyingExtensions as $extension) {
				$byClass[$extension->getClass()][] = $extension;
			}

			$this->methodTypeSpecifyingExtensionsByClass = $byClass;
		}
		return $this->getTypeSpecifyingExtensionsForType($this->methodTypeSpecifyingExtensionsByClass, $className);
	}

	/**
	 * @return StaticMethodTypeSpecifyingExtension[]
	 */
	private function getStaticMethodTypeSpecifyingExtensionsForClass(string $className): array
	{
		if ($this->staticMethodTypeSpecifyingExtensionsByClass === null) {
			$byClass = [];
			foreach ($this->staticMethodTypeSpecifyingExtensions as $extension) {
				$byClass[$extension->getClass()][] = $extension;
			}

			$this->staticMethodTypeSpecifyingExtensionsByClass = $byClass;
		}
		return $this->getTypeSpecifyingExtensionsForType($this->staticMethodTypeSpecifyingExtensionsByClass, $className);
	}

	/**
	 * @param MethodTypeSpecifyingExtension[][]|StaticMethodTypeSpecifyingExtension[][] $extensions
	 * @return mixed[]
	 */
	private function getTypeSpecifyingExtensionsForType(array $extensions, string $className): array
	{
		$extensionsForClass = [[]];
		$class = $this->reflectionProvider->getClass($className);
		foreach (array_merge([$className], $class->getParentClassesNames(), $class->getNativeReflection()->getInterfaceNames()) as $extensionClassName) {
			if (!isset($extensions[$extensionClassName])) {
				continue;
			}

			$extensionsForClass[] = $extensions[$extensionClassName];
		}

		return array_merge(...$extensionsForClass);
	}

	public function resolveEqual(Node\Expr\BinaryOp\Equal $expr, Scope $scope, TypeSpecifierContext $context): SpecifiedTypes
	{
		$expressions = $this->findTypeExpressionsFromBinaryOperation($scope, $expr);
		if ($expressions !== null) {
			$exprNode = $expressions[0];
			$constantType = $expressions[1];
			$otherType = $expressions[2];

			if (!$context->null() && $constantType->getValue() === null) {
				$trueTypes = [
					new NullType(),
					new ConstantBooleanType(false),
					new ConstantIntegerType(0),
					new ConstantFloatType(0.0),
					new ConstantStringType(''),
					new ConstantArrayType([], []),
				];
				return $this->create($exprNode, new UnionType($trueTypes), $context, $scope)->setRootExpr($expr);
			}

			if (!$context->null() && $constantType->getValue() === false) {
				return $this->specifyTypesInCondition(
					$scope,
					$exprNode,
					$context->true() ? TypeSpecifierContext::createFalsey() : TypeSpecifierContext::createFalsey()->negate(),
				)->setRootExpr($expr);
			}

			if (!$context->null() && $constantType->getValue() === true) {
				return $this->specifyTypesInCondition(
					$scope,
					$exprNode,
					$context->true() ? TypeSpecifierContext::createTruthy() : TypeSpecifierContext::createTruthy()->negate(),
				)->setRootExpr($expr);
			}

			if (!$context->null() && $constantType->getValue() === 0 && !$otherType->isInteger()->yes() && !$otherType->isBoolean()->yes()) {
				/* There is a difference between php 7.x and 8.x on the equality
				 * behavior between zero and the empty string, so to be conservative
				 * we leave it untouched regardless of the language version */
				if ($context->true()) {
					$trueTypes = [
						new NullType(),
						new ConstantBooleanType(false),
						new ConstantIntegerType(0),
						new ConstantFloatType(0.0),
						new StringType(),
					];
				} else {
					$trueTypes = [
						new NullType(),
						new ConstantBooleanType(false),
						new ConstantIntegerType(0),
						new ConstantFloatType(0.0),
						new ConstantStringType('0'),
					];
				}
				return $this->create($exprNode, new UnionType($trueTypes), $context, $scope)->setRootExpr($expr);
			}

			if (!$context->null() && $constantType->getValue() === '') {
				/* There is a difference between php 7.x and 8.x on the equality
				 * behavior between zero and the empty string, so to be conservative
				 * we leave it untouched regardless of the language version */
				if ($context->true()) {
					$trueTypes = [
						new NullType(),
						new ConstantBooleanType(false),
						new ConstantIntegerType(0),
						new ConstantFloatType(0.0),
						new ConstantStringType(''),
					];
				} else {
					$trueTypes = [
						new NullType(),
						new ConstantBooleanType(false),
						new ConstantStringType(''),
					];
				}
				return $this->create($exprNode, new UnionType($trueTypes), $context, $scope)->setRootExpr($expr);
			}

			if (
				$exprNode instanceof Node\Expr\FuncCall
				&& $exprNode->name instanceof Node\Name
				&& in_array(strtolower($exprNode->name->toString()), ['gettype', 'get_class', 'get_debug_type'], true)
				&& isset($exprNode->getArgs()[0])
				&& $constantType->isString()->yes()
			) {
				return $this->specifyTypesInCondition($scope, new Node\Expr\BinaryOp\Identical($expr->left, $expr->right), $context)->setRootExpr($expr);
			}

			if (
				$context->true()
				&& $exprNode instanceof Node\Expr\FuncCall
				&& $exprNode->name instanceof Node\Name
				&& $exprNode->name->toLowerString() === 'preg_match'
				&& (new ConstantIntegerType(1))->isSuperTypeOf($constantType)->yes()
			) {
				return $this->specifyTypesInCondition($scope, new Node\Expr\BinaryOp\Identical($expr->left, $expr->right), $context)->setRootExpr($expr);
			}
		}

		$leftType = $scope->getType($expr->left);
		$rightType = $scope->getType($expr->right);

		$leftBooleanType = $leftType->toBoolean();
		if ($leftBooleanType instanceof ConstantBooleanType && $rightType->isBoolean()->yes()) {
			return $this->specifyTypesInCondition(
				$scope,
				new Node\Expr\BinaryOp\Identical(
					new Node\Expr\ConstFetch(new Node\Name($leftBooleanType->getValue() ? 'true' : 'false')),
					$expr->right,
				),
				$context,
			)->setRootExpr($expr);
		}

		$rightBooleanType = $rightType->toBoolean();
		if ($rightBooleanType instanceof ConstantBooleanType && $leftType->isBoolean()->yes()) {
			return $this->specifyTypesInCondition(
				$scope,
				new Node\Expr\BinaryOp\Identical(
					$expr->left,
					new Node\Expr\ConstFetch(new Node\Name($rightBooleanType->getValue() ? 'true' : 'false')),
				),
				$context,
			)->setRootExpr($expr);
		}

		if (
			!$context->null()
			&& $rightType->isArray()->yes()
			&& $leftType->isConstantArray()->yes() && $leftType->isIterableAtLeastOnce()->no()
		) {
			return $this->create($expr->right, new NonEmptyArrayType(), $context->negate(), $scope)->setRootExpr($expr);
		}

		if (
			!$context->null()
			&& $leftType->isArray()->yes()
			&& $rightType->isConstantArray()->yes() && $rightType->isIterableAtLeastOnce()->no()
		) {
			return $this->create($expr->left, new NonEmptyArrayType(), $context->negate(), $scope)->setRootExpr($expr);
		}

		if (
			($leftType->isString()->yes() && $rightType->isString()->yes())
			|| ($leftType->isInteger()->yes() && $rightType->isInteger()->yes())
			|| ($leftType->isFloat()->yes() && $rightType->isFloat()->yes())
			|| ($leftType->isEnum()->yes() && $rightType->isEnum()->yes())
		) {
			return $this->specifyTypesInCondition($scope, new Node\Expr\BinaryOp\Identical($expr->left, $expr->right), $context)->setRootExpr($expr);
		}

		$leftExprString = $this->exprPrinter->printExpr($expr->left);
		$rightExprString = $this->exprPrinter->printExpr($expr->right);
		if ($leftExprString === $rightExprString) {
			if (!$expr->left instanceof Node\Expr\Variable || !$expr->right instanceof Node\Expr\Variable) {
				return (new SpecifiedTypes([], []))->setRootExpr($expr);
			}
		}

		$leftTypes = $this->create($expr->left, $leftType, $context, $scope)->setRootExpr($expr);
		$rightTypes = $this->create($expr->right, $rightType, $context, $scope)->setRootExpr($expr);

		return $context->true()
			? $leftTypes->unionWith($rightTypes)
			: $leftTypes->normalize($scope)->intersectWith($rightTypes->normalize($scope));
	}

	public function resolveIdentical(Node\Expr\BinaryOp\Identical $expr, Scope $scope, TypeSpecifierContext $context): SpecifiedTypes
	{
		// Normalize to: fn() === expr
		$leftExpr = $expr->left;
		$rightExpr = $expr->right;
		if ($rightExpr instanceof Node\Expr\FuncCall && !$leftExpr instanceof Node\Expr\FuncCall) {
			[$leftExpr, $rightExpr] = [$rightExpr, $leftExpr];
		}

		$unwrappedLeftExpr = $leftExpr;
		if ($leftExpr instanceof AlwaysRememberedExpr) {
			$unwrappedLeftExpr = $leftExpr->getExpr();
		}
		$unwrappedRightExpr = $rightExpr;
		if ($rightExpr instanceof AlwaysRememberedExpr) {
			$unwrappedRightExpr = $rightExpr->getExpr();
		}

		$rightType = $scope->getType($rightExpr);

		// (count($a) === $b)
		if (
			!$context->null()
			&& $unwrappedLeftExpr instanceof Node\Expr\FuncCall
			&& count($unwrappedLeftExpr->getArgs()) >= 1
			&& $unwrappedLeftExpr->name instanceof Node\Name
			&& in_array(strtolower((string) $unwrappedLeftExpr->name), ['count', 'sizeof'], true)
			&& $rightType->isInteger()->yes()
		) {
			if (IntegerRangeType::fromInterval(null, -1)->isSuperTypeOf($rightType)->yes()) {
				return $this->create($unwrappedLeftExpr->getArgs()[0]->value, new NeverType(), $context, $scope)->setRootExpr($expr);
			}

			$argType = $scope->getType($unwrappedLeftExpr->getArgs()[0]->value);
			$isZero = (new ConstantIntegerType(0))->isSuperTypeOf($rightType);
			if ($isZero->yes()) {
				$funcTypes = $this->create($unwrappedLeftExpr, $rightType, $context, $scope)->setRootExpr($expr);

				if ($context->truthy() && !$argType->isArray()->yes()) {
					$newArgType = new UnionType([
						new ObjectType(Countable::class),
						new ConstantArrayType([], []),
					]);
				} else {
					$newArgType = new ConstantArrayType([], []);
				}

				return $funcTypes->unionWith(
					$this->create($unwrappedLeftExpr->getArgs()[0]->value, $newArgType, $context, $scope)->setRootExpr($expr),
				);
			}

			if ($argType instanceof UnionType) {
				$narrowed = $this->narrowUnionByArraySize($unwrappedLeftExpr, $argType, $rightType, $context, $scope, $expr);
				if ($narrowed !== null) {
					return $narrowed;
				}
			}

			if ($context->truthy()) {
				if ($argType->isArray()->yes()) {
					if (
						$argType->isConstantArray()->yes()
						&& $rightType->isSuperTypeOf($argType->getArraySize())->no()
					) {
						return $this->create($unwrappedLeftExpr->getArgs()[0]->value, new NeverType(), $context, $scope)->setRootExpr($expr);
					}

					$funcTypes = $this->create($unwrappedLeftExpr, $rightType, $context, $scope)->setRootExpr($expr);
					$constArray = $this->turnListIntoConstantArray($unwrappedLeftExpr, $argType, $rightType, $scope);
					if ($constArray !== null) {
						return $funcTypes->unionWith(
							$this->create($unwrappedLeftExpr->getArgs()[0]->value, $constArray, $context, $scope)->setRootExpr($expr),
						);
					} elseif (IntegerRangeType::fromInterval(1, null)->isSuperTypeOf($rightType)->yes()) {
						return $funcTypes->unionWith(
							$this->create($unwrappedLeftExpr->getArgs()[0]->value, new NonEmptyArrayType(), $context, $scope)->setRootExpr($expr),
						);
					}

					return $funcTypes;
				}
			}
		}

		// strlen($a) === $b
		if (
			!$context->null()
			&& $unwrappedLeftExpr instanceof Node\Expr\FuncCall
			&& count($unwrappedLeftExpr->getArgs()) === 1
			&& $unwrappedLeftExpr->name instanceof Node\Name
			&& in_array(strtolower((string) $unwrappedLeftExpr->name), ['strlen', 'mb_strlen'], true)
			&& $rightType->isInteger()->yes()
		) {
			if (IntegerRangeType::fromInterval(null, -1)->isSuperTypeOf($rightType)->yes()) {
				return $this->create($unwrappedLeftExpr->getArgs()[0]->value, new NeverType(), $context, $scope)->setRootExpr($expr);
			}

			$isZero = (new ConstantIntegerType(0))->isSuperTypeOf($rightType);
			if ($isZero->yes()) {
				$funcTypes = $this->create($unwrappedLeftExpr, $rightType, $context, $scope)->setRootExpr($expr);
				return $funcTypes->unionWith(
					$this->create($unwrappedLeftExpr->getArgs()[0]->value, new ConstantStringType(''), $context, $scope)->setRootExpr($expr),
				);
			}

			if ($context->truthy() && IntegerRangeType::fromInterval(1, null)->isSuperTypeOf($rightType)->yes()) {
				$argType = $scope->getType($unwrappedLeftExpr->getArgs()[0]->value);
				if ($argType->isString()->yes()) {
					$funcTypes = $this->create($unwrappedLeftExpr, $rightType, $context, $scope)->setRootExpr($expr);

					$accessory = new AccessoryNonEmptyStringType();
					if (IntegerRangeType::fromInterval(2, null)->isSuperTypeOf($rightType)->yes()) {
						$accessory = new AccessoryNonFalsyStringType();
					}
					$valueTypes = $this->create($unwrappedLeftExpr->getArgs()[0]->value, $accessory, $context, $scope)->setRootExpr($expr);

					return $funcTypes->unionWith($valueTypes);
				}
			}
		}

		// preg_match($a) === $b
		if (
			$context->true()
			&& $unwrappedLeftExpr instanceof Node\Expr\FuncCall
			&& $unwrappedLeftExpr->name instanceof Node\Name
			&& $unwrappedLeftExpr->name->toLowerString() === 'preg_match'
			&& (new ConstantIntegerType(1))->isSuperTypeOf($rightType)->yes()
		) {
			return $this->specifyTypesInCondition(
				$scope,
				$leftExpr,
				$context,
			)->setRootExpr($expr);
		}

		// get_class($a) === 'Foo'
		if (
			$context->true()
			&& $unwrappedLeftExpr instanceof Node\Expr\FuncCall
			&& $unwrappedLeftExpr->name instanceof Node\Name
			&& in_array(strtolower($unwrappedLeftExpr->name->toString()), ['get_class', 'get_debug_type'], true)
			&& isset($unwrappedLeftExpr->getArgs()[0])
		) {
			if ($rightType->getClassStringObjectType()->isObject()->yes()) {
				return $this->create(
					$unwrappedLeftExpr->getArgs()[0]->value,
					$rightType->getClassStringObjectType(),
					$context,
					$scope,
				)->unionWith($this->create($leftExpr, $rightType, $context, $scope))->setRootExpr($expr);
			}
		}

		// get_class($a) === 'Foo'
		if (
			$context->truthy()
			&& $unwrappedLeftExpr instanceof Node\Expr\FuncCall
			&& $unwrappedLeftExpr->name instanceof Node\Name
			&& in_array(strtolower($unwrappedLeftExpr->name->toString()), [
				'substr', 'strstr', 'stristr', 'strchr', 'strrchr', 'strtolower', 'strtoupper', 'ucfirst', 'lcfirst',
				'mb_substr', 'mb_strstr', 'mb_stristr', 'mb_strchr', 'mb_strrchr', 'mb_strtolower', 'mb_strtoupper', 'mb_ucfirst', 'mb_lcfirst',
				'ucwords', 'mb_convert_case', 'mb_convert_kana',
			], true)
			&& isset($unwrappedLeftExpr->getArgs()[0])
			&& $rightType->isNonEmptyString()->yes()
		) {
			$argType = $scope->getType($unwrappedLeftExpr->getArgs()[0]->value);

			if ($argType->isString()->yes()) {
				if ($rightType->isNonFalsyString()->yes()) {
					return $this->create(
						$unwrappedLeftExpr->getArgs()[0]->value,
						TypeCombinator::intersect($argType, new AccessoryNonFalsyStringType()),
						$context,
						$scope,
					)->setRootExpr($expr);
				}

				return $this->create(
					$unwrappedLeftExpr->getArgs()[0]->value,
					TypeCombinator::intersect($argType, new AccessoryNonEmptyStringType()),
					$context,
					$scope,
				)->setRootExpr($expr);
			}
		}

		if ($rightType->isString()->yes()) {
			$types = null;
			foreach ($rightType->getConstantStrings() as $constantString) {
				$specifiedType = $this->specifyTypesForConstantStringBinaryExpression($unwrappedLeftExpr, $constantString, $context, $scope, $expr);

				if ($specifiedType === null) {
					continue;
				}
				if ($types === null) {
					$types = $specifiedType;
					continue;
				}

				$types = $types->intersectWith($specifiedType);
			}

			if ($types !== null) {
				if ($leftExpr !== $unwrappedLeftExpr) {
					$types = $types->unionWith($this->create($leftExpr, $rightType, $context, $scope)->setRootExpr($expr));
				}
				return $types;
			}
		}

		$expressions = $this->findTypeExpressionsFromBinaryOperation($scope, $expr);
		if ($expressions !== null) {
			$exprNode = $expressions[0];
			$constantType = $expressions[1];

			$unwrappedExprNode = $exprNode;
			if ($exprNode instanceof AlwaysRememberedExpr) {
				$unwrappedExprNode = $exprNode->getExpr();
			}

			$specifiedType = $this->specifyTypesForConstantBinaryExpression($unwrappedExprNode, $constantType, $context, $scope, $expr);
			if ($specifiedType !== null) {
				if ($exprNode !== $unwrappedExprNode) {
					$specifiedType = $specifiedType->unionWith(
						$this->create($exprNode, $constantType, $context, $scope)->setRootExpr($expr),
					);
				}
				return $specifiedType;
			}
		}

		// $a::class === 'Foo'
		if (
			$context->true() &&
			$unwrappedLeftExpr instanceof Node\Expr\ClassConstFetch &&
			$unwrappedLeftExpr->class instanceof Node\Expr &&
			$unwrappedLeftExpr->name instanceof Node\Identifier &&
			$unwrappedRightExpr instanceof Node\Expr\ClassConstFetch &&
			$rightType instanceof ConstantStringType &&
			$rightType->getValue() !== '' &&
			strtolower($unwrappedLeftExpr->name->toString()) === 'class'
		) {
			return $this->specifyTypesInCondition(
				$scope,
				new Node\Expr\Instanceof_(
					$unwrappedLeftExpr->class,
					new Node\Name($rightType->getValue()),
				),
				$context,
			)->unionWith($this->create($leftExpr, $rightType, $context, $scope))->setRootExpr($expr);
		}

		$leftType = $scope->getType($leftExpr);

		// 'Foo' === $a::class
		if (
			$context->true() &&
			$unwrappedRightExpr instanceof Node\Expr\ClassConstFetch &&
			$unwrappedRightExpr->class instanceof Node\Expr &&
			$unwrappedRightExpr->name instanceof Node\Identifier &&
			$unwrappedLeftExpr instanceof Node\Expr\ClassConstFetch &&
			$leftType instanceof ConstantStringType &&
			$leftType->getValue() !== '' &&
			strtolower($unwrappedRightExpr->name->toString()) === 'class'
		) {
			return $this->specifyTypesInCondition(
				$scope,
				new Node\Expr\Instanceof_(
					$unwrappedRightExpr->class,
					new Node\Name($leftType->getValue()),
				),
				$context,
			)->unionWith($this->create($rightExpr, $leftType, $context, $scope)->setRootExpr($expr));
		}

		if ($context->false()) {
			$identicalType = $scope->getType($expr);
			if ($identicalType instanceof ConstantBooleanType) {
				$never = new NeverType();
				$contextForTypes = $identicalType->getValue() ? $context->negate() : $context;
				$leftTypes = $this->create($leftExpr, $never, $contextForTypes, $scope)->setRootExpr($expr);
				$rightTypes = $this->create($rightExpr, $never, $contextForTypes, $scope)->setRootExpr($expr);
				if ($leftExpr instanceof AlwaysRememberedExpr) {
					$leftTypes = $leftTypes->unionWith(
						$this->create($unwrappedLeftExpr, $never, $contextForTypes, $scope)->setRootExpr($expr),
					);
				}
				if ($rightExpr instanceof AlwaysRememberedExpr) {
					$rightTypes = $rightTypes->unionWith(
						$this->create($unwrappedRightExpr, $never, $contextForTypes, $scope)->setRootExpr($expr),
					);
				}
				return $leftTypes->unionWith($rightTypes);
			}
		}

		$types = null;
		if (
			count($leftType->getFiniteTypes()) === 1
			|| (
				$context->true()
				&& $leftType->isConstantValue()->yes()
				&& !$rightType->equals($leftType)
				&& $rightType->isSuperTypeOf($leftType)->yes())
		) {
			$types = $this->create(
				$rightExpr,
				$leftType,
				$context,
				$scope,
			)->setRootExpr($expr);
			if ($rightExpr instanceof AlwaysRememberedExpr) {
				$types = $types->unionWith($this->create(
					$unwrappedRightExpr,
					$leftType,
					$context,
					$scope,
				))->setRootExpr($expr);
			}
		}
		if (
			count($rightType->getFiniteTypes()) === 1
			|| (
				$context->true()
				&& $rightType->isConstantValue()->yes()
				&& !$leftType->equals($rightType)
				&& $leftType->isSuperTypeOf($rightType)->yes()
			)
		) {
			$leftTypes = $this->create(
				$leftExpr,
				$rightType,
				$context,
				$scope,
			)->setRootExpr($expr);
			if ($leftExpr instanceof AlwaysRememberedExpr) {
				$leftTypes = $leftTypes->unionWith($this->create(
					$unwrappedLeftExpr,
					$rightType,
					$context,
					$scope,
				))->setRootExpr($expr);
			}
			if ($types !== null) {
				$types = $types->unionWith($leftTypes);
			} else {
				$types = $leftTypes;
			}
		}

		if ($types !== null) {
			return $types;
		}

		$leftExprString = $this->exprPrinter->printExpr($unwrappedLeftExpr);
		$rightExprString = $this->exprPrinter->printExpr($unwrappedRightExpr);
		if ($leftExprString === $rightExprString) {
			if (!$unwrappedLeftExpr instanceof Node\Expr\Variable || !$unwrappedRightExpr instanceof Node\Expr\Variable) {
				return (new SpecifiedTypes([], []))->setRootExpr($expr);
			}
		}

		if ($context->true()) {
			$leftTypes = $this->create($leftExpr, $rightType, $context, $scope)->setRootExpr($expr);
			$rightTypes = $this->create($rightExpr, $leftType, $context, $scope)->setRootExpr($expr);
			if ($leftExpr instanceof AlwaysRememberedExpr) {
				$leftTypes = $leftTypes->unionWith(
					$this->create($unwrappedLeftExpr, $rightType, $context, $scope)->setRootExpr($expr),
				);
			}
			if ($rightExpr instanceof AlwaysRememberedExpr) {
				$rightTypes = $rightTypes->unionWith(
					$this->create($unwrappedRightExpr, $leftType, $context, $scope)->setRootExpr($expr),
				);
			}
			return $leftTypes->unionWith($rightTypes);
		} elseif ($context->false()) {
			return $this->create($leftExpr, $leftType, $context, $scope)->setRootExpr($expr)->normalize($scope)
				->intersectWith($this->create($rightExpr, $rightType, $context, $scope)->setRootExpr($expr)->normalize($scope));
		}

		return (new SpecifiedTypes([], []))->setRootExpr($expr);
	}

}
