<?php
/* vim: set expandtab sw=4 ts=4 sts=4: */
/**
 *
 * @package PhpMyAdmin
 */

/**
 * Get some core libraries
 */
require_once 'libraries/common.inc.php';

// Check parameters
$common_functions->checkParameters(array('db', 'table'));


/**
 * Defines the url to return to in case of error in a sql statement
 */
$err_url = 'tbl_sql.php?' . PMA_generate_common_url($db, $table);

/**
 * The form used to define the field to add has been submitted
 */
$abort = false;

// check number of fields to be created
if (isset($_REQUEST['submit_num_fields'])) {
    if (isset($_REQUEST['orig_after_field'])) {
        $_REQUEST['after_field'] = $_REQUEST['orig_after_field'];
    }
    if (isset($_REQUEST['orig_field_where'])) {
        $_REQUEST['field_where'] = $_REQUEST['orig_field_where'];
    }
    $num_fields = $_REQUEST['orig_num_fields'] + $_REQUEST['added_fields'];
    $regenerate = true;
} elseif (isset($_REQUEST['num_fields']) && intval($_REQUEST['num_fields']) > 0) {
    $num_fields = (int) $_REQUEST['num_fields'];
} else {
    $num_fields = 1;
}

if (isset($_REQUEST['do_save_data'])) {
    $query = '';
    $definitions = array();

    // Transforms the radio button field_key into 3 arrays
    $field_cnt      = count($_REQUEST['field_name']);
    $field_primary  = array();
    $field_index    = array();
    $field_unique   = array();
    $field_fulltext = array();
    for ($i = 0; $i < $field_cnt; ++$i) {
        if (isset($_REQUEST['field_key'][$i])
            && strlen($_REQUEST['field_name'][$i])
        ) {
            if ($_REQUEST['field_key'][$i] == 'primary_' . $i) {
                $field_primary[] = $i;
            }
            if ($_REQUEST['field_key'][$i] == 'index_' . $i) {
                $field_index[]   = $i;
            }
            if ($_REQUEST['field_key'][$i] == 'unique_' . $i) {
                $field_unique[]  = $i;
            }
            if ($_REQUEST['field_key'][$i] == 'fulltext_' . $i) {
                $field_fulltext[]  = $i;
            }
        } // end if
    } // end for

    // Builds the field creation statement and alters the table
    for ($i = 0; $i < $field_cnt; ++$i) {
        // '0' is also empty for php :-(
        if (empty($_REQUEST['field_name'][$i]) && $_REQUEST['field_name'][$i] != '0') {
            continue;
        }

        $definition = ' ADD ' . PMA_Table::generateFieldSpec(
            $_REQUEST['field_name'][$i],
            $_REQUEST['field_type'][$i],
            $i,
            $_REQUEST['field_length'][$i],
            $_REQUEST['field_attribute'][$i],
            isset($_REQUEST['field_collation'][$i])
                ? $_REQUEST['field_collation'][$i]
                : '',
            isset($_REQUEST['field_null'][$i])
                ? $_REQUEST['field_null'][$i]
                : 'NOT NULL',
            $_REQUEST['field_default_type'][$i],
            $_REQUEST['field_default_value'][$i],
            isset($_REQUEST['field_extra'][$i])
                ? $_REQUEST['field_extra'][$i]
                : false,
            isset($_REQUEST['field_comments'][$i])
                ? $_REQUEST['field_comments'][$i]
                : '',
            $field_primary
        );

        if ($_REQUEST['field_where'] != 'last') {
            // Only the first field can be added somewhere other than at the end
            if ($i == 0) {
                if ($_REQUEST['field_where'] == 'first') {
                    $definition .= ' FIRST';
                } else {
                    $definition .= ' AFTER ' . $common_functions->backquote($_REQUEST['after_field']);
                }
            } else {
                $definition .= ' AFTER ' . $common_functions->backquote($_REQUEST['field_name'][$i-1]);
            }
        }
        $definitions[] = $definition;
    } // end for

    // Builds the primary keys statements and updates the table
    if (count($field_primary)) {
        $fields = array();
        foreach ($field_primary as $field_nr) {
            $fields[] = $common_functions->backquote($_REQUEST['field_name'][$field_nr]);
        }
        $definitions[] = ' ADD PRIMARY KEY (' . implode(', ', $fields) . ') ';
        unset($fields);
    }

    // Builds the indexes statements and updates the table
    if (count($field_index)) {
        $fields = array();
        foreach ($field_index as $field_nr) {
            $fields[] = $common_functions->backquote($_REQUEST['field_name'][$field_nr]);
        }
        $definitions[] = ' ADD INDEX (' . implode(', ', $fields) . ') ';
        unset($fields);
    }

    // Builds the uniques statements and updates the table
    if (count($field_unique)) {
        $fields = array();
        foreach ($field_unique as $field_nr) {
            $fields[] = $common_functions->backquote($_REQUEST['field_name'][$field_nr]);
        }
        $definitions[] = ' ADD UNIQUE (' . implode(', ', $fields) . ') ';
        unset($fields);
    }

    // Builds the fulltext statements and updates the table
    if (count($field_fulltext)) {
        $fields = array();
        foreach ($field_fulltext as $field_nr) {
            $fields[] = $common_functions->backquote($_REQUEST['field_name'][$field_nr]);
        }
        $definitions[] = ' ADD FULLTEXT (' . implode(', ', $fields) . ') ';
        unset($fields);
    }

    // To allow replication, we first select the db to use and then run queries
    // on this db.
    PMA_DBI_select_db($db) or $common_functions->mysqlDie(PMA_getError(), 'USE ' . $common_functions->backquote($db), '', $err_url);
    $sql_query    = 'ALTER TABLE ' . $common_functions->backquote($table) . ' ' . implode(', ', $definitions) . ';';
    $result = PMA_DBI_try_query($sql_query);

    if ($result === true) {
        // If comments were sent, enable relation stuff
        include_once 'libraries/transformations.lib.php';

        // Update comment table for mime types [MIME]
        if (isset($_REQUEST['field_mimetype'])
            && is_array($_REQUEST['field_mimetype'])
            && $cfg['BrowseMIME']
        ) {
            foreach ($_REQUEST['field_mimetype'] as $fieldindex => $mimetype) {
                if (isset($_REQUEST['field_name'][$fieldindex])
                    && strlen($_REQUEST['field_name'][$fieldindex])
                ) {
                    PMA_setMIME(
                        $db, $table,
                        $_REQUEST['field_name'][$fieldindex],
                        $mimetype,
                        $_REQUEST['field_transformation'][$fieldindex],
                        $_REQUEST['field_transformation_options'][$fieldindex]
                    );
                }
            }
        }

        // Go back to the structure sub-page
        $message = PMA_Message::success(__('Table %1$s has been altered successfully'));
        $message->addParam($table);

        if ($GLOBALS['is_ajax_request'] == true) {
            $response = PMA_Response::getInstance();
            $response->addJSON('message', $message);
            $response->addJSON(
                'sql_query',
                $common_functions->getMessage(null, $sql_query)
            );
            exit;
        }

        $active_page = 'tbl_structure.php';
        include 'tbl_structure.php';
    } else {
        $common_functions->mysqlDie('', '', '', $err_url, false);
        // An error happened while inserting/updating a table definition.
        // to prevent total loss of that data, we embed the form once again.
        // The variable $regenerate will be used to restore data in libraries/tbl_properties.inc.php
        $num_fields = $_REQUEST['orig_num_fields'];
        if (isset($_REQUEST['orig_after_field'])) {
            $_REQUEST['after_field'] = $_REQUEST['orig_after_field'];
        }
        if (isset($_REQUEST['orig_field_where'])) {
            $_REQUEST['field_where'] = $_REQUEST['orig_field_where'];
        }
        $regenerate = true;
    }
} // end do alter table

/**
 * Displays the form used to define the new field
 */
if ($abort == false) {
    /**
     * Gets tables informations
     */
    include_once 'libraries/tbl_common.inc.php';
    include_once 'libraries/tbl_info.inc.php';

    $active_page = 'tbl_structure.php';
    /**
     * Display the form
     */
    $action = 'tbl_addfield.php';
    include_once 'libraries/tbl_properties.inc.php';
}

?>
