<?php

declare(strict_types=1);

namespace JamesGifford\Auth\Installer;

use Illuminate\Contracts\Foundation\Application;
use JamesGifford\Auth\Models\Account;
use JamesGifford\Auth\Models\AccountRole;
use JamesGifford\Auth\Models\AccountUser;
use ReflectionClass;
use Throwable;

/**
 * Publishes the package's models into the consuming app as populated
 * subclasses (App\Models\Account, etc.) that EXTEND the package base models.
 *
 * Each published model carries the commonly-customized surface written out and
 * visible — the #[Fillable] attribute (and #[Hidden] if any), the
 * publicIdPrefix() method (only when the base uses public IDs), and casts() —
 * all DERIVED from the actual base model so the published file is correct out
 * of the box. Everything else (relationships, soft deletes, events, pivot
 * mechanics, invariants) stays in the base and flows through inheritance.
 *
 * Shared by the standalone `jamesgifford:auth:publish-models` command and the
 * install command's opt-in publishing step.
 */
final class ModelPublisher
{
    /**
     * Base model FQCN => model-resolution config key.
     */
    private const MODELS = [
        Account::class => 'account',
        AccountUser::class => 'account_user',
        AccountRole::class => 'account_role',
    ];

    public function __construct(private readonly Application $app) {}

    /**
     * The app's model namespace (e.g. App\Models), derived from the app's root
     * namespace rather than hardcoded.
     */
    public function modelNamespace(): string
    {
        return rtrim($this->app->getNamespace(), '\\').'\\Models';
    }

    /**
     * The directory published models are written to (e.g. app/Models).
     */
    public function modelDirectory(): string
    {
        return $this->app->path('Models');
    }

    /**
     * Publish all three subclasses. Existing target files are NEVER overwritten
     * (the consumer may have customized them) — they are skipped.
     *
     * @return list<array{name: string, configKey: string, baseClass: string, appClass: string, path: string, status: string}>
     */
    public function publish(): array
    {
        $directory = $this->modelDirectory();
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $results = [];
        foreach (self::MODELS as $baseClass => $configKey) {
            $short = class_basename($baseClass);
            $path = $directory.DIRECTORY_SEPARATOR.$short.'.php';

            if (is_file($path)) {
                $status = 'skipped';
            } else {
                file_put_contents($path, $this->stubFor($baseClass, $short));
                $status = 'created';
            }

            $results[] = [
                'name' => $short,
                'configKey' => $configKey,
                'baseClass' => $baseClass,
                'appClass' => $this->modelNamespace().'\\'.$short,
                'path' => $path,
                'status' => $status,
            ];
        }

        return $results;
    }

    /**
     * The published-model files this package CAN create in the app, with the
     * base class each extends. Used by uninstall to detect which actually
     * exist (and are genuinely the package's published subclasses) before
     * offering to remove them.
     *
     * @return list<array{name: string, path: string, baseClass: string}>
     */
    public function candidatePaths(): array
    {
        $directory = $this->modelDirectory();

        $candidates = [];
        foreach (self::MODELS as $baseClass => $configKey) {
            $short = class_basename($baseClass);
            $candidates[] = [
                'name' => $short,
                'path' => $directory.DIRECTORY_SEPARATOR.$short.'.php',
                'baseClass' => $baseClass,
            ];
        }

        return $candidates;
    }

    /**
     * Model-resolution config map (key => published FQCN) for wiring.
     *
     * @return array<string, string>
     */
    public function configMap(): array
    {
        $map = [];
        foreach (self::MODELS as $baseClass => $configKey) {
            $map[$configKey] = $this->modelNamespace().'\\'.class_basename($baseClass);
        }

        return $map;
    }

    /**
     * Published model files that exist on disk AND are genuinely the
     * package's subclasses (their source references the package base
     * class) — so an unrelated App\Models\Account the consumer wrote
     * themselves is never mistaken for ours and silently wired into config.
     *
     * @return list<array{name: string, configKey: string, baseClass: string, appClass: string, path: string}>
     */
    public function genuinelyPublished(): array
    {
        $present = [];
        foreach ($this->candidatePaths() as $candidate) {
            if (! is_file($candidate['path'])) {
                continue;
            }

            $contents = (string) file_get_contents($candidate['path']);
            if (! str_contains($contents, $candidate['baseClass'])) {
                continue;
            }

            $present[] = [
                'name' => $candidate['name'],
                'configKey' => self::MODELS[$candidate['baseClass']],
                'baseClass' => $candidate['baseClass'],
                'appClass' => $this->modelNamespace().'\\'.$candidate['name'],
                'path' => $candidate['path'],
            ];
        }

        return $present;
    }

    /**
     * Path to the published config file this class edits to wire published
     * subclasses into model resolution.
     */
    public function publishedConfigPath(): string
    {
        return config_path('jamesgifford'.DIRECTORY_SEPARATOR.'auth.php');
    }

