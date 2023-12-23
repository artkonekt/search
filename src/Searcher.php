<?php

declare(strict_types=1);

namespace Konekt\Search;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\ForwardsCalls;
use Konekt\Search\Contracts\SearchDialect;
use Konekt\Search\Dialects\MySQLDialect;
use Konekt\Search\Dialects\PostgresDialect;
use Konekt\Search\Dialects\SqliteDialect;
use Konekt\Search\Exceptions\OrderByRelevanceException;
use Konekt\Search\Exceptions\UnsupportedDatabaseEngineException;
use Konekt\Search\Exceptions\UnsupportedOperationException;

class Searcher
{
    use Conditionable;
    use ForwardsCalls;

    protected Collection $modelsToSearchThrough;

    protected string $orderByDirection;

    protected ?array $orderByModel = null;

    protected bool $beginWithWildcard = false;

    protected bool $endWithWildcard = true;

    protected bool $soundsLike = false;

    protected bool $ignoreCase = false;

    protected ?string $rawTerms = null;

    protected Collection $terms;

    protected Collection $termsWithoutWildcards;

    protected int $perPage = 15;

    protected string $pageName = '';

    protected bool $parseTerm = true;

    protected bool $simplePaginate = false;

    protected ?int $page = null;

    protected ?string $includeModelTypeWithKey = null;

    private SearchDialect $dialect;

    public function __construct()
    {
        $this->modelsToSearchThrough = new Collection();
        $this->dialect = $this->obtainDialect();
        $this->orderByAsc();
    }

    /**
     * Handle dynamic method calls into the ModelToSearchThrough instances.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return static
     */
    public function __call($method, $parameters)
    {
        $this->modelsToSearchThrough->each(fn(ModelToSearchThrough $modelToSearchThrough) => 
            $this->forwardCallTo($modelToSearchThrough, $method, $parameters)
        );
        return $this;
    }

    public function isCaseInsensitive(): bool
    {
        return $this->ignoreCase;
    }

    public function getSearchTerms(): Collection
    {
        return $this->terms;
    }

    public function orderByAsc(): self
    {
        $this->orderByDirection = 'asc';

        return $this;
    }

    public function orderByDesc(): self
    {
        $this->orderByDirection = 'desc';

        return $this;
    }

    public function orderByRelevance(): self
    {
        $this->orderByDirection = 'relevance';

        return $this;
    }

    public function orderByModel($modelClasses): self
    {
        if (!$this->dialect->supportsOrderByModel()) {
            throw new UnsupportedOperationException('Full text search is not supported on ' . $this->dialect->getName());
        }

        $this->orderByModel = Arr::wrap($modelClasses);

        return $this;
    }

    public function dontParseTerm(): self
    {
        $this->parseTerm = false;

        return $this;
    }

    public function includeModelType(string $key = 'type'): self
    {
        $this->includeModelTypeWithKey = $key;

        return $this;
    }

    public function add(Builder|string $query, string|array|Collection $columns = null, string $orderByColumn = null): self
    {
        // @todo add class_exists verification if $query is a string
        /** @var Builder $builder */
        $builder = is_string($query) ? $query::query() : $query;

        if (is_null($orderByColumn)) {
            $model = $builder->getModel();

            $orderByColumn = $model->usesTimestamps()
                ? $model->getUpdatedAtColumn()
                : $model->getKeyName();
        }

        $modelToSearchThrough = new ModelToSearchThrough(
            $builder,
            Collection::wrap($columns),
            $orderByColumn,
            $this->modelsToSearchThrough->count(),
        );

        $this->modelsToSearchThrough->push($modelToSearchThrough);

        return $this;
    }

    public function addFullText(Builder|string $query, string|array|Collection $columns = null, array $options = [], string $orderByColumn = null): self
    {
        if (!$this->dialect->supportsFullTextSearch()) {
            throw new UnsupportedOperationException('Full text search is not supported on ' . $this->dialect->getName());
        }
        // @todo add class_exists verification if $query is a string
        $builder = is_string($query) ? $query::query() : $query;

        $modelToSearchThrough = new ModelToSearchThrough(
            $builder,
            Collection::wrap($columns),
            $orderByColumn ?: $builder->getModel()->getUpdatedAtColumn(),
            $this->modelsToSearchThrough->count(),
            true,
            $options
        );

        $this->modelsToSearchThrough->push($modelToSearchThrough);

        return $this;
    }

