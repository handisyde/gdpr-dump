<?php

namespace machbarmacher\GdprDump;

use Ifsnop\Mysqldump\Mysqldump;
use machbarmacher\GdprDump\ColumnTransformer\ColumnTransformer;

class MysqldumpGdpr extends Mysqldump
{
    /** @var string */
    const FAKED_COLUMN_EXPRESSION = 'CASE WHEN NULLIF(`%s`, \'\') IS NULL THEN `%s` ELSE \'x\' END';

    /** @var [string][string]string */
    protected $gdprExpressions;

    /** @var [string][string]string */
    protected $gdprReplacements;

    /** @var bool */
    protected $debugSql;

    public function __construct(
        $dsn = '',
        $user = '',
        $pass = '',
        array $dumpSettings = [],
        array $pdoSettings = []
    ) {
        if (array_key_exists('gdpr-expressions', $dumpSettings)) {
            $this->gdprExpressions = $this->normalizeColumnsList($dumpSettings['gdpr-expressions']);
            unset($dumpSettings['gdpr-expressions']);
        } else {
            $this->gdprExpressions = [];
        }

        if (array_key_exists('gdpr-replacements', $dumpSettings)) {
            $this->gdprReplacements = $this->normalizeColumnsList($dumpSettings['gdpr-replacements']);
            unset($dumpSettings['gdpr-replacements']);
            // Normalize keys to avoid testing key existence later on
            foreach($this->gdprReplacements as $table => &$columns) {
                foreach($columns as $column => &$config) {
                    if(array_key_exists('keepEmpty', $config) === false) {
                        $config['keepEmpty'] = false;
                    }
                    if(array_key_exists('keepNull', $config) === false) {
                        $config['keepNull'] = false;
                    }
                    if(array_key_exists('args', $config) === false) {
                        $config['args'] = [];
                    }
                    // Avoid fetching data from faked columns as this is useless
                    if(array_key_exists($table, $this->gdprExpressions) == false) {
                        $this->gdprExpressions[$table] = [];
                    }
                    // Do not throw away if an existing replacement exists, this may
                    // allow further development where faked value is based on the
                    // existing one
                    if(array_key_exists($table, $this->gdprExpressions[$table]) == false) {
                        $this->gdprExpressions[$table][$column] = sprintf(self::FAKED_COLUMN_EXPRESSION, $column, $column);
                    }
                }
            }
        }

        if (array_key_exists('debug-sql', $dumpSettings)) {
            $this->debugSql = $dumpSettings['debug-sql'];
            unset($dumpSettings['debug-sql']);
        }

        if (array_key_exists('locale', $dumpSettings)) {
            ColumnTransformer::setLocale($dumpSettings['locale']);
            unset($dumpSettings['locale']);
        }

        parent::__construct($dsn, $user, $pass, $dumpSettings, $pdoSettings);
    }

    public function getColumnStmt($tableName)
    {
        $columnStmt = parent::getColumnStmt($tableName);
        $tableName = strtolower($tableName);

        if (!empty($this->gdprExpressions[$tableName])) {
            $columnTypes = $this->tableColumnTypes()[$tableName];
            foreach (array_keys($columnTypes) as $i => $columnName) {
                $columnKey = strtolower($columnName);
                if (!empty($this->gdprExpressions[$tableName][$columnKey])) {
                    $expression = $this->gdprExpressions[$tableName][$columnKey];
                    $columnStmt[$i] = "$expression as $columnName";
                }
            }
        }
        if ($this->debugSql) {
            print "/* SELECT " . implode(",",
                    $columnStmt) . " FROM `$tableName` */\n\n";
        }
        return $columnStmt;
    }

    protected function hookTransformColumnValue($tableName, $colName, $colValue, $row)
    {
        $tableName = strtolower($tableName);
        $colName = strtolower($colName);
        if (!empty($this->gdprReplacements[$tableName][$colName])) {
            $replacements = $this->gdprReplacements[$tableName][$colName];
            if(($colValue === null && $replacements['keepNull']) || ($colValue == '' && $replacements['keepEmpty'])) {
                return $colValue;
            }
            $replacement = ColumnTransformer::replaceValue($tableName, $colName, $this->gdprReplacements[$tableName][$colName]);
            if($replacement !== FALSE) {
                return $replacement;
            }
        }
        return $colValue;
    }

    private function normalizeColumnsList(&$tables) {
        $result = [];
        foreach($tables as $table => $columns) {
            $table = strtolower($table);
            $tmp = [];
            foreach($columns as $column => $conf) {
                $column = strtolower($column);
                $tmp[$column] = $conf;
            }
            $result[$table] = $tmp;
        }
        return $result;
    }
}
