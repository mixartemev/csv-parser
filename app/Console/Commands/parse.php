<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\UnreadableFileException;

class parse extends Command
{
    /**
     * Key map
     * @example db:field_name => is_unique // csv:field_name
     */
    const KEYS = [
        'id' => true,  // Identifier
        'name' => false, // Name
        'surname' => false, // Last Name
        'card_num' => true,  // Card
        'email' => false, // email
    ];
    const RULES = [
        'id' => 'required|integer', // Identifier
        'name' => 'required',         // Name
        'surname' => 'required',         // Last Name
        'card_num' => 'required',         // Card
        'email' => 'required|email',   // email
    ];
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'parse';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run parser';
    private $keys, $uniqueKeys;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->keys = array_keys(self::KEYS);
        $this->uniqueKeys = array_keys(array_filter(self::KEYS, function ($key) {
            return $key;
        }));
    }

    /**
     * Execute the console command.
     *
     * @return int
     * @throws FileNotFoundException
     * @throws UnreadableFileException
     */
    public function handle()
    {
        if ($handle = fopen(storage_path('app/public/test_data.csv'), "r")) {
            $line = 0;
            $report = $uniques = $dups = [];
            $newRows = new Collection();
            # File parsing
            while ($row = fgetcsv($handle)) {
                if ($line) { // not header
                    if (is_array($row) && count($row) == 5) { // row parsed correct
                        $row = array_combine($this->keys, $row); // set keys
                        if (($v = $this->validate($row, $uniques))->fails()) { // validation
                            $report['failed'][$line] = $v->getMessageBag()->all();
                            $f = $v->failed();
                            $dupFailkeys = array_keys(array_filter($f, function ($rule) {
                                return array_key_exists('NotIn', $rule);
                            }));
                            foreach ($dupFailkeys as $dupFailkey) {
                                $dups[$dupFailkey] [] = $row[$dupFailkey];
                            }
                        } else {
                            $newRows[$row['id']] = $row;
                            foreach ($this->uniqueKeys as $key) { // fill unique indexes
                                $uniques[$key][] = $row[$key];
                            }
                        }
                    } else { // file reject
                        $lineNum = count($newRows) + 1;
                        throw new UnreadableFileException("String {$lineNum} is not parsed");
                    }
                }
                $line++;
            }
            fclose($handle);

            $newRows = collect($newRows);
            foreach ($dups as $key => $vals) {
                $newRows = $newRows->whereNotIn($key, $vals);
            }

            # Unique validation
            // foreach ($this->uniqueKeys as $key) {
            //     if ($dup = $newRows->duplicates($key)->values()) {
            //         $newRows = $newRows->whereNotIn($key, $dup);
            //     }
            // }

            $newIds = $newRows->pluck('id');
            $oldActiveRowsQuery = User::withoutTrashed();
            $oldTrashedRowsQuery = User::onlyTrashed();
            $oldActiveIds = $oldActiveRowsQuery->pluck('id', 'id')->toArray();
            $oldTrashedIds = $oldTrashedRowsQuery->pluck('id', 'id')->toArray();
            $updateIds = $newIds->intersect($oldActiveIds)->all();
            $update = $newRows->intersectByKeys($oldActiveIds)->all();
            $restoreIds = $newIds->intersect($oldTrashedIds);
            $deleteIds = $newIds->diff($oldActiveIds);
            $deleted = (clone $oldActiveRowsQuery)->whereIn('id', $deleteIds)->delete();
            $restored = $oldTrashedRowsQuery->whereIn('id', $restoreIds)->update(['deleted_at' => null]);
            $updated = $oldActiveRowsQuery->whereIn('id', $updateIds)->forceDelete();
            $updated = User::insert($update);

            $fp = fopen('report.csv', 'w');

            foreach (['failed'] as $type) {
                fputcsv($fp, ['', $type]);
                foreach ($report[$type] as $lineNum => $errors) {
                    foreach ($errors as $error)
                        fputcsv($fp, [$lineNum, $error]);
                }
            }

            return (int)!$updated;
        } else {
            throw new FileNotFoundException('no file: test_data.csv');
        }
    }

    protected function validate(array $data, array $uniq)
    {
        return Validator::make($data, [
            'id' => [
                'required',
                'integer',
                Rule::notIn(@$uniq['id']),
            ],
            'name' => 'required',
            'surname' => 'required',
            'card_num' => [
                'required',
                Rule::notIn(@$uniq['card_num']),
            ],
            'email' => 'required|email',
        ]);
    }
}
