<?php

// Static R&D probe. Never boots the inspected application or reads its database.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

$root = realpath($argv[1]);
$parser = (new ParserFactory)->createForNewestSupportedVersion();
$finder = new NodeFinder;
$printer = new Standard;
$result = ['schema' => 1, 'classes' => [], 'signals' => [], 'errors' => []];
foreach (['app', 'routes', 'config', 'database/migrations'] as $dir) {
    if (! is_dir($root.'/'.$dir)) {
        continue;
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' || $file->isLink()) {
            continue;
        }
        $path = substr($file->getPathname(), strlen($root) + 1);
        $code = file_get_contents($file->getPathname());
        try {
            $ast = $parser->parse($code);
            $resolver = new NodeTraverser(new NameResolver);
            $ast = $resolver->traverse($ast);
        } catch (Throwable $e) {
            $result['errors'][] = ['file' => $path, 'message' => $e->getMessage()];

            continue;
        }
        foreach ($finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $class) {
            if (! isset($class->namespacedName)) {
                continue;
            }
            $row = ['class' => $class->namespacedName->toString(), 'file' => $path, 'line' => $class->getStartLine(),
                'extends' => $class->extends?->toString(), 'abstract' => $class->isAbstract(), 'interfaces' => array_map(fn ($n) => $n->toString(), $class->implements),
                'traits' => [], 'properties' => [], 'methods' => []];
            foreach ($class->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\TraitUse) {
                    $row['traits'] = array_merge($row['traits'], array_map(fn ($n) => $n->toString(), $stmt->traits));
                }
                if ($stmt instanceof Node\Stmt\Property) {
                    foreach ($stmt->props as $prop) {
                        $row['properties'][$prop->name->toString()] = $stmt->type ? $printer->prettyPrint([$stmt->type]) : null;
                    }
                }
                if (! $stmt instanceof Node\Stmt\ClassMethod) {
                    continue;
                }
                $params = [];
                foreach ($stmt->params as $param) {
                    $type = $param->type ? $printer->prettyPrint([$param->type]) : null;
                    $params[$param->var->name] = $type;
                    if ($param->flags) {
                        $row['properties'][$param->var->name] = $type;
                    }
                }
                $row['methods'][] = ['name' => $stmt->name->toString(), 'line' => $stmt->getStartLine(), 'params' => $params];
            }
            $result['classes'][] = $row;
        }
        foreach ($finder->find($ast, fn ($n) => $n instanceof Node\Expr\StaticCall || $n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\FuncCall) as $call) {
            $name = $call->name instanceof Node\Identifier || $call->name instanceof Node\Name ? $call->name->toString() : '';
            $categories = [];
            if (in_array($name, ['morphMap', 'enforceMorphMap'])) {
                $categories[] = 'morph_map';
            }
            if (in_array($name, ['created', 'updated', 'deleted', 'restored', 'observe'])) {
                $categories[] = 'model_event';
            }
            if (in_array($name, ['resource', 'apiResource', 'get', 'post', 'put', 'patch', 'delete', 'name']) && str_starts_with($path, 'routes/')) {
                $categories[] = 'route';
            }
            if (in_array($name, ['dispatch', 'event', 'listen', 'subscribe'])) {
                $categories[] = 'event_wiring';
            }
            if (in_array($name, ['auth', 'user', 'actor', 'by'])) {
                $categories[] = 'actor_hint';
            }
            if (in_array($name, ['transaction', 'afterCommit'])) {
                $categories[] = 'transaction';
            }
            if (in_array($name, ['create', 'save', 'update', 'delete', 'restore', 'forceDelete'])) {
                $categories[] = 'mutation';
            }
            foreach ($categories as $category) {
                $result['signals'][] = ['kind' => $category, 'file' => $path, 'line' => $call->getStartLine(), 'code' => $printer->prettyPrintExpr($call), 'confidence' => 'syntax-only'];
            }
        }
    }
}
$composer = json_decode(file_get_contents($root.'/composer.json'), true);
$result['activitylog'] = ['installed' => isset($composer['require']['spatie/laravel-activitylog']), 'config_present' => is_file($root.'/config/activitylog.php'), 'database_inspected' => false];
usort($result['classes'], fn ($a, $b) => $a['class'] <=> $b['class']);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
