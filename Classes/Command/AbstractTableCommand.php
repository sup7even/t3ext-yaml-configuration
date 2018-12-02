<?php
declare(strict_types = 1);

namespace MaxServ\YamlConfiguration\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class AbstractTableCommand extends Command
{
    /**
     * Path to YAML configuration files within an extension
     */
    public const CONFIGURATION_DIRECTORY = 'Configuration/YamlConfiguration/';

    /**
     * Table into which is imported
     *
     * @var string
     */
    protected $table = '';
    /**
     * YAML file
     *
     * @var string
     */
    protected $file = '';

    /**
     * Cache of table column names
     *
     * @var array
     */
    protected $tableColumnCache = [];

    protected function configure(): void
    {
        $this
            ->addArgument(
                'table',
                InputArgument::REQUIRED,
                'The name of the table which you want to process'
            );
    }

    /**
     * Get column names of a table
     *
     * @return array
     */
    protected function getColumnNames(): array
    {
        $columnNames = [];
        $table = $this->table;
        if (isset($this->tableColumnCache[$table])) {
            return $this->tableColumnCache[$table];
        }
        $result = $this->queryBuilderForTable($table)
            ->select('*')
            ->from($table)
            ->execute()
            ->fetch();
        if ($result) {
            $columnNames = \array_keys($result);
            $this->tableColumnCache[$table] = $columnNames;
        } else {
            $result = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);
            $result = $result->getSchemaManager()->listTableColumns($table);
            foreach ($result as $columnName => $columnProperties) {
                $columnNames[] = $columnName;
            }
            $this->tableColumnCache[$table] = $columnNames;
        }

        return $columnNames;
    }

    protected function doMatchFieldsExists(array $matchFields, array $columnNames, SymfonyStyle $io): bool
    {
        $nonExisting = [];
        foreach ($matchFields as $matchField) {
            if (!\in_array($matchField, $columnNames, true)) {
                $nonExisting[] = $matchField;
            }
        }
        if (\count($nonExisting)) {
            $io->error(
                'Some matchFields do not exist: ' . implode(',', $matchFields) . '.'
            );
            exit(1);
        }
        return true;
    }

    /**
     * Find YAML configuration files in all active extensions of this TYPO3 instance
     *
     * @return array
     */
    protected function findYamlFiles(): array
    {
        $activePackages = GeneralUtility::makeInstance(PackageManager::class)->getActivePackages();
        $configurationFiles = [];
        foreach ($activePackages as $package) {
            if ($package->getPackageKey() === 'yaml-configuration' || $package->getPackageKey() === 'yaml_configuration') {
                continue;
            }
            if (!($package instanceof PackageInterface)) {
                continue;
            }
            $packagePath = $package->getPackagePath();
            if (!\is_dir($packagePath . self::CONFIGURATION_DIRECTORY)) {
                continue;
            }
            $collectedFiles = GeneralUtility::getFilesInDir(
                $packagePath . self::CONFIGURATION_DIRECTORY,
                'yaml,yml',
                true
            );
            if (!empty($collectedFiles)) {
                $configurationFiles[] = array_pop($collectedFiles);
            }
        }
        return $configurationFiles;
    }

    /**
     * Get data configuration from configuration (parsed YAML file)
     *
     *
     * @param $configuration
     * @param string $table
     * @return array
     */
    protected function getDataConfiguration($configuration, string $table): array
    {
        $records = [];
        if ($configuration !== null
            && count($configuration) === 1
            && isset($configuration['TYPO3']['Data'][$table])
            && \is_array($configuration['TYPO3']['Data'][$table])
        ) {
            $records = $configuration['TYPO3']['Data'][$table];
        }

        return $records;
    }

    /**
     * Update timestamp fields
     *
     * You can force adding timestamp fields by the fourth parameter
     *
     * @param array $row
     * @param array $columnNames
     * @param array $fields
     *
     * @param bool $forceField
     * @return array the updated record row
     */
    protected function updateTimeFields(
        array $row,
        array $columnNames,
        array $fields = ['crdate', 'tstamp'],
        bool $forceField = false
    ): array {
        foreach ($fields as $field) {
            if (\array_key_exists($field, $row) && \in_array($field, $columnNames, true)) {
                $row[$field] = time();
            } elseif ($forceField) {
                $row[$field] = time();
            }
        }

        return $row;
    }

    /**
     * Converts an array without keys in first level to a key-value paired array
     *
     * Example:
     *      [
     *           ['foo', 'bar'],
     *           ['muh', 'maeh']
     *      ]
     * to
     *      [
     *           'foo' => 'bar',
     *           'muh' => 'meah'
     *      ]
     *
     * @param array $array
     * @return array
     */
    protected function convertArrayToKeyValuePairArray(array $array): array
    {
        $newArray = [];
        foreach ($array as $arrayItem) {
            $newArray[$arrayItem[0]] = $arrayItem[1];
        }

        return $newArray;
    }

    /**
     * Database QueryBuilder for a table
     *
     * @param string $table
     * @return QueryBuilder
     */
    protected function queryBuilderForTable(string $table): QueryBuilder
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
    }

    /**
     * @param string $table
     */
    public function setTable(string $table): void
    {
        $this->table = preg_replace('/[^a-z0-9_]/', '', $table);
    }

}