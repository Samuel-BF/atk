<?php

namespace Sintattica\Atk\DataGrid;

use Sintattica\Atk\Core\Config;
use Sintattica\Atk\Core\Tools;
use Sintattica\Atk\Utils\StringParser;
use Sintattica\Atk\RecordList\Totalizer;
use Sintattica\Atk\Core\Node;
use Sintattica\Atk\RecordList\RecordList;
use Sintattica\Atk\Session\SessionManager;
use stdClass;
use Exception;

/**
 * The data grid list component renders the recordlist.
 */
class DataGridList extends DataGridComponent
{
    protected $m_hasActionColumn = null;

    /**
     * Render the list.
     *
     * @return string rendered list HTML
     */
    public function render()
    {
        $alwaysShowGrid = $this->getOption('alwaysShowGrid', false);

        if (!$alwaysShowGrid && $this->getGrid()->isEmbedded() && !$this->getGrid()->isUpdate() && count($this->getGrid()->getRecords()) == 0) {
            return '';
        }

        $grid = $this->getGrid();
        $data = $this->getRecordlistData($grid->getRecords(), $grid->getDefaultActions(), $grid->getExcludes());
        $ui = $grid->getNode()->getUi();

        return $ui->render($grid->getNode()->getTemplate('admin'), $data, $grid->getNode()->m_module);
    }

