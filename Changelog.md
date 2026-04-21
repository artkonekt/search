# Konekt Search Changelog

## Unreleased
#### 2026-XX-YY

- Added Laravel 13 support
- Added PHP 8.5 support
- Dropped PHP 8.1 support
- Dropped Laravel 10 support
- Changed the minimum Laravel version requirements to v11.46.2, v12.50 and v13.0


## 1.5.0
#### 2025-03-03

- Added Laravel 12 support
- Added PHP 8.4 support
- Dropped PHP 8.0 support
- Dropped Laravel 9 support

## 1.4.0
#### 2024-04-25

- Added Carbon v3 support

## 1.3.0
#### 2024-01-08

- Added Laravel 11 support (pre-release)
- Added PHPUnit 10 support (internal)

## 1.2.1
#### 2023-11-17

- Added PHP 8.3 support

## 1.2.0
#### 2023-04-04

- Added PHP 8.0 support (it was restricted to 8.1+ before)

## 1.1.0
#### 2023-04-04

- Added SQLite support
- Added explicit exceptions when doing unsupported operations on Postgres/Sqlite

## 1.0.0
#### 2023-04-03

- Fork of [protonemedia/laravel-cross-eloquent-search](https://github.com/protonemedia/laravel-cross-eloquent-search) at version [3.2.0](https://github.com/protonemedia/laravel-cross-eloquent-search/tree/3.2.0)
- Added limited PostgreSQL support:
  - Full text search doesn't work
  - Ordering by model type doesn't work
