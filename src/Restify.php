<?php

namespace Binaryk\LaravelRestify;

use Binaryk\LaravelRestify\Bootstrap\BootRepository;
use Binaryk\LaravelRestify\Events\RestifyBeforeEach;
use Binaryk\LaravelRestify\Events\RestifyStarting;
use Binaryk\LaravelRestify\Exceptions\RepositoryNotFoundException;
use Binaryk\LaravelRestify\Http\Requests\RestifyRequest;
use Binaryk\LaravelRestify\Models\ActionLog;
use Binaryk\LaravelRestify\Repositories\Repository;
use Binaryk\LaravelRestify\Traits\AuthorizesRequests;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Finder\Finder;

class Restify
{
    use AuthorizesRequests;

    /**
     * The registered repository names.
     *
     * @var array
     */
    public static $repositories = [];

    /**
     * The callback used to report Restify's exceptions.
     *
     * @var \Closure
     */
    public static $reportCallback;

    /**
     * The callback used to render Restify's exceptions.
     *
     * @var \Closure
     */
    public static $renderCallback;

    /**
     * Get the repository class name for a given key.
     *
     * @param  string  $key
     * @return string|null
     */
    public static function repositoryClassForKey(string $key): ?string
    {
        return collect(static::$repositories)->first(function ($value) use ($key) {
            return $value::uriKey() === $key;
        });
    }

    /**
     * Get the repository class for the prefix.
     *
     * @param  string  $prefix
     * @return string|null
     */
    public static function repositoryClassForPrefix(string $prefix): ?string
    {
        return collect(static::$repositories)->first(function ($value) use ($prefix) {
            /** @var Repository $value */
            return str_contains(
                ltrim($prefix, '/'),
                ltrim($value::route(), '/')
            );
        });
    }

    /**
     * Return the repository instance for a given key.
     *
     * @param  string  $key
     *
     * @throw RepositoryNotFoundException
     *
     * @return Repository
     */
    public static function repository(string $key): Repository
    {
        /**
         * @var Repository|string $repositoryClass
         */
        if (is_null($repositoryClass = static::repositoryClassForKey($key))) {
            throw RepositoryNotFoundException::make($key);
        }

        return $repositoryClass::isMock()
            ? $repositoryClass::getMock()
            : $repositoryClass::resolveWith($repositoryClass::newModel());
    }

    /**
     * Get the repository class name for a given model.
     *
     * @param  string  $model
     * @return string
     */
    public static function repositoryForModel($model)
    {
        return collect(static::$repositories)->first(function ($value) use ($model) {
            if ($model instanceof Model) {
                $model = get_class($model);
            }

            return $value::guessModelClassName() === $model;
        });
    }

    /**
     * Get the repository class name for a given table name.
     *
     * @param  string  $table
     * @return string
     */
    public static function repositoryForTable($table)
    {
        return collect(static::$repositories)->first(function ($value) use ($table) {
            return app($value::guessModelClassName())->getTable() === $table;
        });
    }

    /**
     * Register the given repositories.
     *
     * @param  array  $repositories
     * @return static
     */
    public static function repositories(array $repositories)
    {
        static::$repositories = array_unique(
            array_merge(static::$repositories, $repositories)
        );

        collect($repositories)->each(function (string $repository) {
            (new BootRepository($repository))->boot();
        });

        return new static();
    }

    /**
     * Register all of the repository classes in the given directory.
     *
     * @param  string  $directory
     * @return void
     *
     * @throws ReflectionException
     */
    public static function repositoriesFrom(string $directory): void
    {
        $namespace = app()->getNamespace();

        $repositories = [];

        if (! is_dir($directory)) {
            return;
        }

        foreach ((new Finder())->in($directory)->files() as $repository) {
            $repository = $namespace.str_replace(
                ['/', '.php'],
                ['\\', ''],
                Str::after($repository->getPathname(), app_path().DIRECTORY_SEPARATOR)
            );

            if (is_subclass_of(
                $repository,
                Repository::class
            ) && (new ReflectionClass($repository))->isInstantiable()) {
                $repositories[] = $repository;
            }
        }

        static::repositories(
            collect($repositories)->sort()->all()
        );
    }

    /**
     * Get the URI path prefix utilized by Restify.
     *
     * @param  null  $plus
     * @return string
     */
    public static function path($plus = null, array $query = [])
    {
        if (! is_null($plus)) {
            return empty($query)
                ? config('restify.base', '/restify-api').'/'.$plus
                : config('restify.base', '/restify-api').'/'.$plus.'?'.http_build_query($query);
        }

        return empty($query)
            ? config('restify.base', '/restify-api')
            : config('restify.base', '/restify-api').'?'.http_build_query($query);
    }

    /**
     * Register an event listener for the Restify "serving" event.
     *
     * This listener is added in the RestifyApplicationServiceProvider
     *
     * @param  \Closure|string  $callback
     * @return void
     */
    public static function starting($callback)
    {
        Event::listen(RestifyStarting::class, $callback);
    }

    /**
     * @param  \Closure|string  $callback
     */
    public static function beforeEach($callback)
    {
        Event::listen(RestifyBeforeEach::class, $callback);
    }

    /**
     * Set the callback used for intercepting any request exception.
     *
     * @param  \Closure|string  $callback
     */
    public static function exceptionHandler($callback)
    {
        static::$renderCallback = $callback;
    }

    public static function globallySearchableRepositories(RestifyRequest $request): array
    {
        return collect(static::$repositories)
            ->filter(fn ($repository) => $repository::authorizedToUseRepository($request))
            ->filter(fn ($repository) => $repository::$globallySearchable)
            ->sortBy(static::sortResourcesWith())
            ->all();
    }

    public static function sortResourcesWith()
    {
        return function ($resource) {
            return $resource::label();
        };
    }

    /**
     * Humanize the given value into a proper name.
     *
     * @param  string  $value
     * @return string
     */
    public static function humanize($value)
    {
        if (is_object($value)) {
            return static::humanize(class_basename(get_class($value)));
        }

        return Str::title(Str::snake($value, ' '));
    }

    public static function actionLog(): ActionLog
    {
        return static::actionRepository()::newModel();
    }

    public static function actionRepository(): Repository
    {
        return app(config('restify.logs.repository'));
    }

    public static function isRestify(Request $request): bool
    {
        $path = trim(static::path(), '/') ?: '/';

        return $request->is($path) ||
            $request->is(trim($path.'/*', '/')) ||
            $request->is('restify-api/*') ||
            collect(static::$repositories)
                ->filter(fn ($repository) => $repository::prefix())
                ->some(fn ($repository) => $request->is($repository::prefix().'/*'));
    }

    /**
     * @throws ReflectionException
     */
    public static function ensureRepositoriesLoaded(): void
    {
        if (empty(static::$repositories)) {
            static::repositoriesFrom(app_path('Restify'));
        }
    }
}