    /**
     * Get records for a recordlist without actually rendering the recordlist.
     *
     * @param array $recordset the list of records
     * @param array $actions the default actions array
     * @param array $suppressList fields we don't display
     *
     * @return string The rendered recordlist
     */
    private function getRecordlistData($recordset, $actions, $suppressList = array())
    {
        $grid = $this->getGrid();
        $page = $this->getPage();
        $sm = SessionManager::getInstance();

        $edit = $grid->isEditing();

        $page->register_script(Config::getGlobal('assets_url').'javascript/recordlist.js');

        $listName = $grid->getName();

        $defaulthighlight = Config::getGlobal('highlight');
        $selectcolor = Config::getGlobal('select');

        /* retrieve list array */
        $list = $this->listArray($recordset, '', $actions, $suppressList);

        /* Check if some flags are still valid or not... */
        $hasMRA = $grid->hasFlag(DataGrid::MULTI_RECORD_ACTIONS);
        if ($hasMRA && (count($list['mra']) == 0 || count($list['rows']) == 0)) {
            $hasMRA = false;
        }

        $hasSearch = $grid->hasFlag(DataGrid::SEARCH) && !$grid->isEditing();
        if ($hasSearch && count($list['search']) == 0) {
            $hasSearch = false;
        }

        if ($grid->hasFlag(DataGrid::MULTI_RECORD_PRIORITY_ACTIONS) && (count($grid->getNode()->m_priority_actions) == 0 || count($list['rows']) == 0)) {
            $grid->removeFlag(DataGrid::MULTI_RECORD_PRIORITY_ACTIONS);
        } else {
            if ($grid->hasFlag(DataGrid::MULTI_RECORD_PRIORITY_ACTIONS)) {
                $grid->removeFlag(DataGrid::MULTI_RECORD_ACTIONS);
                if ($grid->getNode()->m_priority_max == 0) {
                    $grid->getNode()->m_priority_max = $grid->getNode()->m_priority_min + count($list['rows']) - 1;
                }
            }
        }

        $hasActionCol = $this->_hasActionColumn($list, $hasSearch);

        $orientation = Config::getGlobal('recordlist_orientation');

        /*         * *********** */
        /* HEADER ROW */
        /*         * *********** */
        $headercols = array();

        if ($hasActionCol && count($list['rows']) == 0) {
            if ($orientation == 'left' || $orientation == 'both') {
                // empty cell above search button, if zero rows
                // if $orientation is empty, no search button is shown, so no empty cell is needed
                $headercols[] = array('content' => '&nbsp;');
            }
        }

        if (!$edit && ($hasMRA || $grid->hasFlag(DataGrid::MULTI_RECORD_PRIORITY_ACTIONS))) {
            $headercols[] = array('content' => ''); // Empty leader on top of mra action list.
        }

        if (($orientation == 'left' || $orientation == 'both') && ($hasActionCol && count($list['rows']) > 0)) {
            $headercols[] = array('content' => '');
        }

        foreach (array_values($list['heading']) as $head) {
            if (!$grid->hasFlag(DataGrid::SORT) || empty($head['order'])) {
                $headercols[] = array('content' => $head['title']);
            } else {
                $call = $grid->getUpdateCall(array('atkorderby' => $head['order'], 'atkstartat' => 0));
                $headercols[] = array('content' => $this->_getHeadingAnchorHtml($call, $head['title']));
            }
        }

        if (($orientation == 'right' || $orientation == 'both') && ($hasActionCol && count($list['rows']) > 0)) {
            $headercols[] = array('content' => '');
        }

        if ($hasActionCol && count($list['rows']) == 0) {
            if ($orientation == 'right' || $orientation == 'both') {
                // empty cell above search button, if zero rows
                // if $orientation is empty, no search button is shown, so no empty cell is needed
                $headercols[] = array('content' => '&nbsp;');
            }
        }

        /*         * *********** */
        /* SORT   ROW */
        /*         * *********** */
        $sortcols = array();
        $sortstart = '';
        $sortend = '';
        if ($grid->hasFlag(DataGrid::EXTENDED_SORT)) {
            $call = htmlentities($grid->getUpdateCall(array('atkstartat' => 0), array(), 'ATK.DataGrid.extractExtendedSortOverrides'));
            $button = '<input type="button" value="'.Tools::atktext('sort').'" onclick="'.$call.'">';

            if (!$edit && ($hasMRA || $grid->hasFlag(DataGrid::MULTI_RECORD_PRIORITY_ACTIONS))) {
                $sortcols[] = array('content' => ''); // Empty leader on top of mra action list.
            }

            if ($orientation == 'left' || $orientation == 'both') {
                $sortcols[] = array('content' => $button);
            }

            foreach (array_keys($list['heading']) as $key) {
                if (isset($list['sort'][$key])) {
                    $sortcols[] = array('content' => $list['sort'][$key]);
                }
            }

            if ($orientation == 'right' || $orientation == 'both') {
                $sortcols[] = array('content' => $button);
            }
        }

        /*         * *********** */
        /* SEARCH ROW */
        /*         * *********** */

        $searchcols = array();
        $searchstart = '';
        $searchend = '';
        if ($hasSearch) {
            $call = htmlentities($grid->getUpdateCall(array('atkstartat' => 0), array(), 'ATK.DataGrid.extractSearchOverrides'));
            $buttonType = $grid->isEmbedded() ? 'button' : 'submit';
            $button = '<input type="'.$buttonType.'" class="btn btn-default btn_search" value="'.Tools::atktext('search').'" onclick="'.$call.' return false;">';
            if ($grid->hasFlag(DataGrid::EXTENDED_SEARCH)) {
                $button .= ' '.Tools::href(Config::getGlobal('dispatcher').'?atknodeuri='.$grid->getActionNode()->atkNodeUri().'&atkaction='.$grid->getActionNode()->getExtendedSearchAction(),
                        '('.Tools::atktext('search_extended').')', SessionManager::SESSION_NESTED);
            }

            $button = '<div class="search-buttons">'.$button.'</div>';

            // $searchstart = '<a name="searchform"></a>';
            $searchstart = '';

            if (!$edit && ($hasMRA || $grid->hasFlag(DataGrid::MULTI_RECORD_PRIORITY_ACTIONS))) {
                $searchcols[] = array('content' => '');
            }

            if ($orientation == 'left' || $orientation == 'both') {
                $searchcols[] = array('content' => $button);
            }

            foreach (array_keys($list['heading']) as $key) {
                if (isset($list['search'][$key])) {
                    $searchcols[] = array('content' => $list['search'][$key]);
                } else {
                    $searchcols[] = array('content' => '');
                }
            }
            if ($orientation == 'right' || $orientation == 'both') {
                $searchcols[] = array('content' => $button);
            }
        }

        /*         * **************************************** */
        /* MULTI-RECORD-(PRIORITY-)ACTIONS FORM DATA */
        /*         * **************************************** */
        $liststart = '';
        $listend = '';

        if (!$edit && ($hasMRA || $grid->hasFlag(DataGrid::MULTI_RECORD_PRIORITY_ACTIONS))) {
            $page->register_script(Config::getGlobal('assets_url').'javascript/formselect.js');

            if ($hasMRA) {
                $liststart .= '<script language="javascript" type="text/javascript">var '.$listName.' = new Object();</script>';
            }
        }

        /*         * ***** */
        /* ROWS */
        /*         * ***** */

        $records = array();
        $keys = array_keys($actions);
        $actionurl = (count($actions) > 0) ? $actions[$keys[0]] : '';
        $actionloader = "rl_a['".$listName."'] = {};";
        $actionloader .= "\nrl_a['".$listName."']['base'] = '".$sm->sessionVars($grid->getActionSessionStatus(), 1, $actionurl)."';";
        $actionloader .= "\nrl_a['".$listName."']['embed'] = ".($grid->isEmbedded() ? 'true' : 'false').';';

        for ($i = 0, $_i = count($list['rows']); $i < $_i; ++$i) {
            $record = array();

            /* Special rowColor method makes it possible to change the row color based on the record data.
             * the method can return a simple value (which will be used for the normal row color), or can be
             * an array, in which case the first element will be the normal row color, and the second the mouseover
             * row color, example: function rowColor(&$record, $num) { return array('red', 'blue'); }
             */
            $method = 'rowColor';
            $bgn = '';
            $bgh = $defaulthighlight;
            if (method_exists($grid->getNode(), $method)) {
                $bgn = $grid->getNode()->$method($recordset[$i], $i);
                if (is_array($bgn)) {
                    list($bgn, $bgh) = $bgn;
                }
            }

            $record['class'] = $grid->getNode()->rowClass($recordset[$i], $i);

            foreach ($grid->getNode()->getRowClassCallback() as $callback) {
                $record['class'] .= ' '.call_user_func_array($callback, array($recordset[$i], $i));
            }

            /* alternate colors of rows */
            $record['background'] = $bgn;
            $record['highlight'] = $bgh;
            $record['rownum'] = $i;
            $record['id'] = $listName.'_'.$i;
            $record['type'] = $list['rows'][$i]['type'];

            /* multi-record-priority-actions -> priority selection */
            if (!$edit && $grid->hasFlag(DataGrid::MULTI_RECORD_PRIORITY_ACTIONS)) {
                $select = '<select name="'.$listName.'_atkselector[]">'.'<option value="'.htmlentities($list['rows'][$i]['selector']).'"></option>';
                for ($j = $grid->getNode()->m_priority_min; $j <= $grid->getNode()->m_priority_max; ++$j) {
                    $select .= '<option value="'.$j.'">'.$j.'</option>';
                }
                $select .= '</select>';
                $record['cols'][] = array('content' => $select, 'type' => 'mrpa');
            } /* multi-record-actions -> checkbox */ elseif (!$edit && $hasMRA) {
                if (count($list['rows'][$i]['mra']) > 0) {
                    switch ($grid->getMRASelectionMode()) {
                        case Node::MRA_SINGLE_SELECT:
                            $inputHTML = '<input type="radio" name="'.$listName.'_atkselector[]" value="'.$list['rows'][$i]['selector'].'" class="atkradiobutton" onclick="if (this.disabled) this.checked = false">';
                            break;
                        case Node::MRA_NO_SELECT:
                            $inputHTML = '<input type="checkbox" disabled="disabled" checked="checked">'.'<input type="hidden" name="'.$listName.'_atkselector[]" value="'.$list['rows'][$i]['selector'].'">';
                            break;
                        case Node::MRA_MULTI_SELECT:
                        default:
                            $inputHTML = '<input type="checkbox" name="'.$listName.'_atkselector['.$i.']" value="'.$list['rows'][$i]['selector'].'" class="atkcheckbox" onclick="if (this.disabled) this.checked = false">';
                    }

                    $record['cols'][] = array(
                        'content' => $inputHTML.'
              <script language="javascript"  type="text/javascript">'.$listName.'["'.htmlentities($list['rows'][$i]['selector']).'"] =
                  new Array("'.implode($list['rows'][$i]['mra'], '","').'");
              </script>',
                        'type' => 'mra',
                    );
                } else {
                    $record['cols'][] = array('content' => '');
                }
            } // editable row, add selector
            else {
                if ($edit && $list['rows'][$i]['edit']) {
                    $liststart .= '<input type="hidden" name="atkdatagriddata_AE_'.$i.'_AE_atkprimkey" value="'.htmlentities($list['rows'][$i]['selector']).'">';
                }
            }

            $str_actions = '<span class="actions">';
            $actionloader .= "\nrl_a['".$listName."'][".$i.'] = {};';
            $icons = Config::getGlobal('recordlist_icons');

            foreach ($list['rows'][$i]['actions'] as $name => $url) {
                if (substr($url, 0, 11) == 'javascript:') {
                    $call = substr($url, 11);
                    $actionloader .= "\nrl_a['{$listName}'][{$i}]['{$name}'] = function() { $call; };";
                } else {
                    $actionloader .= "\nrl_a['{$listName}'][{$i}]['{$name}'] = '$url';";
                }

                $module = $grid->getNode()->m_module;
                $nodetype = $grid->getNode()->m_type;
                $actionKeys = array(
                    'action_'.$module.'_'.$nodetype.'_'.$name,
                    'action_'.$nodetype.'_'.$name,
                    'action_'.$name,
                    $name,
                );

                $link = htmlentities($this->text($actionKeys));
                if ($icons == true) {
                    $normalizedName = strtolower(str_replace('-', '_', $name));
                    $icon = Config::get($module, 'icon_'.$nodetype.'_'.$normalizedName, false);
                    if (!$icon) {
                        $icon = Config::getGlobal('icon_'.$nodetype.'_'.$normalizedName, false);
                    }
                    if (!$icon) {
                        $icon = Config::getGlobal('icon_'.$normalizedName, false);
                    }
                    if ($icon) {
                        $link = '<i class="'.$icon.'" title="'.$link.'"></i>';
                    }
                }

                $confirmtext = 'false';
                if (Config::getGlobal('recordlist_javascript_delete') && $name == 'delete') {
                    $confirmtext = "'".$grid->getNode()->confirmActionText($name)."'";
                }
                $str_actions .= $this->_renderRecordActionLink($url, $link, $listName, $i, $name, $confirmtext);
            }

            $str_actions .= '</span>';
            /* actions (left) */
            if ($orientation == 'left' || $orientation == 'both') {
                if (!empty($list['rows'][$i]['actions'])) {
                    $record['cols'][] = array('content' => $str_actions, 'type' => 'actions');
                } else {
                    if ($hasActionCol) {
                        $record['cols'][] = array('content' => '');
                    }
                }
            }

            /* columns */
            foreach ($list['rows'][$i]['data'] as $html) {
                $record['cols'][] = array('content' => $html, 'type' => 'data');
            }

            /* actions (right) */
            if ($orientation == 'right' || $orientation == 'both') {
                if (!empty($list['rows'][$i]['actions'])) {
                    $record['cols'][] = array('content' => $str_actions, 'type' => 'actions');
                } else {
                    if ($hasActionCol) {
                        $record['cols'][] = array('content' => '');
                    }
                }
            }

            $records[] = $record;
        }

        $page->register_scriptcode($actionloader);
        $this->m_actionloader = $actionloader;

        /*************/
        /* TOTAL ROW */
        /*************/
        $totalcols = array();

        if (count($list['total']) > 0) {
            if (!$edit && ($hasMRA || $grid->hasFlag(DataGrid::MULTI_RECORD_PRIORITY_ACTIONS))) {
                $totalcols[] = array('content' => '');
            }

            if (($orientation == 'left' || $orientation == 'both') && ($hasActionCol && count($list['rows']) > 0)) {
                $totalcols[] = array('content' => '');
            }

            foreach (array_keys($list['heading']) as $key) {
                $totalcols[] = array(
                    'content' => (isset($list['total'][$key]) ? $list['total'][$key] : ''),
                );
            }

            if (($orientation == 'right' || $orientation == 'both') && ($hasActionCol && count($list['rows']) > 0)) {
                $totalcols[] = array('content' => '');
            }
        }

        /*         * ********************************************** */
        /* MULTI-RECORD-PRIORITY-ACTION FORM (CONTINUED) */
        /*         * ********************************************** */
        $mra = '';
        if (!$edit && $grid->hasFlag(DataGrid::MULTI_RECORD_PRIORITY_ACTIONS)) {
            $target = $sm->sessionUrl(Config::getGlobal('dispatcher').'?atknodeuri='.$grid->getActionNode()->atkNodeUri(), SessionManager::SESSION_NESTED);

            /* multiple actions -> dropdown */
            if (count($grid->getNode()->m_priority_actions) > 1) {
                $mra = '<select name="'.$listName.'_atkaction">'.'<option value="">'.Tools::atktext('with_selected').':</option>';

                foreach ($grid->getNode()->m_priority_actions as $name) {
                    $mra .= '<option value="'.$name.'">'.Tools::atktext($name).'</option>';
                }

                $mra .= '</select>&nbsp;'.$this->getCustomMraHtml().'<input type="button" class="btn" value="'.Tools::atktext('submit').'" onclick="atkSubmitMRPA(\''.$listName.'\', this.form, \''.$target.'\')">';
            } /* one action -> only the submit button */ else {
                $mra = $this->getCustomMraHtml().'<input type="hidden" name="'.$listName.'_atkaction" value="'.$grid->getNode()->m_priority_actions[0].'">'.'<input type="button" class="btn" value="'.Tools::atktext($grid->getNode()->m_priority_actions[0]).'" onclick="atkSubmitMRPA(\''.$listName.'\', this.form, \''.$target.'\')">';
            }
        }

        /*         * ************************************* */
        /* MULTI-RECORD-ACTION FORM (CONTINUED) */ /*         * ************************************* */ elseif (!$edit && $hasMRA) {
            $postvars = $grid->getNode()->m_postvars;

            $target = $sm->sessionUrl(Config::getGlobal('dispatcher').'?atknodeuri='.$grid->getNode()->atkNodeUri().'&atktarget='.(!empty($postvars['atktarget']) ? $postvars['atktarget'] : '').'&atktargetvar='.(!empty($postvars['atktargetvar']) ? $postvars['atktargetvar'] : '').'&atktargetvartpl='.(!empty($postvars['atktargetvartpl']) ? $postvars['atktargetvartpl'] : ''),
                SessionManager::SESSION_NESTED);

            $mra = (count($list['rows']) > 1 && $grid->getMRASelectionMode() == Node::MRA_MULTI_SELECT ? '<a href="javascript:void(0)" onclick="updateSelection(\''.$listName.'\', $(this).up(\'form\'), \'all\')">'.Tools::atktext('select_all').'</a> | '.'<a href="javascript:void(0)" onclick="updateSelection(\''.$listName.'\', $(this).up(\'form\'), \'none\')">'.Tools::atktext('deselect_all').'</a> | '.'<a href="javascript:void(0)" onclick="updateSelection(\''.$listName.'\', $(this).up(\'form\'), \'invert\')">'.Tools::atktext('select_invert').'</a> ' //'<div style="height: 8px"></div>'
                : '');

            $module = $grid->getNode()->m_module;
            $nodetype = $grid->getNode()->m_type;

            /* multiple actions -> dropdown */
            if (count($list['mra']) > 1) {
                $default = $this->getGrid()->getMRADefaultAction();
                $mra .= '<select name="'.$listName.'_atkaction" onchange="javascript:updateSelectable(\''.$listName.'\', this.form)">'.'<option value="">'.Tools::atktext('with_selected').'</option>';

                foreach ($list['mra'] as $name) {
                    if ($grid->getNode()->allowed($name)) {
                        $actionKeys = array(
                            'action_'.$module.'_'.$nodetype.'_'.$name,
                            'action_'.$nodetype.'_'.$name,
                            'action_'.$name,
                            $name,
                        );

                        $mra .= '<option value="'.$name.'"';
                        if ($default == $name) {
                            $mra .= 'selected="selected"';
                        }
                        $mra .= '>'.Tools::atktext($actionKeys, $grid->getNode()->m_module, $grid->getNode()->m_type).'</option>';
                    }
                }

                $embedded = $this->getGrid()->isEmbedded() ? 'true' : 'false';
                $mra .= '</select>&nbsp;'.$this->getCustomMraHtml().'<input type="button" class="btn" value="'.Tools::atktext('submit').'" onclick="atkSubmitMRA(\''.$listName.'\', this.form, \''.$target.'\', '.$embedded.', false)">';
            } /* one action -> only the submit button */ else {
                if ($grid->getNode()->allowed($list['mra'][0])) {
                    $name = $list['mra'][0];

                    $actionKeys = array(
                        'action_'.$module.'_'.$nodetype.'_'.$name,
                        'action_'.$nodetype.'_'.$name,
                        'action_'.$name,
                        $name,
                    );

                    $embedded = $this->getGrid()->isEmbedded() ? 'true' : 'false';
                    $mra .= '<input type="hidden" name="'.$listName.'_atkaction" value="'.$name.'">'.$this->getCustomMraHtml().'<input type="button" class="btn" value="'.Tools::atktext($actionKeys,
                            $grid->getNode()->m_module,
                            $grid->getNode()->m_type).'" onclick="atkSubmitMRA(\''.$listName.'\', this.form, \''.$target.'\', '.$embedded.', false)">';
                }
            }
        } else {
            if ($edit) {
                $mra = '<input type="button" class="btn" value="'.Tools::atktext('save').'" onclick="'.htmlentities($this->getGrid()->getSaveCall()).'">';
            }
        }

        $recordListData = array(
            'rows' => $records,
            'header' => $headercols,
            'search' => $searchcols,
            'sort' => $sortcols,
            'total' => $totalcols,
            'searchstart' => $searchstart,
            'searchend' => $searchend,
            'sortstart' => $sortstart,
            'sortend' => $sortend,
            'liststart' => $liststart,
            'listend' => $listend,
            'listid' => $listName,
            'mra' => $mra,
            'editing' => $this->getGrid()->isEditing(),
        );

        return $recordListData;
    }

