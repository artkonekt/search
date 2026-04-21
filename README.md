# Multi-model Eloquent Search

[![Tests](https://img.shields.io/github/actions/workflow/status/artkonekt/search/tests.yml?branch=master&style=flat-square)](https://github.com/artkonekt/search/actions?query=workflow%3Atests)
[![Packagist version](https://img.shields.io/packagist/v/konekt/search.svg?style=flat-square)](https://packagist.org/packages/konekt/search)
[![Packagist downloads](https://img.shields.io/packagist/dt/konekt/search.svg?style=flat-square)](https://packagist.org/packages/konekt/search)
[![StyleCI](https://styleci.io/repos/942087226/shield?branch=master)](https://styleci.io/repos/942087226)
[![MIT Software License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE.md)


> This package is a fork of the wonderful [Laravel Cross Eloquent Search](https://github.com/protonemedia/laravel-cross-eloquent-search) package.  
> Consider [sponsoring the original author](https://github.com/sponsors/pascalbaljet) of this package.

This Laravel package allows you to search through multiple Eloquent models.
It supports sorting, pagination, scoped queries, eager load relationships,
and searching through single or multiple columns.

## Requirements

- PHP 8.0+
- MySQL 8.0 (all features)
- PostgreSQL 11+ (partial feature set)
- SQLite (partial feature set)
- Laravel 9.x, 10.x, 11.x, 12.x, 13.x

### Feature Matrix

| Feature                | MySQL 8 | PostgreSQL | SQLite  |
|------------------------|---------|------------|---------|
| Multi-model search     | ✔       | ✔          | ✔       |
| Multi-model pagination | ✔       | ✔          | ✔       |
| Search in JSON fields  | ✔       | ✔          | ✔       |
| Sounds like search     | ✔       | ✔          | ❌       |
| Full Text Search       | ✔       | ❌          | ❌       |
| Sort by Model Order    | ✔       | ❌          | ❌       |


## Features

- Search through one or more [Eloquent models](https://laravel.com/docs/master/eloquent).
- Support for cross-model [pagination](https://laravel.com/docs/master/pagination#introduction).
- Search through single or multiple columns.
- Search through (nested) relationships.
- Support for Full-Text Search, even through relationships.
- Order by (cross-model) columns or by relevance.
- Use [constraints](https://laravel.com/docs/master/eloquent#retrieving-models) and [scoped queries](https://laravel.com/docs/master/eloquent#query-scopes).
- [Eager load relationships](https://laravel.com/docs/master/eloquent-relationships#eager-loading) for each model.
- In-database [sorting](https://laravel.com/docs/master/queries#ordering-grouping-limit-and-offset) of the combined result.
- Zero third-party dependencies

## Installation

You can install the package via composer:

```bash
composer require konekt/search
```

- If you want to use the `soundsLike()` similarity search with PostgreSQL, then you need to run `CREATE EXTENSION pg_trgm;` once on the given database to enable the [Trigram Extension](https://www.postgresql.org/docs/current/pgtrgm.html).

## Usage

Start your search query by adding one or more models to search through. Call the `add` method with the model's class name and the column you want to search through. Then call the `search` method with the search term, and you'll get a `\Illuminate\Database\Eloquent\Collection` instance with the results.

The results are sorted in ascending order by the *updated* column by default. In most cases, this column is `updated_at`. If you've [customized](https://laravel.com/docs/master/eloquent#timestamps) your model's `UPDATED_AT` constant, or overwritten the `getUpdatedAtColumn` method, this package will use the customized column. If you don't use timestamps at all, it will use the primary key by default. Of course, you can [order by another column](#sorting) as well.

```php
use Konekt\Search\Facades\Search;

$results = Search::add(Post::class, 'title')
    ->add(Video::class, 'title')
    ->search('howto');
```

If you care about indentation, you can optionally use the `new` method on the facade:

```php
Search::new()
    ->add(Post::class, 'title')
    ->add(Video::class, 'title')
    ->search('howto');
```

There's also an `when` method to apply certain clauses based on another condition:

```php
Search::new()
    ->when($user->isVerified(), fn($search) => $search->add(Post::class, 'title'))
    ->when($user->isAdmin(), fn($search) => $search->add(Video::class, 'title'))
    ->search('howto');
```

### Wildcards

By default, we split up the search term, and each keyword will get a wildcard symbol to do partial matching. Practically this means the search term `apple ios` will result in `apple%` and `ios%`. If you want a wildcard symbol to begin with as well, you can call the `beginWithWildcard` method. This will result in `%apple%` and `%ios%`.

```php
Search::add(Post::class, 'title')
    ->add(Video::class, 'title')
    ->beginWithWildcard()
    ->search('os');
```

If you want to disable the behaviour where a wildcard is appended to the terms, you should call the `endWithWildcard` method with `false`:

```php
Search::add(Post::class, 'title')
    ->add(Video::class, 'title')
    ->beginWithWildcard()
    ->endWithWildcard(false)
    ->search('os');
```

### Multi-word search

Multi-word search is supported out of the box. Simply wrap your phrase into double-quotes.

```php
Search::add(Post::class, 'title')
    ->add(Video::class, 'title')
    ->search('"macos big sur"');
```

You can disable the parsing of the search term by calling the `dontParseTerm` method, which gives you the same results as using double-quotes.

```php
Search::add(Post::class, 'title')
    ->add(Video::class, 'title')
    ->dontParseTerm()
    ->search('macos big sur');
```

### Sorting

If you want to sort the results by another column, you can pass that column to the `add` method as a third parameter. Call the `orderByDesc` method to sort the results in descending order.

```php
Search::add(Post::class, 'title', 'published_at')
    ->add(Video::class, 'title', 'released_at')
    ->orderByDesc()
    ->search('learn');
```

You can call the `orderByRelevance` method to sort the results by the number of occurrences of the search terms. Imagine these two sentences:

* Apple introduces iPhone 13 and iPhone 13 mini
* Apple unveils new iPad mini with breakthrough performance in stunning new design

If you search for *Apple iPad*, the second sentence will come up first, as there are more matches of the search terms.

```php
Search::add(Post::class, 'title')
    ->beginWithWildcard()
    ->orderByRelevance()
    ->search('Apple iPad');
```

Ordering by relevance is *not* supported if you're searching through (nested) relationships.

To sort the results by model type, you can use the `orderByModel` method by giving it your preferred order of the models:

```php
Search::new()
    ->add(Comment::class, ['body'])
    ->add(Post::class, ['title'])
    ->add(Video::class, ['title', 'description'])
    ->orderByModel([
        Post::class, Video::class, Comment::class,
    ])
    ->search('Artisan School');
```

### Pagination

We highly recommend paginating your results. Call the `paginate` method before the `search` method, and you'll get an instance of `\Illuminate\Contracts\Pagination\LengthAwarePaginator` as a result. The `paginate` method takes three (optional) parameters to customize the paginator. These arguments are [the same](https://laravel.com/docs/master/pagination#introduction) as Laravel's database paginator.

```php
Search::add(Post::class, 'title')
    ->add(Video::class, 'title')

    ->paginate()
    // or
    ->paginate($perPage = 15, $pageName = 'page', $page = 1)

    ->search('build');
```

You may also use [simple pagination](https://laravel.com/docs/master/pagination#simple-pagination). This will return an instance of `\Illuminate\Contracts\Pagination\Paginator`, which is not length aware:

```php
Search::add(Post::class, 'title')
    ->add(Video::class, 'title')

    ->simplePaginate()
    // or
    ->simplePaginate($perPage = 15, $pageName = 'page', $page = 1)

    ->search('build');
```

### Constraints and scoped queries

Instead of the class name, you can also pass an instance of the [Eloquent query builder](https://laravel.com/docs/master/eloquent#retrieving-models) to the `add` method. This allows you to add constraints to each model.

```php
Search::add(Post::published(), 'title')
    ->add(Video::where('views', '>', 2500), 'title')
    ->search('compile');
```

### Multiple columns per model

You can search through multiple columns by passing an array of columns as the second argument.

```php
Search::add(Post::class, ['title', 'body'])
    ->add(Video::class, ['title', 'subtitle'])
    ->search('eloquent');
```

### Search through (nested) relationships

You can search through (nested) relationships by using the *dot* notation:

```php
Search::add(Post::class, ['comments.body'])
    ->add(Video::class, ['posts.user.biography'])
    ->search('solution');
```

### Full-Text Search

You may use [MySQL's Full-Text Search](https://laravel.com/docs/master/queries#full-text-where-clauses) by using the `addFullText` method. You can search through a single or multiple columns (using [full text indexes](https://laravel.com/docs/master/migrations#available-index-types)), and you can specify a set of options, for example, to specify the mode. You can even mix regular and full-text searches in one query:

```php
Search::new()
    ->add(Post::class, 'title')
    ->addFullText(Video::class, 'title', ['mode' => 'boolean'])
    ->addFullText(Blog::class, ['title', 'subtitle', 'body'], ['mode' => 'boolean'])
    ->search('framework -css');
```

If you want to search through relationships, you need to pass in an array where the array key contains the relation, while the value is an array of columns:

```php
Search::new()
    ->addFullText(Page::class, [
        'posts' => ['title', 'body'],
        'sections' => ['title', 'subtitle', 'body'],
    ])
    ->search('framework -css');
```

### Sounds like

MySQL has a *soundex* algorithm built-in so you can search for terms that sound almost the same. You can use this feature by calling the `soundsLike` method:

```php
Search::new()
    ->add(Post::class, 'framework')
    ->add(Video::class, 'framework')
    ->soundsLike()
    ->search('larafel');
```

### Eager load relationships

Not much to explain here, but this is supported as well :)

```php
Search::add(Post::with('comments'), 'title')
    ->add(Video::with('likes'), 'title')
    ->search('guitar');
```

### Getting results without searching

You call the `search` method without a term or with an empty term. In this case, you can discard the second argument of the `add` method. With the `orderBy` method, you can set the column to sort by (previously the third argument):

```php
Search::add(Post::class)
    ->orderBy('published_at')
    ->add(Video::class)
    ->orderBy('released_at')
    ->search();
```

### Counting records

You can count the number of results with the `count` method:

```php
Search::add(Post::published(), 'title')
    ->add(Video::where('views', '>', 2500), 'title')
    ->count('compile');
```

### Model Identifier

You can use the `includeModelType` to add the model type to the search result.

```php
Search::add(Post::class, 'title')
    ->add(Video::class, 'title')
    ->includeModelType()
    ->paginate()
    ->search('foo');

// Example result with model identifier.
{
    "current_page": 1,
    "data": [
        {
            "id": 1,
            "video_id": null,
            "title": "foo",
            "published_at": null,
            "created_at": "2021-12-03T09:39:10.000000Z",
            "updated_at": "2021-12-03T09:39:10.000000Z",
            "type": "Post",
        },
        {
            "id": 1,
            "title": "foo",
            "subtitle": null,
            "published_at": null,
            "created_at": "2021-12-03T09:39:10.000000Z",
            "updated_at": "2021-12-03T09:39:10.000000Z",
            "type": "Video",
        },
    ],
    ...
}
```

By default, it uses the `type` key, but you can customize this by passing the key to the method.

You can also customize the `type` value by adding a public method `searchType()` to your model to override the default class base name.

```php
class Video extends Model
{
    public function searchType()
    {
        return 'awesome_video';
    }
}

// Example result with searchType() method.
{
    "current_page": 1,
    "data": [
        {
            "id": 1,
            "video_id": null,
            "title": "foo",
            "published_at": null,
            "created_at": "2021-12-03T09:39:10.000000Z",
            "updated_at": "2021-12-03T09:39:10.000000Z",
            "type": "awesome_video",
        }
    ],
    ...
```

### Standalone parser

You can use the parser with the `parseTerms` method:

```php
$terms = Search::parseTerms('drums guitar');
```

You can also pass in a callback as a second argument to loop through each term:

```php
Search::parseTerms('drums guitar', function($term, $key) {
    //
});
```
