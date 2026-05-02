<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;

class GenerateOpenApiSpec extends Command
{
    protected $signature = 'api:generate-openapi
        {--output=storage/app/openapi.json : Output path (relative to base)}
        {--prefix=api : Only include routes starting with this prefix}
        {--format=json : Output format: json or yaml}';

    protected $description = 'Generate an OpenAPI 3.0 spec from registered API routes.';

    public function handle(Router $router): int
    {
        $prefix = trim((string) $this->option('prefix'), '/');
        $outputPath = base_path($this->option('output'));
        $format = strtolower((string) $this->option('format'));

        $paths = [];
        $tags = [];

        foreach ($router->getRoutes() as $route) {
            assert($route instanceof Route);
            $uri = $route->uri();
            if ($prefix !== '' && ! str_starts_with($uri, $prefix.'/') && $uri !== $prefix) {
                continue;
            }

            $methods = array_diff($route->methods(), ['HEAD', 'OPTIONS']);
            if ($methods === []) {
                continue;
            }

            $openapiPath = '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => '{'.$m[1].'}', $uri);
            $action = $route->getActionName();
            $tagName = $this->resolveTag($action, $uri);
            $tags[$tagName] = true;

            $params = $this->extractParams($uri);

            foreach ($methods as $method) {
                $methodLower = strtolower($method);
                if (! isset($paths[$openapiPath])) {
                    $paths[$openapiPath] = [];
                }

                $paths[$openapiPath][$methodLower] = [
                    'tags' => [$tagName],
                    'summary' => $this->buildSummary($action, $methodLower, $uri),
                    'operationId' => $this->buildOperationId($methodLower, $uri),
                    'parameters' => $params,
                    'responses' => [
                        '200' => ['description' => 'Successful response'],
                        '401' => ['description' => 'Unauthenticated'],
                        '403' => ['description' => 'Forbidden'],
                        '404' => ['description' => 'Not found'],
                        '422' => ['description' => 'Validation error'],
                    ],
                    'security' => $this->requiresAuth($route) ? [['bearerAuth' => []]] : [],
                ];
            }
        }

        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'ZULU Platform API',
                'description' => 'Generated from registered routes via php artisan api:generate-openapi.',
                'version' => '1.0.0',
                'contact' => ['name' => 'ZULU Platform'],
            ],
            'servers' => [
                ['url' => config('app.url'), 'description' => 'Configured app URL'],
                ['url' => 'https://api.zulu.am', 'description' => 'Production'],
            ],
            'tags' => array_map(fn ($name) => ['name' => $name], array_keys($tags)),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Sanctum-Token',
                    ],
                ],
            ],
        ];

        File::ensureDirectoryExists(dirname($outputPath));

        if ($format === 'yaml') {
            $output = $this->toSimpleYaml($spec);
            $outputPath = preg_replace('/\.json$/', '.yaml', $outputPath);
        } else {
            $output = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        File::put($outputPath, (string) $output);

        $endpointCount = array_sum(array_map('count', $paths));
        $this->info(sprintf(
            'OpenAPI spec written to %s (%d paths, %d endpoints across %d tags).',
            $outputPath,
            count($paths),
            $endpointCount,
            count($tags),
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractParams(string $uri): array
    {
        if (! preg_match_all('/\{(\w+)\??\}/', $uri, $matches)) {
            return [];
        }

        return array_map(fn ($name) => [
            'name' => $name,
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string'],
        ], $matches[1]);
    }

    private function resolveTag(string $action, string $uri): string
    {
        // Group by controller name (last segment before "Controller@...")
        if (preg_match('/\\\\([A-Z]\w+)Controller@/', $action, $m)) {
            return $m[1];
        }

        return strtok($uri, '/') ?: 'general';
    }

    private function buildSummary(string $action, string $method, string $uri): string
    {
        $controllerAction = '';
        if (preg_match('/@(\w+)$/', $action, $m)) {
            $controllerAction = $m[1];
        }

        return strtoupper($method).' /'.$uri.($controllerAction ? ' — '.$controllerAction : '');
    }

    private function buildOperationId(string $method, string $uri): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9]+/', '_', $uri);

        return $method.'_'.trim((string) $clean, '_');
    }

    private function requiresAuth(Route $route): bool
    {
        $middleware = (array) $route->gatherMiddleware();
        foreach ($middleware as $m) {
            if (str_starts_with((string) $m, 'auth:') || $m === 'auth' || $m === 'auth.basic') {
                return true;
            }
        }

        return false;
    }

    /**
     * Minimal YAML emitter for the OpenAPI structure (no external dep).
     *
     * @param  mixed  $data
     */
    private function toSimpleYaml($data, int $indent = 0): string
    {
        $pad = str_repeat('  ', $indent);
        $out = '';

        if (is_array($data)) {
            $isList = array_keys($data) === range(0, count($data) - 1);
            foreach ($data as $key => $value) {
                if ($isList) {
                    if (is_scalar($value) || $value === null) {
                        $out .= $pad.'- '.$this->yamlScalar($value)."\n";
                    } else {
                        $out .= $pad."-\n".$this->toSimpleYaml($value, $indent + 1);
                    }
                } else {
                    if (is_scalar($value) || $value === null) {
                        $out .= $pad.$key.': '.$this->yamlScalar($value)."\n";
                    } else {
                        $out .= $pad.$key.":\n".$this->toSimpleYaml($value, $indent + 1);
                    }
                }
            }
        }

        return $out;
    }

    private function yamlScalar(mixed $value): string
    {
        if ($value === null) {
            return '~';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $str = (string) $value;
        if (preg_match('/[:#\n]/', $str) || $str === '' || str_contains($str, '"')) {
            return '"'.str_replace('"', '\\"', $str).'"';
        }

        return $str;
    }
}
