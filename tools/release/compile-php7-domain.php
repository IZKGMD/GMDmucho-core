<?php
/** Build-time lowering of original domain sources. Never runs on the shared host. */
declare(strict_types=1);

$parserRoot = getenv('MUCHO_PHP_PARSER_ROOT');
if ($parserRoot) {
    spl_autoload_register(static function (string $class) use ($parserRoot): void {
        if (str_starts_with($class, 'PhpParser\\')) {
            require $parserRoot . '/lib/' . str_replace('\\', '/', $class) . '.php';
        }
    });
} elseif (is_file(__DIR__ . '/../../vendor/autoload.php')) {
    require __DIR__ . '/../../vendor/autoload.php';
}
if (!class_exists(PhpParser\ParserFactory::class)) {
    fwrite(STDERR, "Set MUCHO_PHP_PARSER_ROOT to nikic/PHP-Parser v5.6.2.\n");
    exit(1);
}

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Arrow/closure free variables, respecting nested lexical scope. */
function muchoBuildFree(Node|array|null $node, array $bound = []): array
{
    if ($node === null) return [];
    if (is_array($node)) {
        $names = [];
        foreach ($node as $child) $names += muchoBuildFree($child, $bound);
        return $names;
    }
    if ($node instanceof Expr\Variable && is_string($node->name)) {
        return isset($bound[$node->name]) || in_array($node->name,
            ['this','GLOBALS','_SERVER','_GET','_POST','_FILES','_COOKIE','_SESSION','_ENV','_REQUEST'], true)
            ? [] : [$node->name => true];
    }
    if ($node instanceof Expr\Closure) {
        $names = [];
        foreach ($node->uses as $use) $names += muchoBuildFree($use->var, $bound);
        return $names;
    }
    if ($node instanceof Expr\ArrowFunction) {
        foreach ($node->params as $param) $bound[$param->var->name] = true;
        return muchoBuildFree($node->expr, $bound);
    }
    $names = [];
    foreach ($node->getSubNodeNames() as $key) {
        $value = $node->$key;
        if ($value instanceof Node || is_array($value)) $names += muchoBuildFree($value, $bound);
    }
    return $names;
}

function muchoBuildClosure(array $statements, Node|array $scope, array $bound = []): Expr\FuncCall
{
    $uses = [];
    foreach (muchoBuildFree($scope, $bound) as $name => $_) {
        $uses[] = new Node\ClosureUse(new Expr\Variable($name), true);
    }
    return new Expr\FuncCall(new Expr\Closure(['uses' => $uses, 'stmts' => $statements]));
}

final class MuchoPhp70Lowering extends PhpParser\NodeVisitorAbstract
{
    private array $parents = [];
    private int $serial = 0;
    public function __construct(private string $relativeFile, private array $signatures) {}

