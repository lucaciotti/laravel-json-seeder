
## INTRO

This is a modified version of the brilliant package created by [TimoKoerber](https://github.com/TimoKoerber/laravel-json-seeder).

In this version I changed the call to "json_decode" in the Seeder side with the package created by [Halaxa](https://github.com/halaxa/json-machine) "json-machine".
  - "json_decode" often causes Allowed Memory Size Exhausted, when it has to process large files (which can often happen in database seeding).
  - the "halaxa / json-machine" offers a very easy to use and memory efficient drop-in replacement for inefficient iteration of big JSON files

In addition, I added a few more configurations for the seeding procedure. 

# myChangelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


## [1.1.1] - 2021-04-16
### Fixed
- Improved "Exception -> File Empty" Read content to each file kept causing "Allowed Memory Size Exhausted"
- Inserted timer calculation for each seeding file 


## [1.1] - 2021-04-16
### First Release


------------------------------------------------------------------------------------------
![Laravel JSON Seeder](https://user-images.githubusercontent.com/65356688/86782944-fe5aa180-c05f-11ea-9267-1581c7f991e1.jpg)

## Laravel JSON Seeder

Create and use JSON files to seed your database in your Laravel applications. 

This package works both ways!

- Export your database into JSON files
- Use JSON files to seed your database

## Installation

**Require this package** with composer. It is recommended to only require the package for development.

```shell
composer require timokoerber/laravel-json-seeder --dev
```

Next you need to **publish** the config file and register the required commands with ...   

```shell
php artisan vendor:publish --provider="LucaCiotti\LaravelJsonSeeder\JsonSeederServiceProvider"
```

This will create the file `config/jsonseeder.php` where you can find the configurations.

Next add the JsonSeederServiceProvider to the `providers` array in `config/app.php` ...   

```php
// config/app.php

'providers' => [
    ...
    
    LucaCiotti\LaravelJsonSeeder\JsonSeederServiceProvider::class,
    
    ...
]
```

## Creating JSON seeds from database
![Laravel JSON Seeder - Creating JSON seeds from database](https://user-images.githubusercontent.com/65356688/86143845-3ceadc00-baf5-11ea-956f-d707b88d148c.gif)

Of course you can create the JSON files manually. But if you already have a good development database, you can easily export it into JSON seeds. 

You can create seeds for **every table in your database** by calling ...

```shell
php artisan jsonseeds:create
```
This will create one JSON file for watch table in your database (i.e. table *users* -> *users.json*, table *posts* -> *posts.json*, etc.). 

If you only want to create a seed of **one specific table** (i.e. `users`), call ...

```shell
php artisan jsonseeds:create users
```

Existing files **won't be overwritten** by default. If you call the command again, a **sub-directory will be created** and the JSON seeds will be stored there. 
If you want to **overwrite the existing seeds**, use the `overwrite` option like ...

```shell
php artisan jsonseeds:create users -o|--overwrite
```

or just **use the command** ...

```shell
php artisan jsonseeds:overwrite users
```

## Seeding

![Laravel JSON Seeder - Seeding](https://user-images.githubusercontent.com/65356688/86143769-23e22b00-baf5-11ea-90e6-0631a41d81c4.gif)

Go to your `databas/seeds/DatabaseSeeder.php` and add the JsonSeeder inside the `run()` method like this ...

```php
// database/seeds/DatabaseSeeder.php

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call(LucaCiotti\LaravelJsonSeeder\JsonDatabaseSeeder::class);
    }
}
```

You can now call the JSON Seeder with the **usual Artisan command** ...

```shell
php artisan db:seed
```

## Settings & Configurations

### Directory

By default your seeds will be written into or read from the directory `/database/json`. If you want a different directory, you can add the environment variable 
`JSON_SEEDS_DIRECTORY` in your `.env` file ...

```
# .env

JSON_SEEDS_DIRECTORY=database/json
```

### Ignoring tables

Some tables in your database might not require any seeds. 
If you want to ignore these tables, you can put them into the setting `ignore-tables` in the `/config.jsonseeder.php`

```php
// config/jsonseeder.php

'ignore-tables' => [
    'migrations',
    'failed_jobs',
    'password_resets',
]
```

If a table in your database is empty, the LaravelJsonSeeder will create a JSON file with an empty array by default. This might be useful if you want your seeds to truncate this table.
If you don't want this, you can change the setting `ignore-empty-tables` in `config/jsonseeder.php`, so no JSON seed will be created.

```php
// config/jsonseeder.php

'ignore-empty-tables' => true
```

> **Important!!!** Do not forget to clear the cache after editing the config file: `php artisan cache:clear`

### Environments

The environment variable `JSON_SEEDS_DIRECTORY` might be useful if you are using seeds in Unit Tests and want to use different seeds for this. 

```
- database
  - json
      - development
        - comapanies.json
        - users.json 
        - posts.json
      - testing
        - users.json
        - posts.json
```
#### Development
```
# .env

JSON_SEEDS_DIRECTORY=database/json/development
```
#### Testing
```xml
// phpunit.xml

<phpunit>
    <php>
        <env name="JSON_SEEDS_DIRECTORY" value="database/json/testing"/>
    </php>
</phpunit>
```

## Errors & Warnings

![jsonseeder-errors](https://user-images.githubusercontent.com/65356688/86142165-2e9bc080-baf3-11ea-99b8-9bef46cd61f2.gif)


| Error | Type | Solution |
| ------| -----| -------- |
| Table does not exist! | Error | The name of the JSON file does not match any table. Check the filename or create the table. |
| JSON syntax is invalid! | Error | The JSON text inside the file seems to be invalid. Check if the JSON format is correct.|
| Exception occured! Check logfile! | Error | There seems to be a problem with the Database. Check your system and your default logfile. |
| JSON file is empty! | Error | The JSON file is completely empty, which makes it useless. If it should truncate the table, provide an empty array `[]`. Otherwise delelte it.|
| JSON file has no rows! | Warning | The JSON file contains only an empty array `[]`. This results in a truncated table and might not be intended. |
| Missing fields! | Warning | At least one row in the JSON file is missing a field, that is present in the database table. Check for typos or provide it in the JSON file. |
| Unknown fields! | Warning | At least one row in the JSON file has a field that does not exist in the database. Check for typos or make sure to add it to the database table. |


## License

Copyright © Timo Körber

Laravel JSON Seeder is open-sourced software licensed under the [MIT license](LICENSE).
