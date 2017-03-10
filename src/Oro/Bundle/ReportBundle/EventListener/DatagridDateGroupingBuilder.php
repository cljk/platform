<?php

namespace Oro\Bundle\ReportBundle\EventListener;

use Oro\Bundle\DataGridBundle\Datagrid\Common\DatagridConfiguration;
use Oro\Bundle\DataGridBundle\Extension\Sorter\AbstractSorterExtension;
use Oro\Bundle\FilterBundle\Filter\DateGroupingFilter;
use Oro\Bundle\FilterBundle\Filter\SkipEmptyPeriodsFilter;
use Oro\Bundle\FilterBundle\Form\Type\Filter\DateGroupingFilterType;
use Oro\Bundle\QueryDesignerBundle\Form\Type\DateGroupingType;
use Oro\Bundle\QueryDesignerBundle\Model\AbstractQueryDesigner;
use Oro\Bundle\QueryDesignerBundle\QueryDesigner\JoinIdentifierHelper;
use Oro\Bundle\ReportBundle\Entity\Report;
use Oro\Bundle\ReportBundle\Exception\InvalidDatagridConfigException;

class DatagridDateGroupingBuilder
{
    const ACTIONS_KEY_NAME = 'actions';
    const FILTERS_KEY_NAME = 'filters';
    const SOURCE_KEY_NAME = 'source';
    const SORTERS_KEY_NAME = 'sorters';
    const PROPERTIES_KEY_NAME = 'properties';
    const FIELDS_ACL_KEY_NAME = 'fields_acl';
    const COLUMNS_KEY_NAME = 'columns';
    const CALENDAR_DATE_COLUMN_ALIAS = 'cDate';
    const CALENDAR_TABLE_JOIN_CONDITION_TEMPLATE = 'CAST(%s as DATE) = CAST(%s.%s as DATE)';
    const CALENDAR_DATE_GRID_COLUMN_NAME = 'dateGrouping';
    const DATE_PERIOD_FILTER = 'datePeriodFilter';
    const DEFAULT_GROUP_BY_FIELD = 'id';

    /**
     * @var string
     */
    protected $calendarDateClass;

    /**
     * @param string $calendarDateEntity
     * @param null $joinIdHelper
     */
    public function __construct($calendarDateEntity, $joinIdHelper = null)
    {
        $this->calendarDateClass = $calendarDateEntity;
        $this->joinIdHelper = $joinIdHelper;
    }

    /**
     * @param DatagridConfiguration $config
     * @param AbstractQueryDesigner $report
     * @throws InvalidDatagridConfigException
     */
    public function applyDateGroupingFilterIfRequired(DatagridConfiguration $config, AbstractQueryDesigner $report)
    {
        if (!$report instanceof Report) {
            return;
        }

        $reportDefinition = json_decode($report->getDefinition(), true);
        if (!$this->isDateGroupingFilterRequired($reportDefinition)) {
            return;
        }

        if (!$this->isGridConfigValid($config)) {
            throw new InvalidDatagridConfigException();
        }

        $joinHelper = $this->getJoinIdHelper($report->getEntity());
        $dateGroupDefinition = $reportDefinition[DateGroupingType::DATE_GROUPING_NAME];

        $dateFieldName = $joinHelper->getFieldName($dateGroupDefinition[DateGroupingType::FIELD_NAME_ID]);
        $dateFieldTableAlias = $this->getRealDateFieldTableAlias(
            $config,
            $joinHelper->explodeColumnName(
                $dateGroupDefinition[DateGroupingType::FIELD_NAME_ID]
            )
        );
        $notNullableField = $this->getNotNullableField($config);

        $this->changeFiltersSection(
            $config,
            $report->getEntity(),
            $dateFieldName,
            $dateFieldTableAlias,
            $notNullableField
        );
        $this->changeSourceSection($config, $dateFieldTableAlias, $dateFieldName);
        $this->changeSortersSection($config);
        $this->changeColumnsSection($config);
        $this->addEmptyPeriodsFilter(
            $config,
            $dateGroupDefinition[DateGroupingType::USE_SKIP_EMPTY_PERIODS_FILTER_ID],
            $notNullableField
        );
        $this->changeGroupBySection($config);
        $this->removeViewLink($config);
    }

