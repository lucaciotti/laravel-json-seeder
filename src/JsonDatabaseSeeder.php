<?php

namespace LucaCiotti\LaravelJsonSeeder;

use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LucaCiotti\LaravelJsonSeeder\Utils\SeederResult;
use LucaCiotti\LaravelJsonSeeder\Utils\SeederResultTable;
use JsonMachine\JsonMachine;
use JsonMachine\JsonDecoder\DecodingError;
use JsonMachine\JsonDecoder\ErrorWrappingDecoder;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

class JsonDatabaseSeeder extends Seeder
{
    protected $tableName;
    protected $configUseUpsert;
    protected $configDisableForeignKeyConstrain;
    protected $configIgnoreEmptyValues;

    /**
     * @var SeederResultTable
     */
    protected $SeederResultTable;

    public function run()
    {
        $env = App::environment();
        $this->command->line('<info>Environment:</info> ' . $env);

        $seedsDirectory = config('jsonseeder.directory', '/database/json');
        $absoluteSeedsDirectory = base_path($seedsDirectory);

        $this->configUseUpsert = config('jsonseeder.json-seed.use-upsert', true);
        $this->configDisableForeignKeyConstrain = config('jsonseeder.json-seed.disable-foreignKey-constraints', true);
        $this->configIgnoreEmptyValues = config('jsonseeder.json-seed.ignore-empty-values', true);

        if (!File::isDirectory($absoluteSeedsDirectory)) {
            $this->command->error('The directory ' . $seedsDirectory . ' was not found.');

            return false;
        }
        $this->command->line('<info>Database Connection:</info> ' . DB::connection()->getDatabaseName());
        $this->command->line('<info>Directory:</info> ' . $seedsDirectory);

        $jsonFiles = $this->getJsonFiles($absoluteSeedsDirectory);

        if (!$jsonFiles) {
            $this->command->warn('The directory ' . $seedsDirectory . ' has no JSON seeds.');
            $this->command->line('You can create seeds from you database by calling <info>php artisan jsonseeds:create</info>');

            return false;
        }

        $this->command->line('Found <info>' . count($jsonFiles) . ' JSON files</info> in <info>' . $seedsDirectory . '</info>');
        $this->SeederResultTable = new SeederResultTable();

        $this->seed($jsonFiles);

        return true;
    }