    /**
     * Returns the link for heading anchors.
     *
     * @param string $onClickCall the value for in the onclick
     * @param string $title the title of the link $title
     *
     * @return string
     */
    protected function _getHeadingAnchorHtml($onClickCall, $title)
    {
        return '<a href="javascript:void(0)" onclick="'.htmlentities($onClickCall).'">'.$title.'</a>';
    }

    /**
     * Renders a link for a row action with the specified parameters.
     *
     * @param string $url The URL for the record action
     * @param string $link HTML for displaying the link (between the <a></a>)
     * @param string $listName The name of the recordlist
     * @param string $i The row index to render the action for
     * @param string $name The action name
     * @param bool|string $confirmtext The text for the confirmation if set
     *
     * @return string the html link
     */
    protected function _renderRecordActionLink($url, $link, $listName, $i, $name, $confirmtext = 'false')
    {
        return '<a href="'."javascript:rl_do('$listName',$i,'$name',$confirmtext);".'" class="btn btn-default">'.$link.'</a>';
    }

    /**
     * Checks wether the recordlist should display a column which holds the actions.
     *
     * @param array $list The recordlist data
     * @param bool $hasSearch
     *
     * @return bool Wether the list should display an extra column to hold the actions
     */
    public function _hasActionColumn($list, $hasSearch)
    {
        $grid = $this->getGrid();

        if ($this->m_hasActionColumn === null) {
            // when there's a search bar, we always need an extra column (for the button)
            if ($hasSearch) {
                $this->m_hasActionColumn = true;
            } // when there's an extended sort bar, we also need the column (for the sort button)
            else {
                if ($grid->hasFlag(DataGrid::EXTENDED_SORT)) {
                    $this->m_hasActionColumn = true;
                } else {
                    // otherwise, it depends on whether one of the records has actions defined.
                    $this->m_hasActionColumn = false;

                    foreach ($list['rows'] as $record) {
                        if (!empty($record['actions'])) {
                            $this->m_hasActionColumn = true;
                            break;
                        }
                    }
                }
            }
        }

        return $this->m_hasActionColumn;
    }