    /**
     * @param DatagridConfiguration $config
     */
    protected function removeViewLink(DatagridConfiguration $config)
    {
        if (!$config->offsetExists(static::PROPERTIES_KEY_NAME)) {
            return;
        }
        $properties = $config->offsetGet(static::PROPERTIES_KEY_NAME);
        if (empty($properties)) {
            return;
        }

        if (array_key_exists('view_link', $properties)) {
            unset($properties['view_link']);
        }
        if (array_key_exists('id', $properties)) {
            unset($properties['id']);
        }

        $config->offsetSet(static::PROPERTIES_KEY_NAME, $properties);
    }

    /**
     * Adds required filter configuration with default values
     *
     * @param DatagridConfiguration $config
     * @param string $rootEntityClass
     * @param string $dateFieldName
     * @param $dateFieldTableAlias
     * @param string $notNullableField
     * @param string $defaultFilterValue
     * @internal param string $dateFieldTableAlias
     * @internal param string $dateGroupingWithAlias
     */
    protected function changeFiltersSection(
        DatagridConfiguration $config,
        $rootEntityClass,
        $dateFieldName,
        $dateFieldTableAlias,
        $notNullableField,
        $defaultFilterValue = DateGroupingFilterType::TYPE_DAY
    ) {
        $filters = $config->offsetGet(static::FILTERS_KEY_NAME);

        $filters['columns'][DateGroupingFilter::NAME] = [
            'type' => DateGroupingFilter::NAME,
            'data_name' => $this->getCalendarDateFieldReferenceString(),
            'label' => 'oro.report.filter.grouping.label',
            'column_name' => static::CALENDAR_DATE_GRID_COLUMN_NAME,
            'calendar_entity' => $this->calendarDateClass,
            'target_entity' => $rootEntityClass,
            'not_nullable_field' => $notNullableField,
            'joined_column' => $dateFieldName,
            'joined_table' => $dateFieldTableAlias,
        ];
        if (!array_key_exists(static::DATE_PERIOD_FILTER, $filters['columns'])) {
            $filters['columns'][static::DATE_PERIOD_FILTER] = [
                'label' => 'oro.report.datagrid.column.time_period.label',
                'type' => 'datetime',
                'data_name' => $this->getCalendarDateFieldReferenceString(),
            ];
        }

        if (!array_key_exists('default', $filters)) {
            $filters['default'] = [];
        }
        $filters['default'][DateGroupingFilter::NAME] = ['value' => $defaultFilterValue];

        $config->offsetSet(static::FILTERS_KEY_NAME, $filters);
    }

    /**
     * Checks if skipEmptyPeriods filter should be used and configures it
     *
     * @param DatagridConfiguration $config
     * @param bool $useSkipEmptyPeriodsFilter
     * @param string $notNullableField
     */
    protected function addEmptyPeriodsFilter(
        DatagridConfiguration $config,
        $useSkipEmptyPeriodsFilter,
        $notNullableField
    ) {
        if (!$useSkipEmptyPeriodsFilter) {
            return;
        }
        $filters = $config->offsetGet(static::FILTERS_KEY_NAME);
        $filters['columns'][SkipEmptyPeriodsFilter::NAME] = [
            'type' => SkipEmptyPeriodsFilter::NAME,
            'data_name' => $this->getCalendarDateFieldReferenceString(),
            'label' => 'oro.report.filter.skip_empty_periods.label',
            'not_nullable_field' => $notNullableField,
        ];
        $filters['default'][SkipEmptyPeriodsFilter::NAME] = ['value' => 1];
        $config->offsetSet(static::FILTERS_KEY_NAME, $filters);
    }

