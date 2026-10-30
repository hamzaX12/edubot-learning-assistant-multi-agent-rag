<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Upgrade code for local_edubot.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_edubot_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026052400) {

        // Define field conversation_type to be added to local_edubot_conversations.
        $table = new xmldb_table('local_edubot_conversations');
        $field = new xmldb_field('conversation_type', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'chat', 'updated_at');

        // Conditionally launch add field conversation_type.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field context_data to be added to local_edubot_conversations.
        $field = new xmldb_field('context_data', XMLDB_TYPE_TEXT, null, null, null, null, null, 'conversation_type');

        // Conditionally launch add field context_data.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // EduBot savepoint reached.
        upgrade_plugin_savepoint(true, 2026052400, 'local', 'edubot');
    }

    return true;
}