    public function enterNode(Node $node): void
    {
        $this->parents[] = $node;
        if ($node instanceof Expr\CallLike && !$node->isFirstClassCallable()) {
            $args = $node->getArgs();
            if (array_filter($args, static fn($arg) => $arg->name !== null)) {
                $name = $node instanceof Expr\New_ ? '__construct'
                    : (($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) && $node->name instanceof Node\Identifier ? strtolower($node->name->name) : '');
                $matches = array_filter($this->signatures[$name] ?? [], static function ($params) use ($args) {
                    foreach ($args as $index=>$arg) {
                        if ($arg->name !== null && ($params[$index] ?? null) !== $arg->name->name) return false;
                    }
                    return true;
                });
                if (!$matches) throw new RuntimeException('Out-of-order or unknown named call '.$name.' in '.$this->relativeFile);
                foreach ($args as $arg) $arg->name = null;
            }
        }
        if ($node instanceof Stmt\Class_) {
            $node->flags &= ~PhpParser\Modifiers::READONLY;
            foreach ($node->getMethods() as $method) {
                if (strtolower($method->name->toString()) !== '__construct') continue;
                $assignments = [];
                foreach ($method->params as $param) {
                    if (!$param->flags) continue;
                    $visibility = $param->flags & PhpParser\Modifiers::VISIBILITY_MASK;
                    $node->stmts[] = new Stmt\Property($visibility, [new Node\PropertyItem($param->var->name)]);
                    $assignments[] = new Stmt\Expression(new Expr\Assign(
                        new Expr\PropertyFetch(new Expr\Variable('this'), $param->var->name), clone $param->var));
                    $param->flags = 0;
                }
                $method->stmts = array_merge($assignments, $method->stmts ?? []);
            }
        }
        if ($node instanceof Stmt\Property) {
            $node->type = null;
            $node->flags &= ~PhpParser\Modifiers::READONLY;
        }
        if ($node instanceof Stmt\ClassConst) $node->flags = 0;
        if (property_exists($node, 'attrGroups')) $node->attrGroups = [];
        if ($node instanceof Node\Param) $node->type = $this->type($node->type);
        if ($node instanceof Node\FunctionLike) {
            $node->returnType = $this->type($node->returnType);
            foreach ($node->params as $param) {
                if (!($param->default instanceof Expr\New_)) continue;
                $value = $param->default;
                $param->default = new Expr\ConstFetch(new Node\Name('null'));
                if (!is_array($node->stmts)) throw new RuntimeException('Object default without function body');
                array_unshift($node->stmts, new Stmt\If_(new Expr\BinaryOp\Identical(clone $param->var,
                    new Expr\ConstFetch(new Node\Name('null'))), ['stmts'=>[
                        new Stmt\Expression(new Expr\Assign(clone $param->var, $value))]]));
            }
        }
        if ($node instanceof Stmt\Catch_ && $node->var === null) $node->var = new Expr\Variable('__mcCaught');
    }

    private function type(?Node $type): ?Node
    {
        if ($type instanceof Node\ComplexType) return null;
        if ($type instanceof Node\Identifier && in_array(strtolower($type->name),
            ['void','never','mixed','object','iterable','static','false','true','null'], true)) return null;
        return $type;
    }