    /**
     * Point every genuinely-published subclass's models.<key> value at its
     * App\Models class in the published config file, so publishing a
     * subclass never leaves the package silently resolving the base class
     * instead.
     *
     * A targeted regex replace scoped to the 'models' => [...] block — not
     * structural parsing — so the rest of the consumer's file (formatting,
     * comments, other keys) is left untouched. Idempotent: rewriting an
     * already-correct value reproduces the same text. Also mirrors each
     * change into the live config repository so this same process resolves
     * correctly without a config:clear round trip.
     *
     * @return array{registered: array<string, string>, failed: list<string>}
     */
    public function registerPublishedModels(): array
    {
        $path = $this->publishedConfigPath();
        $published = $this->genuinelyPublished();

        if (! is_file($path)) {
            return [
                'registered' => [],
                'failed' => array_column($published, 'configKey'),
            ];
        }

        $original = (string) file_get_contents($path);
        $contents = $original;
        $registered = [];
        $failed = [];

        foreach ($published as $model) {
            $configKey = $model['configKey'];
            $appClass = $model['appClass'];

            $pattern = "/('models'\\s*=>\\s*\\[.*?'".preg_quote($configKey, '/')."'\\s*=>\\s*)[^,]+,/s";
            $matched = false;
            $updated = preg_replace_callback($pattern, function (array $m) use ($appClass, &$matched): string {
                $matched = true;

                return $m[1]."\\{$appClass}::class,";
            }, $contents, 1);

            if (! $matched || ! is_string($updated)) {
                $failed[] = $configKey;

                continue;
            }

            $contents = $updated;
            $registered[$configKey] = $appClass;
            config(["jamesgifford.auth.models.{$configKey}" => $appClass]);
        }

        if ($contents !== $original) {
            file_put_contents($path, $contents);
        }

        return ['registered' => $registered, 'failed' => $failed];
    }

    /**
     * Per published-subclass key, whether the LIVE model-resolution config
     * actually matches its genuinely published subclass — the same value
     * PackageModels would resolve right now, not merely what the file's
     * text says.
     *
     * @return list<array{configKey: string, appClass: ?string, status: 'registered'|'unregistered'|'not_published'}>
     */
    public function configConsistency(): array
    {
        $publishedByKey = [];
        foreach ($this->genuinelyPublished() as $model) {
            $publishedByKey[$model['configKey']] = $model['appClass'];
        }

        $report = [];
        foreach (self::MODELS as $configKey) {
            if (! isset($publishedByKey[$configKey])) {
                $report[] = ['configKey' => $configKey, 'appClass' => null, 'status' => 'not_published'];

                continue;
            }

            $appClass = $publishedByKey[$configKey];
            $resolved = ltrim((string) config("jamesgifford.auth.models.{$configKey}"), '\\');

            $report[] = [
                'configKey' => $configKey,
                'appClass' => $appClass,
                'status' => $resolved === $appClass ? 'registered' : 'unregistered',
            ];
        }

        return $report;
    }

    private function stubFor(string $baseClass, string $short): string
    {
        $instance = new $baseClass;
        $fillable = $instance->getFillable();
        $hidden = method_exists($instance, 'getHidden') ? $instance->getHidden() : [];
        $casts = $this->declaredCasts($baseClass);
        $prefix = $this->prefixFor($instance);

        $alias = 'Base'.$short;
        $namespace = $this->modelNamespace();

        $imports = ['use Illuminate\\Database\\Eloquent\\Attributes\\Fillable;'];
        if ($hidden !== []) {
            $imports[] = 'use Illuminate\\Database\\Eloquent\\Attributes\\Hidden;';
        }
        $imports[] = "use {$baseClass} as {$alias};";
        sort($imports);

        $attributes = ['#[Fillable(['.$this->exportList($fillable).'])]'];
        if ($hidden !== []) {
            $attributes[] = '#[Hidden(['.$this->exportList($hidden).'])]';
        }

        $methods = [];
        if ($prefix !== null) {
            $methods[] = "    public function publicIdPrefix(): string\n".
                "    {\n".
                "        return '{$prefix}';\n".
                '    }';
        }
        if ($casts !== []) {
            $castLines = [];
            foreach ($casts as $key => $value) {
                $castLines[] = "            '{$key}' => '{$value}',";
            }
            $methods[] = "    protected function casts(): array\n".
                "    {\n".
                "        return [\n".
                implode("\n", $castLines)."\n".
                "        ];\n".
                '    }';
        }

        $body = $methods === [] ? '' : "\n".implode("\n\n", $methods)."\n";

        $editable = ['the fillable fields'];
        if ($prefix !== null) {
            $editable[] = 'public ID prefix';
        }
        if ($casts !== []) {
            $editable[] = 'casts';
        }

        return "<?php\n\n".
            "declare(strict_types=1);\n\n".
            "namespace {$namespace};\n\n".
            implode("\n", $imports)."\n\n".
            "/**\n".
            " * Published {$short} model. Extends the package base model; edit\n".
            ' * '.$this->humanJoin($editable)." here. Relationships, soft deletes,\n".
            " * events, and other behavior are inherited from the base model.\n".
            " */\n".
            implode("\n", $attributes)."\n".
            "class {$short} extends {$alias}\n".
            "{{$body}}\n";
    }

    /**
     * The casts the base model declares via its $casts property (not the
     * auto-added key cast), so the published casts() mirrors only real casts.
     *
     * @return array<string, string>
     */
    private function declaredCasts(string $baseClass): array
    {
        $casts = (new ReflectionClass($baseClass))->getDefaultProperties()['casts'] ?? [];

        return is_array($casts) ? $casts : [];
    }

    private function prefixFor(object $instance): ?string
    {
        if (! method_exists($instance, 'publicIdPrefix')) {
            return null;
        }

        try {
            $prefix = $instance->publicIdPrefix();
        } catch (Throwable) {
            return null;
        }

        return is_string($prefix) && $prefix !== '' ? $prefix : null;
    }

    /**
     * @param  array<int, string>  $items
     */
    private function exportList(array $items): string
    {
        return implode(', ', array_map(static fn (string $item): string => "'{$item}'", $items));
    }

    /**
     * @param  list<string>  $items
     */
    private function humanJoin(array $items): string
    {
        $count = count($items);
        if ($count === 1) {
            return $items[0];
        }
        if ($count === 2) {
            return $items[0].' and '.$items[1];
        }

        $last = array_pop($items);

        return implode(', ', $items).', and '.$last;
    }
}
