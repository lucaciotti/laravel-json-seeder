<?php

namespace TimoKoerber\LaravelJsonSeeder;

use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use TimoKoerber\LaravelJsonSeeder\Utils\SeederResult;
use TimoKoerber\LaravelJsonSeeder\Utils\SeederResultTable;
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
        $this->command->line('<info>Environment:</info> '.$env);

        $seedsDirectory = config('jsonseeder.directory', '/database/json');
        $absoluteSeedsDirectory = base_path($seedsDirectory);

        $this->configUseUpsert = config('jsonseeder.json-seed.use-upsert', true);
        $this->configDisableForeignKeyConstrain = config('jsonseeder.json-seed.disable-foreignKey-constraints', true);
        $this->configIgnoreEmptyValues = config('jsonseeder.json-seed.ignore-empty-values', true);

        if (! File::isDirectory($absoluteSeedsDirectory)) {
            $this->command->error('The directory '.$seedsDirectory.' was not found.');

            return false;
        }

        $this->command->line('<info>Directory:</info> '.$seedsDirectory);

        $jsonFiles = $this->getJsonFiles($absoluteSeedsDirectory);

        if (! $jsonFiles) {
            $this->command->warn('The directory '.$seedsDirectory.' has no JSON seeds.');
            $this->command->line('You can create seeds from you database by calling <info>php artisan jsonseeds:create</info>');

            return false;
        }

        $this->command->line('Found <info>'.count($jsonFiles).' JSON files</info> in <info>'.$seedsDirectory.'</info>');
        $this->SeederResultTable = new SeederResultTable();

        $this->seed($jsonFiles);

        return true;
    }

    public function seed(array $jsonFiles)
    {
        if($this->configDisableForeignKeyConstrain) Schema::disableForeignKeyConstraints();

        foreach ($jsonFiles as $jsonFile) {
            $SeederResult = new SeederResult();
            $this->SeederResultTable->addRow($SeederResult);

            $filename = $jsonFile->getFilename();
            $tableName = Str::before($filename, '.json');
            $SeederResult->setFilename($filename);
            $SeederResult->setTable($tableName);

            $this->command->line('Seeding '.$filename);

            if (! Schema::hasTable($tableName)) {
                $this->outputError(SeederResult::ERROR_NO_TABLE);

                $SeederResult->setStatusAborted();
                $SeederResult->setError(SeederResult::ERROR_NO_TABLE);
                $SeederResult->setTableStatus(SeederResult::TABLE_STATUS_NOT_FOUND);

                continue;
            }

            $SeederResult->setTableStatus(SeederResult::TABLE_STATUS_EXISTS);

            // move this here cause the forEach starts before with the "halaxa/json-machine"
            $tableColumns = DB::getSchemaBuilder()->getColumnListing($tableName);

            $filepath = $jsonFile->getRealPath();
            $content = File::get($filepath);
            $fileSize = filesize($filepath);

            // this often causes Allowed Memory Size Exhausted caused by inefficient iteration of big JSON files
            // $jsonArray = $this->getValidJsonString($content, $SeederResult);
            if (empty($content)) {
                $this->outputError(SeederResult::ERROR_FILE_EMPTY);
                $SeederResult->setError(SeederResult::ERROR_FILE_EMPTY);
                $SeederResult->setStatusAborted();

                return null;
            }


            if (!$this->configUseUpsert) DB::table($tableName)->truncate();

            $bar = $this->command->createProgressBar($fileSize);
            $bar->start();

            // we will use the "halaxa/json-machine" method --> https://github.com/halaxa/json-machine
            $jsonRows = JsonMachine::fromFile($content, '', new ErrorWrappingDecoder(new ExtJsonDecoder()));
            foreach ($jsonRows as $key => $item) {
                if ($key instanceof DecodingError || $item instanceof DecodingError) {
                    $this->outputError(SeederResult::ERROR_SYNTAX_INVALID);
                    $SeederResult->setError(SeederResult::ERROR_SYNTAX_INVALID);
                    $SeederResult->setStatusAborted();

                    return null;
                }
                $bar->advance();
                // $this->outputInfo('Progress : ' . intval($jsonRows->getPosition() / $fileSize * 100) . ' %');
                $this->compareJsonWithTableColumns($item, $tableColumns, $SeederResult);
                $data = Arr::only($item, $tableColumns);

                if ($this->configIgnoreEmptyValues) $data = array_filter($data, fn ($value) => !is_null($value) && $value !== '');

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
                    $SeederResult->setStatusAborted();
                    Log::warning($e->getMessage());
                    break;
                }

            }
            $bar->finish();
            $this->outputInfo('Seeding successful!');
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
            $SeederResult->setError(SeederResult::ERROR_FIELDS_MISSING.' '.implode(',', $diff));
        }

        $diff = array_diff(array_keys($item), $columns);

        if ($diff) {
            $SeederResult->setError(SeederResult::ERROR_FIELDS_UNKNOWN.' '.implode(',', $diff));
        }
    }

    protected function outputInfo(string $message)
    {
        $this->command->info(' > '.$message);
    }

    protected function outputWarning(string $message)
    {
        $this->command->warn(' > '.$message);
    }

    protected function outputError(string $message)
    {
        $this->command->error(' > '.$message);
    }
}