    public function leaveNode(Node $node): ?Node
    {
        array_pop($this->parents);
        $parent = $this->parents ? $this->parents[array_key_last($this->parents)] : null;
        if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name
            && strtolower(ltrim($node->class->toString(),'\\'))==='closure' && $node->name instanceof Node\Identifier
            && strtolower($node->name->name)==='fromcallable') {
            return new Expr\FuncCall(new Node\Name\FullyQualified('mucho7_callable_closure'),$node->args);
        }
        if ($node instanceof Expr\ClassConstFetch && $node->name instanceof Node\Identifier
            && strtolower($node->name->name)==='class' && $node->class instanceof Expr) {
            return new Expr\FuncCall(new Node\Name\FullyQualified('get_class'), [new Node\Arg($node->class)]);
        }
        if ($node instanceof Expr\CallLike && $node->isFirstClassCallable()) {
            $call = clone $node;
            $args = new Expr\Variable('__mcCallArgs');
            $call->args = [new Node\Arg($args, false, true)];
            $uses = [];
            foreach (muchoBuildFree($node) as $name=>$_) $uses[] = new Node\ClosureUse(new Expr\Variable($name));
            return new Expr\Closure(['params'=>[new Node\Param($args, null, null, false, true)],
                'uses'=>$uses,'stmts'=>[new Stmt\Return_($call)]]);
        }
        if ($node instanceof Expr\ArrowFunction) {
            $bound = [];
            foreach ($node->params as $param) $bound[$param->var->name] = true;
            $uses = [];
            foreach (muchoBuildFree($node->expr, $bound) as $name => $_) $uses[] = new Node\ClosureUse(new Expr\Variable($name));
            return new Expr\Closure(['static'=>$node->static,'byRef'=>$node->byRef,'params'=>$node->params,
                'uses'=>$uses,'returnType'=>$node->returnType,'stmts'=>[new Stmt\Return_($node->expr)]], $node->getAttributes());
        }
        if ($node instanceof Expr\Match_) {
            $variable = new Expr\Variable('__mcMatch' . ++$this->serial);
            $statements = [new Stmt\Expression(new Expr\Assign($variable, $node->cond))];
            $default = null;
            foreach ($node->arms as $arm) {
                if ($arm->conds === null) { $default = $arm->body; continue; }
                $condition = null;
                foreach ($arm->conds as $value) {
                    $equal = new Expr\BinaryOp\Identical(clone $variable, $value);
                    $condition = $condition === null ? $equal : new Expr\BinaryOp\BooleanOr($condition, $equal);
                }
                $statements[] = new Stmt\If_($condition, ['stmts'=>[new Stmt\Return_($arm->body)]]);
            }
            $statements[] = $default !== null ? new Stmt\Return_($default)
                : new Stmt\Expression(new Expr\Throw_(new Expr\New_(new Node\Name\FullyQualified('UnhandledMatchError'))));
            return muchoBuildClosure($statements, $node);
        }
        if ($node instanceof Expr\Throw_ && !($parent instanceof Stmt\Expression)) {
            return muchoBuildClosure([new Stmt\Expression($node)], $node);
        }
        if ($node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\NullsafePropertyFetch) {
            $variable = new Expr\Variable('__mcOptional' . ++$this->serial);
            $access = $node instanceof Expr\NullsafeMethodCall
                ? new Expr\MethodCall(clone $variable, $node->name, $node->args)
                : new Expr\PropertyFetch(clone $variable, $node->name);
            return muchoBuildClosure([
                new Stmt\Expression(new Expr\Assign($variable, $node->var)),
                new Stmt\Return_(new Expr\Ternary(new Expr\BinaryOp\Identical(clone $variable,
                    new Expr\ConstFetch(new Node\Name('null'))), new Expr\ConstFetch(new Node\Name('null')), $access))
            ], $node);
        }
        if ($node instanceof Expr\AssignOp\Coalesce) {
            // This source tree uses simple variables/properties here. Reject
            // side-effectful lvalues instead of evaluating them twice.
            if (!($node->var instanceof Expr\Variable) && !($node->var instanceof Expr\PropertyFetch)
                && !($node->var instanceof Expr\StaticPropertyFetch)) throw new RuntimeException('Complex ??= lvalue');
            return new Expr\Assign($node->var, new Expr\BinaryOp\Coalesce(clone $node->var, $node->expr));
        }
        if ($node instanceof Expr\List_) $node->setAttribute('kind', Expr\List_::KIND_LIST);
        if ($node instanceof Stmt\TryCatch) {
            $catches = [];
            foreach ($node->catches as $catch) foreach ($catch->types as $type) {
                $copy = clone $catch;$copy->types = [$type];$catches[] = $copy;
            }
            $node->catches = $catches;
        }
        if ($node instanceof Expr\Array_ && array_filter($node->items, static fn($item)=>$item && $item->unpack)) {
            $args = [];$group = [];
            foreach ($node->items as $item) {
                if ($item->unpack) {
                    if ($group) {$args[] = new Node\Arg(new Expr\Array_($group));$group=[];}
                    $args[] = new Node\Arg($item->value);
                } else $group[] = $item;
            }
            if ($group) $args[] = new Node\Arg(new Expr\Array_($group));
            return new Expr\FuncCall(new Node\Name\FullyQualified('array_merge'), $args);
        }
        if ($node instanceof Node\Arg && $node->name !== null) {
            throw new RuntimeException('Named argument '.$node->name.' in '.$this->relativeFile);
        }
        if ($node instanceof Node\Scalar\MagicConst\Dir || $node instanceof Node\Scalar\MagicConst\File) {
            $path = $node instanceof Node\Scalar\MagicConst\Dir ? dirname($this->relativeFile) : $this->relativeFile;
            $constant=$node instanceof Node\Scalar\MagicConst\File && str_starts_with($this->relativeFile,'tests/') ? 'MUCHO7_COMPILED_ROOT' : 'MUCHO7_PROJECT_ROOT';
            return new Expr\BinaryOp\Concat(new Expr\ConstFetch(new Node\Name\FullyQualified($constant)),
                new Node\Scalar\String_('/'.$path));
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name
            && in_array(strtolower($node->name->toString()), ['json_encode','json_decode'], true)) {
            $node->name = new Node\Name\FullyQualified('mucho7_'.$node->name->toString());
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name && strtolower($node->name->toString())==='proc_open') {
            $node->name = new Node\Name\FullyQualified('mucho7_proc_open');
        }
        if ($this->relativeFile==='src/CloudSave/CloudSaveRepository.php' && $node instanceof Expr\FuncCall
            && $node->name instanceof Node\Name && in_array(strtolower($node->name->toString()),['openssl_encrypt','openssl_decrypt'],true)) {
            $node->name = new Node\Name\FullyQualified('mucho7_'.$node->name->toString());
        }
        if ($node instanceof Expr\Include_) {
            $node->expr = new Expr\FuncCall(new Node\Name\FullyQualified('mucho7_compiled_include'), [new Node\Arg($node->expr)]);
        }
        if (str_starts_with($this->relativeFile,'database/migrations/') && $node instanceof Expr\MethodCall
            && $node->name instanceof Node\Identifier && strtolower($node->name->name)==='exec') {
            return new Expr\FuncCall(new Node\Name\FullyQualified('mucho7_domain_schema_exec'),
                array_merge([new Node\Arg($node->var)],$node->args));
        }
        return null;
    }
}