    public function addMany($queries): self
    {
        Collection::make($queries)->each(function ($query) {
            $this->add(...$query);
        });

        return $this;
    }

    public function orderBy(string $orderByColumn): self
    {
        $this->modelsToSearchThrough->last()->orderByColumn($orderByColumn);

        return $this;
    }

    public function ignoreCase(bool $state = true): self
    {
        $this->ignoreCase = $state;

        return $this;
    }

    public function beginWithWildcard(bool $state = true): self
    {
        $this->beginWithWildcard = $state;

        return $this;
    }

    public function endWithWildcard(bool $state = true): self
    {
        $this->endWithWildcard = $state;

        return $this;
    }

    public function soundsLike(bool $state = true): self
    {
        $state ? $this->dialect->useSoundex() : $this->dialect->avoidSoundex();
        $this->soundsLike = $state;

        return $this;
    }

    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): self
    {
        $this->page = $page ?: Paginator::resolveCurrentPage($pageName);
        $this->pageName = $pageName;
        $this->perPage = $perPage;
        $this->simplePaginate = false;

        return $this;
    }

    public function simplePaginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): self
    {
        $this->paginate($perPage, $pageName, $page);
        $this->simplePaginate = true;

        return $this;
    }

    public function parseTerms(string $terms, callable $callback = null): Collection
    {
        $callback = $callback ?: fn () => null;

        return Collection::make(str_getcsv($terms, ' ', '"'))
            ->filter()
            ->values()
            ->when(null !== $callback, function ($terms) use ($callback) {
                return $terms->each(fn ($value, $key) => $callback($value, $key));
            });
    }

    public function addSearchQueryToBuilder(Builder $builder, ModelToSearchThrough $modelToSearchThrough): void
    {
        if ($this->termsWithoutWildcards->isEmpty()) {
            return;
        }

        $builder->where(function (Builder $query) use ($modelToSearchThrough) {
            if (!$modelToSearchThrough->isFullTextSearch()) {
                return $modelToSearchThrough->getColumns()->each(function ($column) use ($query, $modelToSearchThrough) {
                    Str::contains($column, '.')
                        ? $this->addNestedRelationToQuery($query, $column)
                        : $this->addWhereTermsToQuery($query, $modelToSearchThrough->qualifyColumn($column));
                });
            }

            $modelToSearchThrough
                ->toGroupedCollection()
                ->each(function (ModelToSearchThrough $modelToSearchThrough) use ($query) {
                    if ($relation = $modelToSearchThrough->getFullTextRelation()) {
                        $query->orWhereHas($relation, function ($relationQuery) use ($modelToSearchThrough) {
                            $relationQuery->where(function ($query) use ($modelToSearchThrough) {
                                $query->orWhereFullText(
                                    $modelToSearchThrough->getColumns()->all(),
                                    $this->rawTerms,
                                    $modelToSearchThrough->getFullTextOptions()
                                );
                            });
                        });
                    } else {
                        $query->orWhereFullText(
                            $modelToSearchThrough->getColumns()->map(fn ($column) => $modelToSearchThrough->qualifyColumn($column))->all(),
                            $this->rawTerms,
                            $modelToSearchThrough->getFullTextOptions()
                        );
                    }
                });
        });
    }

    public function count(string $terms = null): int
    {
        $this->initializeTerms($terms ?: '');

        return $this->getCompiledQueryBuilder()->count();
    }

    /**
     * Initialize the search terms, execute the search query and retrieve all
     * models per type. Map the results to a Eloquent collection and set
     * the collection on the paginator (whenever used).
     */
    public function search(string $terms = null): Collection|LengthAwarePaginator|PaginatorContract
    {
        $this->initializeTerms($terms ?: '');

        $results = $this->getIdAndOrderAttributes();

        $modelsPerType = $this->getModelsPerType($results);

        // loop over the results again and replace the object with the related model
        return $results->map(function ($item) use ($modelsPerType) {
            // from this set, pick '0_post_key'
            //
            // [
            //     "0_post_key": 1
            //     "0_post_order": "2020-07-08 19:51:08"
            //     "1_video_key": null
            //     "1_video_order": null
            // ]

            $modelKey = Collection::make($item)->search(function ($value, $key) {
                return $value && Str::endsWith($key, '_key');
            });

            /** @var Model $model */
            $model = $modelsPerType->get($modelKey)->get($item->$modelKey);

            if ($this->includeModelTypeWithKey) {
                $searchType = method_exists($model, 'searchType') ? $model->searchType() : class_basename($model);

                $model->setAttribute($this->includeModelTypeWithKey, $searchType);
            }

            return $model;
        })
            ->pipe(fn (Collection $models) => new EloquentCollection($models))
            ->when($this->pageName, fn (EloquentCollection $models) => $results->setCollection($models));
    }

    public function makeSelects(ModelToSearchThrough $currentModel): array
    {
        return $this->modelsToSearchThrough->flatMap(function (ModelToSearchThrough $modelToSearchThrough) use ($currentModel) {
            $qualifiedKeyName = $qualifiedOrderByColumnName = $modelOrderKey = 'null';

            if ($modelToSearchThrough === $currentModel) {
                $prefix = $modelToSearchThrough->getModel()->getConnection()->getTablePrefix();

                $qualifiedKeyName = $prefix . $modelToSearchThrough->getQualifiedKeyName();
                $qualifiedOrderByColumnName = $prefix . $modelToSearchThrough->getQualifiedOrderByColumnName();

                if ($this->orderByModel) {
                    $modelOrderKey = array_search(
                        get_class($modelToSearchThrough->getModel()),
                        $this->orderByModel ?: []
                    );

                    if (false === $modelOrderKey) {
                        $modelOrderKey = count($this->orderByModel);
                    }
                }
            }

            return array_filter([
                DB::raw("{$qualifiedKeyName} as {$modelToSearchThrough->getModelKey()}"),
                DB::raw("{$qualifiedOrderByColumnName} as {$modelToSearchThrough->getModelKey('order')}"),
                $this->orderByModel ? DB::raw("{$modelOrderKey} as {$modelToSearchThrough->getModelKey('model_order')}") : null,
            ]);
        })->all();
    }

    public function addRelevanceQueryToBuilder($builder, ModelToSearchThrough $modelToSearchThrough): void
    {
        if (!$this->isOrderingByRelevance() || $this->termsWithoutWildcards->isEmpty()) {
            return;
        }

        if (Str::contains($modelToSearchThrough->getColumns()->implode(''), '.')) {
            throw OrderByRelevanceException::new();
        }

        $expressionsAndBindings = $modelToSearchThrough->getQualifiedColumns()->flatMap(function ($field) use ($modelToSearchThrough, $builder) {
            $prefix = $modelToSearchThrough->getModel()->getConnection()->getTablePrefix();
            $field = $builder->getQuery()->getGrammar()->wrap($prefix . $field);

            return $this->termsWithoutWildcards->map(function ($term) use ($field) {
                return [
                    'expression' => sprintf('COALESCE(%1$s(LOWER(%2$s)) - %1$s(REPLACE(LOWER(%2$s), ?, ?)), 0)', $this->dialect->charLengthFunction(), $field),
                    'bindings' => [Str::lower($term), Str::substr(Str::lower($term), 1)],
                ];
            });
        });

        $selects = $expressionsAndBindings->map->expression->implode(' + ');
        $bindings = $expressionsAndBindings->flatMap->bindings->all();

        $builder->selectRaw("{$selects} as terms_count", $bindings);
    }

    protected function initializeTerms(string $terms): self
    {
        $this->rawTerms = $terms;

        $terms = $this->parseTerm ? $this->parseTerms($terms) : $terms;

        $this->termsWithoutWildcards = Collection::wrap($terms)->filter()->map(function ($term) {
            return $this->ignoreCase ? Str::lower($term) : $term;
        });

        $this->terms = Collection::make($this->termsWithoutWildcards)->unless($this->soundsLike, function ($terms) {
            return $terms->map(function ($term) {
                return implode('', [
                    $this->beginWithWildcard ? '%' : '',
                    $term,
                    $this->endWithWildcard ? '%' : '',
                ]);
            });
        });

        return $this;
    }

    /**
     * Implodes the qualified order keys with a comma and wraps them in a COALESCE method.
     */
    protected function makeOrderBy(): string
    {
        return $this->dialect->makeCoalesce(
            $this->modelsToSearchThrough->map->getModelKey('order')
        );
    }

    /**
     * Implodes the qualified orderByModel keys with a comma and wraps them in a COALESCE method.
     */
    protected function makeOrderByModel(): string
    {
        return $this->dialect->makeCoalesce(
            $this->modelsToSearchThrough->map->getModelKey('model_order')
        );
    }

    protected function buildQueries(): Collection
    {
        return $this->dialect->buildQueries($this->modelsToSearchThrough);
    }

    /**
     * Compiles all queries to one big one which binds everything together using UNION statements.
     */
    protected function getCompiledQueryBuilder(): QueryBuilder
    {
        $queries = $this->buildQueries();

        // take the first query

        /** @var BaseBuilder $firstQuery */
        $firstQuery = $queries->shift()->toBase();

        // union the other queries together
        $queries->each(fn (Builder $query) => $firstQuery->union($query));

        if ($this->orderByModel) {
            $firstQuery->orderBy(
                DB::raw($this->makeOrderByModel()),
                $this->isOrderingByRelevance() ? 'asc' : $this->orderByDirection
            );
        }

        $firstQuery = DB::table($firstQuery, 't1');

        if ($this->isOrderingByRelevance() && $this->termsWithoutWildcards->isNotEmpty()) {
            return $firstQuery->orderBy('terms_count', 'desc');
        }

        // sort by the given columns and direction
        return $firstQuery->orderBy(
            DB::raw($this->makeOrderBy()),
            $this->isOrderingByRelevance() ? 'asc' : $this->orderByDirection
        );
    }

    protected function getIdAndOrderAttributes(): Collection|LengthAwarePaginator|PaginatorContract
    {
        $query = $this->getCompiledQueryBuilder();

        // Determine the pagination method to call on Eloquent\Builder
        $paginateMethod = $this->simplePaginate ? 'simplePaginate' : 'paginate';

        // get all results or limit the results by pagination
        return $this->pageName
            ? $query->{$paginateMethod}($this->perPage, ['*'], $this->pageName, $this->page)
            : $query->get();

        // the collection will be something like:
        //
        // [
        //     [
        //         "0_post_key": null
        //         "0_post_order": null
        //         "1_video_key": 3
        //         "1_video_order": "2020-07-07 19:51:08"
        //     ],
        //     [
        //         "0_post_key": 1
        //         "0_post_order": "2020-07-08 19:51:08"
        //         "1_video_key": null
        //         "1_video_order": null
        //     ]
        // ]
    }

    protected function getModelsPerType(Collection|LengthAwarePaginator|PaginatorContract $results): Collection
    {
        return $this->modelsToSearchThrough
            ->keyBy->getModelKey()
            ->map(function (ModelToSearchThrough $modelToSearchThrough, $key) use ($results) {
                $ids = $results->pluck($key)->filter();

                return $ids->isNotEmpty()
                    ? $modelToSearchThrough->getModel()->newQueryWithoutScopes()->whereKey($ids)->get()->keyBy->getKey()
                    : null;
            });

        // the collection will be something like:
        //
        // [
        //     "0_post_key" => [
        //         1 => PostModel
        //     ],
        //     "1_video_key" => [
        //         3 => VideoModel
        //     ],
        // ]
    }

    private function addNestedRelationToQuery(Builder $query, string $nestedRelationAndColumn): void
    {
        $segments = explode('.', $nestedRelationAndColumn);

        $column = array_pop($segments);

        $relation = implode('.', $segments);

        $query->orWhereHas($relation, function ($relationQuery) use ($column) {
            $relationQuery->where(
                fn ($query) => $this->addWhereTermsToQuery($query, $query->qualifyColumn($column))
            );
        });
    }

    private function addWhereTermsToQuery(Builder $query, array|string $column): void
    {
        $this->dialect->addWhereTermsToQuery($query, $column);
    }

    private function isOrderingByRelevance(): bool
    {
        return 'relevance' === $this->orderByDirection;
    }

    private function obtainDialect(): SearchDialect
    {
        return match (DB::connection()->getDriverName()) {
            'mysql' => new MySQLDialect($this),
            'pgsql' => new PostgresDialect($this),
            'sqlite' => new SqliteDialect($this),
            default => throw new UnsupportedDatabaseEngineException(DB::connection()->getDriverName()),
        };
    }
}
