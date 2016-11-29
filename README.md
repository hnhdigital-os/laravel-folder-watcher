# Laravel Folder Watcher

This package has been developed by H&H|Digital, an Australian botique developer. Visit us at [hnh.digital](http://hnh.digital).

[![Latest Stable Version](https://poser.pugx.org/bluora/laravel-folder-watcher/v/stable.svg)](https://packagist.org/packages/bluora/laravel-folder-watcher) [![Total Downloads](https://poser.pugx.org/bluora/laravel-folder-watcher/downloads.svg)](https://packagist.org/packages/bluora/laravel-folder-watcher) [![Latest Unstable Version](https://poser.pugx.org/bluora/laravel-folder-watcher/v/unstable.svg)](https://packagist.org/packages/bluora/laravel-folder-watcher) [![License](https://poser.pugx.org/bluora/laravel-folder-watcher/license.svg)](https://packagist.org/packages/bluora/laravel-folder-watcher)

[![Build Status](https://travis-ci.org/bluora/laravel-folder-watcher.svg?branch=master)](https://travis-ci.org/bluora/laravel-folder-watcher) [![StyleCI](https://styleci.io/repos/73382984/shield?branch=master)](https://styleci.io/repos/73382984) [![Test Coverage](https://codeclimate.com/github/bluora/laravel-folder-watcher/badges/coverage.svg)](https://codeclimate.com/github/bluora/laravel-folder-watcher/coverage) [![Issue Count](https://codeclimate.com/github/bluora/laravel-folder-watcher/badges/issue_count.svg)](https://codeclimate.com/github/bluora/laravel-folder-watcher) [![Code Climate](https://codeclimate.com/github/bluora/laravel-folder-watcher/badges/gpa.svg)](https://codeclimate.com/github/bluora/laravel-folder-watcher)

Provides a Laravel console command that can watch a given folder, and any changes are passed to the provided command script.

Useful for running as a background task that initiates a virus scan on uploaded files.

## Install

Via composer:

`$ composer require-dev bluora/laravel-folder-watcher dev-master`

Enable the console command by editing app/Console/Kernel.php:

```php
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
      ...
      \Bluora\LaravelFolderWatcher\FolderWatcherCommand::class,
      ...
    ];
```

## Usage

Run the console command using the following:

### Load

Load a given configuration file. This will load a background process for each folder/binary combination.

`# php artisan watcher load --config-file=***`

### Background

Loads a given watch path and binary as a background process.

`# php artisan watcher background --watch-path=*** --binary=*** --script-arguments=***`

### Run

Runs a given watch path and binary.

`# php artisan watcher run --watch-path=*** --binary=*** --script-arguments=***`

### List

Lists all the background watch processes currently active.

`# php artisan watcher list`

### Kill

Provide a process id (from the list action) to stop it running. Using --pid=all will stop all processes.

`# php artisan watcher kill --pid=***`

## Configuration file

You can provide any yaml based file as input to this command using the load action and the --config-file argument.

The yaml file is in the following format:

```yaml
[folder path]:
    - [binary]: [arguments]
```

* [folder path]: The directory that will be watched for changes. The watcher recursively adds all child folders.
* [binary]: The binary that we will run. This could be an absolute path or an alias. (eg php)
* [arguments]: The arguments that need to be given to the binary. Use the placeholders below to allow the watcher to pass this through.

### Command placeholders

* {{file-path}}: The absolute file path to the changed file.
* {{root-path}}: The base directory of the watcher.
* {{file-removed}}: Boolean (1 or 0) to indicate if the file was deleted.

## Contributing

Please see [CONTRIBUTING](https://github.com/bluora/laravel-folder-watcher/blob/master/CONTRIBUTING.md) for details.

## Credits

* [Rocco Howard](https://github.com/therocis)
* [All Contributors](https://github.com/bluora/laravel-folder-watcher/contributors)

## License

The MIT License (MIT). Please see [License File](https://github.com/bluora/laravel-folder-watcher/blob/master/LICENSE) for more information.
