<?php

// B: format-preserving, explicit AST anchors. Dry-run is the default.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

$root = realpath($argv[1]);
if (! $root || ! str_contains($root, '/rnd-autoconfig-apps/') || basename($root) !== 'B') {
    throw new RuntimeException('Only experiment B may be rewritten.');
}
$apply = in_array('--apply', $argv, true);
$parser = (new ParserFactory)->createForNewestSupportedVersion();
$finder = new NodeFinder;
$patches = [];
foreach (['app'] as $directory) {
    if (! is_dir($root.'/'.$directory)) {
        continue;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php' || $file->isLink()) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if (preg_match('~^app/(Models|Events|Providers|Stories|Storyfeed)/~', $relative)) {
            continue;
        }
        $code = file_get_contents($file->getPathname());
        $ast = (new NodeTraverser(new NameResolver))->traverse($parser->parse($code));
        $edits = [];
        foreach ($finder->find($ast, fn ($n) => $n instanceof Node\Stmt\ClassMethod || $n instanceof Node\Expr\Closure) as $scope) {
            $types = [];
            foreach ($scope->params as $param) {
                if ($param->type instanceof Node\Name && str_starts_with($param->type->toString(), 'App\\Models\\')) {
                    $types[$param->var->name] = $param->type->toString();
                }
            }
            // Experimental fallback: local `new Model` assignments. This is a
            // candidate proof, not flow-sensitive type or transaction analysis.
            foreach ($finder->findInstanceOf($scope->stmts ?? [], Node\Expr\Assign::class) as $assign) {
                if ($assign->var instanceof Node\Expr\Variable && $assign->expr instanceof Node\Expr\New_ && $assign->expr->class instanceof Node\Name && str_starts_with($assign->expr->class->toString(), 'App\\Models\\')) {
                    $types[$assign->var->name] = $assign->expr->class->toString();
                }
            }
            foreach ($finder->findInstanceOf($scope->stmts ?? [], Node\Stmt\Expression::class) as $stmt) {
                if (! $stmt instanceof Node\Stmt\Expression) {
                    continue;
                }
                $expr = $stmt->expr;
                if (! $expr instanceof Node\Expr\MethodCall || ! $expr->name instanceof Node\Identifier) {
                    continue;
                }
                $receiver = $expr->var;
                while ($receiver instanceof Node\Expr\MethodCall) {
                    $receiver = $receiver->var;
                }
                if (! $receiver instanceof Node\Expr\Variable) {
                    continue;
                }
                $variable = $receiver->name;
                $method = $expr->name->toString();
                if (! isset($types[$variable]) || ! in_array($method, ['update', 'save', 'delete', 'restore'])) {
                    continue;
                }
                $verb = $method === 'save' ? 'update' : $method;
                $model = basename(str_replace('\\', '/', $types[$variable]));
                $name = 'inferred.'.$model.'.'.$verb;
                if (str_contains($code, "story('".$name."'")) {
                    continue;
                }
                $lineStart = strrpos(substr($code, 0, $stmt->getStartFilePos()), "\n");
                preg_match('/^\s*/', substr($code, $lineStart + 1), $match);
                $indent = rtrim($match[0], "\r\n");
                $text = "\n".$indent."story('{$name}', \${$variable})->actor(null)->publish();";
                $edits[$stmt->getEndFilePos()] = ['offset' => $stmt->getEndFilePos() + 1, 'text' => $text, 'object' => $model, 'verb' => $verb,
                    'line' => $stmt->getStartLine(), 'risk' => 'Write return value, vetoed model events, actor, retries and commit timing require review.'];
            }
        }
        if ($edits === []) {
            continue;
        }
        usort($edits, fn ($a, $b) => $b['offset'] <=> $a['offset']);
        $new = $code;
        foreach ($edits as $edit) {
            $new = substr_replace($new, $edit['text'], $edit['offset'], 0);
        }
        $parser->parse($new);
        $patches[] = ['file' => substr($file->getPathname(), strlen($root) + 1), 'before' => $code, 'after' => $new, 'edits' => $edits];
        if ($apply) {
            file_put_contents($file->getPathname(), $new);
        }
    }
}
echo json_encode(['mode' => $apply ? 'apply' : 'dry-run', 'patches' => $patches], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