    /**
     * Get custom mra html.
     *
     * @return string The custom mra html
     */
    public function getCustomMraHtml()
    {
        $grid = $this;
        if (method_exists($grid->getNode(), 'getcustommrahtml')) {
            $output = $grid->getNode()->getCustomMraHtml();

            return $output;
        }

        return;
    }

    /**
     * Convert datagrid flags to recordlist flags.
     *
     * @todo this should be replaced in the long term
     *
     * @deprecated
     *
     * @return int
     */
    private function convertDataGridFlags()
    {
        $grid = $this->getGrid();

        $result = !$grid->isEditing() && $grid->hasFlag(DataGrid::MULTI_RECORD_ACTIONS) ? RecordList::RL_MRA : 0;
        $result |= !$grid->isEditing() && $grid->hasFlag(DataGrid::MULTI_RECORD_PRIORITY_ACTIONS) ? RecordList::RL_MRPA : 0;
        $result |= $grid->isEditing() || !$grid->hasFlag(DataGrid::SEARCH) ? RecordList::RL_NO_SEARCH : 0;
        $result |= $grid->isEditing() || !$grid->hasFlag(DataGrid::EXTENDED_SEARCH) ? RecordList::RL_NO_EXTENDED_SEARCH : 0;
        $result |= !$grid->isEditing() && $grid->hasFlag(DataGrid::EXTENDED_SORT) ? RecordList::RL_EXT_SORT : 0;
        $result |= $grid->isEditing() ? RecordList::RL_NO_SORT : 0;

        return $result;
    }