    public function seed(array $jsonFiles)
    {
        if ($this->configDisableForeignKeyConstrain) Schema::disableForeignKeyConstraints();

        foreach ($jsonFiles as $jsonFile) {
            $SeederResult = new SeederResult();
            $this->SeederResultTable->addRow($SeederResult);
            $startTimer = microtime(true);

            $filename = $jsonFile->getFilename();
            $tableName = Str::before($filename, '.json');
            $SeederResult->setFilename($filename);
            $SeederResult->setTable($tableName);

            $this->command->line('Seeding ' . $filename);

            if (!Schema::hasTable($tableName)) {
                $this->outputError(SeederResult::ERROR_NO_TABLE);

                $SeederResult->setStatusAborted();
                $SeederResult->setError(SeederResult::ERROR_NO_TABLE);
                $SeederResult->setTableStatus(SeederResult::TABLE_STATUS_NOT_FOUND);

                continue;
            }

            $SeederResult->setTableStatus(SeederResult::TABLE_STATUS_EXISTS);
            $tableColumns = DB::getSchemaBuilder()->getColumnListing($tableName);

            $filepath = $jsonFile->getRealPath();
            $fileSize = filesize($filepath);

            if (!$this->configUseUpsert) {
                $this->command->warn('Truncate table "' . $tableName . '".');
                DB::table($tableName)->truncate();
            }

            // we will use the "halaxa/json-machine" method --> https://github.com/halaxa/json-machine
            try {
                $jsonRows = JsonMachine::fromFile($filepath, '', new ErrorWrappingDecoder(new ExtJsonDecoder(true)));
                $bar = $this->command->getOutput()->createProgressBar(100);
                $bar->start();
                try {
                    foreach ($jsonRows as $key => $item) {
                        $bar->setProgress($jsonRows->getPosition() / $fileSize * 100);

                        if (!empty($item)) {
                            $this->compareJsonWithTableColumns($item, $tableColumns, $SeederResult);
                            $data = Arr::only($item, $tableColumns);
                            if ($this->configIgnoreEmptyValues) {
                                $data = array_filter($data, fn ($value) => !is_null($value) && $value !== '');
                            } else {
                                //replace empty array values with null in date type
                                array_walk($data, function (&$value, $key) {
                                    if (stripos($key, 'data') !== false || stripos($key, 'date') !==false || stripos($key, 'dt') == 0) {
                                        $value = $value === "" ? NULL : $value;
                                    } else {
                                        $value = $value;
                                    }
                                });
                                // $data = array_map(function ($value) {
                                //     return $value === "" ? NULL : $value;
                                // }, $data);
                            }

                            try {
                                if ($this->configUseUpsert) {
                                    DB::table($tableName)->upsert($data, '');
                                } else {
                                    DB::table($tableName)->insert($data);
                                }
                                $SeederResult->addRow();
                                $SeederResult->setStatusSucceeded();
                            } catch (\Exception $e) {
                                $this->outputError(SeederResult::ERROR_EXCEPTION);
                                $SeederResult->setError(SeederResult::ERROR_EXCEPTION);
                                $SeederResult->setTableStatus(SeederResult::ERROR_EXCEPTION);
                                $SeederResult->setStatusAborted();
                                Log::warning($e->getMessage());
                                break;
                            }
                        }
                    }
                    $bar->finish();
                    $timeElapsedSecs = microtime(true) - $startTimer;
                    $this->outputInfo('Seeding successful! [in ' . round($timeElapsedSecs, 3) . 'sec]');
                } catch (DecodingError $e) {
                    $this->outputError(SeederResult::ERROR_SYNTAX_INVALID);
                    $SeederResult->setError(SeederResult::ERROR_SYNTAX_INVALID);
                    $SeederResult->setStatusAborted();
                    Log::warning($e->getErrorMessage());
                    continue;
                }
            } catch (\Exception $e) {
                $this->outputError(SeederResult::ERROR_FILE_EMPTY);
                $SeederResult->setError(SeederResult::ERROR_FILE_EMPTY);
                $SeederResult->setTableStatus(SeederResult::ERROR_FILE_EMPTY);
                $SeederResult->setStatusAborted();
                Log::warning($e->getMessage());
                continue;
            }
            $jsonRows = null;
        }

        if ($this->configDisableForeignKeyConstrain) Schema::enableForeignKeyConstraints();

        $this->command->line('');
        $this->command->table($this->SeederResultTable->getHeader(), $this->SeederResultTable->getResult());
    }

    protected function getJsonFiles($seedsDirectory)
    {
        $files = File::files($seedsDirectory);

        $files = array_filter($files, static function ($filename) {
            return Str::endsWith($filename, 'json');
        });

        return array_values($files);
    }

    protected function compareJsonWithTableColumns(array $item, array $columns, SeederResult $SeederResult)
    {
        $diff = array_diff($columns, array_keys($item));

        if ($diff) {
            $SeederResult->setError(SeederResult::ERROR_FIELDS_MISSING . ' ' . implode(',', $diff));
        }

        $diff = array_diff(array_keys($item), $columns);

        if ($diff) {
            $SeederResult->setError(SeederResult::ERROR_FIELDS_UNKNOWN . ' ' . implode(',', $diff));
        }
    }

    protected function outputInfo(string $message)
    {
        $this->command->info(' > ' . $message);
    }

    protected function outputWarning(string $message)
    {
        $this->command->warn(' > ' . $message);
    }

    protected function outputError(string $message)
    {
        $this->command->error(' > ' . $message);
    }
}