    /**
     * Adds two more columns to datagrid config related to grouping
     *
     * @param DatagridConfiguration $config
     */
    protected function changeColumnsSection(DatagridConfiguration $config)
    {
        $columns = $config->offsetGet(static::COLUMNS_KEY_NAME);
        $newColumns = [
            static::CALENDAR_DATE_COLUMN_ALIAS => [
                'frontend_type' => 'date',
                'renderable' => false,
            ],
            static::CALENDAR_DATE_GRID_COLUMN_NAME => [
                'label' => 'oro.report.datagrid.column.time_period.label',
            ],
        ];
        $columns = $newColumns + $columns;
        $config->offsetSet(static::COLUMNS_KEY_NAME, $columns);
    }

    /**
     * Configures sorter section for newly added date grouping columns
     *
     * @param DatagridConfiguration $config
     */
    protected function changeSortersSection(DatagridConfiguration $config)
    {
        $sorters = $config->offsetGet(static::SORTERS_KEY_NAME);
        $sorters['columns'][static::CALENDAR_DATE_COLUMN_ALIAS] = [
            'data_name' => $this->getCalendarDateFieldReferenceString(),
        ];
        $sorters['columns'][static::CALENDAR_DATE_GRID_COLUMN_NAME] = [
            'data_name' => $this->getCalendarDateFieldReferenceString(),
        ];
        if (!array_key_exists('default', $sorters)) {
            $sorters['default'] = [];
        }
        $sorters['default'][static::CALENDAR_DATE_GRID_COLUMN_NAME] = AbstractSorterExtension::DIRECTION_DESC;
        $config->offsetSet(static::SORTERS_KEY_NAME, $sorters);
    }

    /**
     * Replaces the "from" section of query to be the calendar date table, and moves the original "from"
     * table to "left join" section. This kind of hack is required as the filter needs a left join which is not possible
     * in doctrine
     *
     * @param DatagridConfiguration $config
     * @param string $dateFieldTableAlias
     * @param string $dateFieldName
     */
    protected function changeSourceSection(DatagridConfiguration $config, $dateFieldTableAlias, $dateFieldName)
    {
        $source = $config->offsetGet(static::SOURCE_KEY_NAME);
        $from = $source['query']['from'][0];
        $newFrom = [
            'alias' => DateGroupingFilter::CALENDAR_TABLE,
            'table' => $this->calendarDateClass,
        ];
        $source['query']['from'][0] = $newFrom;
        if (!array_key_exists('join', $source['query'])) {
            $source['query']['join'] = [];
        }
        if (!array_key_exists('left', $source['query']['join'])) {
            $source['query']['join']['left'] = [];
        }
        $newLeftJoins = [];
        $newLeftJoins[] = [
            'join' => $from['table'],
            'alias' => $from['alias'],
            'conditionType' => 'WITH',
            'condition' => $this->getCalendarJoinCondition($dateFieldTableAlias, $dateFieldName),
        ];
        foreach ($source['query']['join']['left'] as $join) {
            $newLeftJoins[] = $join;
        }
        $source['query']['join']['left'] = $newLeftJoins;
        $source['query']['select'][] = $this->getCalenderSelectProperty();
        $config->offsetSet(static::SOURCE_KEY_NAME, $source);
    }

    /**
     * Add group by for calendarDate.date, which is required by postgresql
     *
     * @param DatagridConfiguration $config
     */
    protected function changeGroupBySection(DatagridConfiguration $config)
    {
        $source = $config->offsetGet(static::SOURCE_KEY_NAME);
        $groupBy = explode(',', $source['query']['groupBy']);
        $groupBy[] = 'calendarDate.date';
        $source['query']['groupBy'] = implode(',', $groupBy);
        $config->offsetSet(static::SOURCE_KEY_NAME, $source);
    }

    /**
     * @param string $dateFieldTableAlias
     * @param string $dateFieldName
     * @return string
     */
    protected function getCalendarJoinCondition($dateFieldTableAlias, $dateFieldName)
    {
        return sprintf(
            static::CALENDAR_TABLE_JOIN_CONDITION_TEMPLATE,
            $this->getCalendarDateFieldReferenceString(),
            $dateFieldTableAlias,
            $dateFieldName
        );
    }

    /**
     * @return string
     */
    protected function getCalendarDateFieldReferenceString()
    {
        return sprintf('%s.date', DateGroupingFilter::CALENDAR_TABLE);
    }