    /**
     * Function outputs an array with all information necessary to output a recordlist.
     *
     * @param array $recordset List of records that need to be displayed
     * @param string $prefix Prefix for each column name (used for subcalls)
     * @param array $actions List of default actions for each record
     * @param array $suppress An array of fields that you want to hide
     *
     * The result array contains the following information:
     *  "name"     => the name of the recordlist
     *  "heading"  => for each visible column an array containing: "title" {, "url"}
     *  "search"   => for each visible column HTML input field(s) for searching
     *  "rows"     => list of rows, per row: "data", "actions", "mra", "record"
     *  "totalraw" => for each totalisable column the sum value field(s) (raw)
     *  "total"    => for each totalisable column the sum value (display)
     *  "mra"      => list of all multi-record actions
     *
     * @return array see above
     */
    private function listArray(&$recordset, $prefix = '', $actions = array(), $suppress = array())
    {
        $grid = $this->getGrid();

        $flags = $this->convertDataGridFlags();

        if (!is_array($suppress)) {
            $suppress = array();
        }
        $result = array(
            'name' => $grid->getName(),
            'heading' => array(),
            'search' => array(),
            'rows' => array(),
            'totalraw' => array(),
            'total' => array(),
            'mra' => array(),
        );

        $columnConfig = $grid->getNode()->getColumnConfig($grid->getName());

        if (!Tools::hasFlag($flags, RecordList::RL_NO_SEARCH) || $grid->isEditing()) {
            $grid->getNode()->setAttribSizes();
        }

        $this->_addListArrayHeader($result, $prefix, $suppress, $flags, $columnConfig);

        /* actions array can contain multi-record-actions */
        if (count($actions) == 2 && count(array_diff(array_keys($actions), array('actions', 'mra'))) == 0) {
            $mra = $actions['mra'];
            $actions = $actions['actions'];
        } else {
            $mra = $grid->getNode()->hasFlag(Node::NF_NO_DELETE) ? array() : array('delete');
        }

        /* get the rows */
        for ($i = 0, $_i = count($recordset); $i < $_i; ++$i) {
            $result['rows'][$i] = array(
                'columns' => array(),
                'actions' => $actions,
                'mra' => $mra,
                'record' => &$recordset[$i],
                'data' => array(),
            );
            $result['rows'][$i]['selector'] = $grid->getNode()->primaryKey($recordset[$i]);
            $result['rows'][$i]['type'] = 'data';
            $row = &$result['rows'][$i];

            /* actions / mra */
            $grid->getNode()->collectRecordActions($row['record'], $row['actions'], $row['mra']);

            // filter actions we are allowed to execute
            foreach ($row['actions'] as $name => $url) {
                if (!empty($url) && $grid->getNode()->allowed($name, $row['record'])) {
                    /* dirty hack */
                    $atkencoded = strpos($url, '_15B') > 0;

                    $url = str_replace('%5B', '[', $url);
                    $url = str_replace('%5D', ']', $url);
                    $url = str_replace('_1'.'5B', '[', $url);
                    $url = str_replace('_1'.'5D', ']', $url);

                    if ($atkencoded) {
                        $url = str_replace('[pk]', Tools::atkurlencode(rawurlencode($row['selector']), false), $url);
                    } else {
                        $url = str_replace('[pk]', rawurlencode($row['selector']), $url);
                    }

                    $parser = new StringParser($url);
                    $url = $parser->parse($row['record'], true, false);
                    $row['actions'][$name] = $url;
                } else {
                    unset($row['actions'][$name]);
                }
            }

            // filter multi-record-actions we are allowed to execute
            foreach ($row['mra'] as $j => $name) {
                if (!$grid->getNode()->allowed($name, $row['record'])) {
                    unset($row['mra'][$j]);
                }
            }

            $row['mra'] = array_values($row['mra']);
            $result['mra'] = array_merge($result['mra'], $row['mra']);

            /* columns */
            $editAllowed = $grid->getPostvar('atkgridedit', false) && $grid->getNode()->allowed('edit', $result['rows'][$i]['record']);
            $result['rows'][$i]['edit'] = $editAllowed;
            $this->_addListArrayRow($result, $prefix, $suppress, $flags, $i, $editAllowed);
        }

        if (Tools::hasFlag($flags, RecordList::RL_EXT_SORT) && $columnConfig->hasSubTotals()) {
            $totalizer = new Totalizer($grid->getNode(), $columnConfig);
            $result['rows'] = $totalizer->totalize($result['rows']);
        }

        if (Tools::hasFlag($flags, RecordList::RL_MRA)) {
            $result['mra'] = array_values(array_unique($result['mra']));
        }

        return $result;
    }

