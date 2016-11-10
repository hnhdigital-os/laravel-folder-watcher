# Laravel PHP-Elixir

[![Latest Stable Version](https://poser.pugx.org/bluora/laravel-folder-watcher/v/stable.svg)](https://packagist.org/packages/bluora/laravel-folder-watcher) [![Total Downloads](https://poser.pugx.org/bluora/laravel-folder-watcher/downloads.svg)](https://packagist.org/packages/bluora/laravel-folder-watcher) [![Latest Unstable Version](https://poser.pugx.org/bluora/laravel-folder-watcher/v/unstable.svg)](https://packagist.org/packages/bluora/laravel-folder-watcher) [![License](https://poser.pugx.org/bluora/laravel-folder-watcher/license.svg)](https://packagist.org/packages/bluora/laravel-folder-watcher)

[![Build Status](https://travis-ci.org/bluora/laravel-folder-watcher.svg?branch=master)](https://travis-ci.org/bluora/laravel-folder-watcher) [![StyleCI](https://styleci.io/repos/x/shield?branch=master)](https://styleci.io/repos/x) [![Test Coverage](https://codeclimate.com/github/bluora/laravel-folder-watcher/badges/coverage.svg)](https://codeclimate.com/github/bluora/laravel-folder-watcher/coverage) [![Issue Count](https://codeclimate.com/github/bluora/laravel-folder-watcher/badges/issue_count.svg)](https://codeclimate.com/github/bluora/laravel-folder-watcher) [![Code Climate](https://codeclimate.com/github/bluora/laravel-folder-watcher/badges/gpa.svg)](https://codeclimate.com/github/bluora/laravel-folder-watcher) 

Provides a Laravel console command that can watch a given folder, and any changes are passed to the provided command script.

Useful for running as a background task that initiates a virus scan on uploaded files.

## Installation

Install via composer:

`composer require-dev bluora/laravel-folder-watcher dev-master`

Add it to your available console commands in app/Console/Kernel.php:

```php
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
      ...
      \Bluora\LaravelFolderWatcher\FolderWatcherCommand::class,
    ];
```