    /**
     * @return string
     */
    protected function getCalenderSelectProperty()
    {
        return sprintf(
            '%s as %s',
            $this->getCalendarDateFieldReferenceString(),
            static::CALENDAR_DATE_COLUMN_ALIAS
        );
    }

    /**
     * @param DatagridConfiguration $config
     * @return bool
     */
    protected function isGridConfigValid(DatagridConfiguration $config)
    {
        return ($this->isSourceSectionValid($config)
            && $config->offsetExists(static::SORTERS_KEY_NAME)
            && isset($config->offsetGet(static::SORTERS_KEY_NAME)['columns'])
            && $config->offsetExists(static::FIELDS_ACL_KEY_NAME)
            && isset($config->offsetGet(static::FIELDS_ACL_KEY_NAME)['columns'])
            && $config->offsetExists(static::COLUMNS_KEY_NAME)
            && $config->offsetExists(static::FILTERS_KEY_NAME)
            && isset($config->offsetGet(static::FILTERS_KEY_NAME)['columns'])
        );
    }

    /**
     * @param DatagridConfiguration $config
     * @return bool
     */
    protected function isSourceSectionValid(DatagridConfiguration $config)
    {
        return ($config->offsetExists(static::SOURCE_KEY_NAME)
            && isset($config->offsetGet(static::SOURCE_KEY_NAME)['query_config'])
            && isset($config->offsetGet(static::SOURCE_KEY_NAME)['query_config']['column_aliases'])
            && isset($config->offsetGet(static::SOURCE_KEY_NAME)['query_config']['table_aliases'])
            && isset($config->offsetGet(static::SOURCE_KEY_NAME)['query'])
            && isset($config->offsetGet(static::SOURCE_KEY_NAME)['query']['select'])
            && isset($config->offsetGet(static::SOURCE_KEY_NAME)['query']['groupBy'])
            && isset($config->offsetGet(static::SOURCE_KEY_NAME)['query']['from'])
            && count($config->offsetGet(static::SOURCE_KEY_NAME)['query']['from']) > 0);
    }

    /**
     * @param array $definition
     * @return bool
     */
    protected function isDateGroupingFilterRequired($definition)
    {
        return (is_array($definition)
            && array_key_exists(DateGroupingType::DATE_GROUPING_NAME, $definition)
            && array_key_exists(
                DateGroupingType::FIELD_NAME_ID,
                $definition[DateGroupingType::DATE_GROUPING_NAME]
            )
            && array_key_exists(
                DateGroupingType::USE_SKIP_EMPTY_PERIODS_FILTER_ID,
                $definition[DateGroupingType::DATE_GROUPING_NAME]
            )
        );
    }

    /**
     * @param DatagridConfiguration $config
     * @return string
     */
    protected function getNotNullableField(DatagridConfiguration $config)
    {
        $aliases = $config->offsetGet(static::SOURCE_KEY_NAME)['query_config']['table_aliases'];

        return sprintf('%s.%s', $aliases[''], static::DEFAULT_GROUP_BY_FIELD);
    }

    /**
     * @param DatagridConfiguration $config
     * @param  [] $joinIds
     * @return string
     * @throws InvalidDatagridConfigException
     */
    protected function getRealDateFieldTableAlias(DatagridConfiguration $config, $joinIds)
    {
        $tableAliasKey = end($joinIds);
        $tableAliases = $config->offsetGet(static::SOURCE_KEY_NAME)['query_config']['table_aliases'];

        if (!array_key_exists($tableAliasKey, $tableAliases)) {
            throw new InvalidDatagridConfigException(
                sprintf('The table alias for key %s must be defined!', $tableAliasKey)
            );
        }

        return $tableAliases[$tableAliasKey];
    }

    /**
     * @param string|null $entity
     * @return null|JoinIdentifierHelper
     */
    protected function getJoinIdHelper($entity = null)
    {
        if (!$this->joinIdHelper) {
            $this->joinIdHelper = new JoinIdentifierHelper($entity);
        }

        return $this->joinIdHelper;
    }
}