    /**
     * Returns the list attributes and their possible child column
     * names for this list.
     */
    protected function _getColumns()
    {
        $result = array();

        $columns = $this->getOption('columns');
        if ($columns == null) {
            foreach ($this->getNode()->getAttributeNames() as $attrName) {
                $entry = new stdClass();
                $entry->attrName = $attrName;
                $entry->columnName = '*';
                $result[] = $entry;
            }
        } else {
            foreach ($columns as $column) {
                $parts = explode('.', $column);
                $entry = new stdClass();
                $entry->attrName = $parts[0];
                $entry->columnName = isset($parts[1]) ? $parts[1] : null;
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * Add the list array header to the result list.
     */
    private function _addListArrayHeader(&$listArray, $prefix, $suppressList, $flags, $columnConfig)
    {
        $columns = $this->_getColumns();

        foreach ($columns as $column) {
            if (in_array($column->attrName, $suppressList)) {
                continue;
            }

            $attr = $this->getNode()->getAttribute($column->attrName);
            if (!is_object($attr)) {
                throw new Exception("Invalid attribute {$column->attrName} for node ".$this->getNode()->atkNodeUri());
            }

            $attr->addToListArrayHeader($this->getNode()->getAction(), $listArray, $prefix, $flags, $this->getGrid()->getPostvar('atksearch'), $columnConfig,
                $this->getGrid(), $column->columnName);
        }
    }

    /**
     * Adds the given row the the list array.
     */
    private function _addListArrayRow(&$listArray, $prefix, $suppressList, $flags, $rowIndex, $editAllowed)
    {
        $columns = $this->_getColumns();

        foreach ($columns as $column) {
            if (in_array($column->attrName, $suppressList)) {
                continue;
            }

            $attr = $this->getNode()->getAttribute($column->attrName);
            if (!is_object($attr)) {
                throw new Exception("Invalid attribute {$column->attrName} for node ".$this->getNode()->atkNodeUri());
            }

            $edit = $editAllowed && in_array($column->attrName, $this->getNode()->m_editableListAttributes);

            $attr->addToListArrayRow($this->getNode()->getAction(), $listArray, $rowIndex, $prefix, $flags, $edit, $this->getGrid(), $column->columnName);
        }
    }
}