$root = dirname(__DIR__, 2);
$destination = $argv[1] ?? $root.'/compat/php7/core';
$parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
$printer = new PhpParser\PrettyPrinter\Standard(['phpVersion'=>PhpParser\PhpVersion::fromString('7.0')]);
$files = [];
foreach (['src','database/migrations'] as $sourceDirectory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$sourceDirectory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension()==='php') $files[] = $file->getPathname();
    }
}
sort($files);
$integrationTests=['admin-clans-integration','clan-client-integration','gd2209-gameplay-schema-integration',
    'gd2209-social-integration','gd2209-thread-integration','gd2209-level-integration',
    'gd2209-moderation-queue-integration','gd2209-report-queue-integration','gd2209-case-actions-integration',
    'gd2209-sanctions-utilities-integration','gd2209-song-usage-integration','gd2209-audio-integration'];
foreach($integrationTests as $test)$files[]=$root.'/tests/application/'.$test.'.php';
foreach (array_slice($argv,2) as $extra) {
    $path=realpath($root.'/'.$extra);
    if (!$path || !str_starts_with($path,$root.'/') || !str_ends_with($path,'.php')) throw new RuntimeException('Invalid extra source');
    $files[]=$path;
}
$files=array_values(array_unique($files));sort($files);
$signatures = [];
$finder = new PhpParser\NodeFinder();
foreach ($files as $file) {
    foreach ($finder->findInstanceOf($parser->parse(file_get_contents($file)), Stmt\ClassMethod::class) as $method) {
        $signatures[strtolower($method->name->name)][] = array_map(static fn($param)=>$param->var->name, $method->params);
    }
}
$manifest = [];
$failures = [];
foreach ($files as $file) {
    $relative = substr($file, strlen($root)+1);
    try {
        $tree = $parser->parse(file_get_contents($file));
        $traverser = new PhpParser\NodeTraverser(new MuchoPhp70Lowering($relative, $signatures));
        $tree = $traverser->traverse($tree);
        if(str_starts_with($relative,'tests/')){
            $bootstrap=new Stmt\Expression(new Expr\Include_(new Expr\BinaryOp\Concat(
                new Expr\FuncCall(new Node\Name('dirname'),[new Node\Arg(new Node\Scalar\MagicConst\Dir()),new Node\Arg(new Node\Scalar\Int_(count(explode('/',$relative))))]),
                new Node\Scalar\String_('/domain.php')),Expr\Include_::TYPE_REQUIRE_ONCE));
            $position=isset($tree[0]) && $tree[0] instanceof Stmt\Declare_ ? 1 : 0;
            array_splice($tree,$position,0,[$bootstrap]);
        }
        $output = $printer->prettyPrintFile($tree)."\n";
        $target = $destination.'/'.$relative;
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
        file_put_contents($target, $output);
        $manifest[$relative] = hash_file('sha256', $file);
    } catch (Throwable $error) {
        $failures[] = $relative.': '.$error->getMessage();
    }
}
if ($failures) { fwrite(STDERR, implode("\n", $failures)."\n"); exit(1); }
file_put_contents($destination.'/sources.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
file_put_contents($destination.'/autoload.php', "<?php\nrequire_once dirname(__DIR__).'/domain.php';\nreturn true;\n");
echo 'PHP7_DOMAIN_COMPILED '.count($manifest)." original sources\n";
