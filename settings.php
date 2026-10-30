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
 * Admin settings for EduBot.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    $settings = new admin_settingpage('local_edubot', get_string('pluginname', 'local_edubot'));

    // Header.
    $settings->add(new admin_setting_heading(
        'local_edubot/settings_heading',
        get_string('settings_heading', 'local_edubot'),
        get_string('settings_heading_desc', 'local_edubot')
    ));

    // Plugin enabled.
    $settings->add(new admin_setting_configcheckbox(
        'local_edubot/plugin_enabled',
        get_string('plugin_enabled', 'local_edubot'),
        get_string('plugin_enabled_desc', 'local_edubot'),
        1
    ));

    // Gemini API Key.
    $settings->add(new admin_setting_configtext(
        'local_edubot/gemini_api_key',
        get_string('gemini_api_key', 'local_edubot'),
        get_string('gemini_api_key_desc', 'local_edubot'),
        '',
        PARAM_RAW_TRIMMED
    ));

    // Gemini Model.
    $models = [
        'gemini-flash-latest' => 'Gemini Flash (Latest — Recommended)',
        'gemini-2.5-flash' => 'Gemini 2.5 Flash',
        'gemini-2.5-pro' => 'Gemini 2.5 Pro',
        'gemini-1.5-flash' => 'Gemini 1.5 Flash (Legacy)',
    ];
    $settings->add(new admin_setting_configselect(
        'local_edubot/gemini_model',
        get_string('gemini_model', 'local_edubot'),
        get_string('gemini_model_desc', 'local_edubot'),
        'gemini-flash-latest',
        $models
    ));

    // LlamaParse API Key.
    $settings->add(new admin_setting_configpasswordunmask(
        'local_edubot/llamaparse_api_key',
        get_string('llamaparse_api_key', 'local_edubot'),
        get_string('llamaparse_api_key_desc', 'local_edubot'),
        ''
    ));

    // Constraint Mode.
    $modes = [
        'GUIDED' => 'GUIDED — Hints and Socratic questions only',
        'STRICT' => 'STRICT — Only point to resources',
        'OPEN'   => 'OPEN — Full explanations allowed',
    ];
    $settings->add(new admin_setting_configselect(
        'local_edubot/constraint_mode',
        get_string('constraint_mode', 'local_edubot'),
        get_string('constraint_mode_desc', 'local_edubot'),
        'GUIDED',
        $modes
    ));

    // Max RAG Chunks.
    $settings->add(new admin_setting_configtext(
        'local_edubot/max_rag_chunks',
        get_string('max_rag_chunks', 'local_edubot'),
        get_string('max_rag_chunks_desc', 'local_edubot'),
        3,
        PARAM_INT
    ));

    // Enable emotion detection.
    $settings->add(new admin_setting_configcheckbox(
        'local_edubot/enable_emotion_detection',
        get_string('enable_emotion_detection', 'local_edubot'),
        get_string('enable_emotion_detection_desc', 'local_edubot'),
        1
    ));

    // Add link to RAG indexing admin page.
    $settings->add(new admin_setting_heading(
        'local_edubot/reindex_heading',
        get_string('reindex_heading', 'local_edubot'),
        get_string('reindex_desc', 'local_edubot') .
        '<br><a href="' . $CFG->wwwroot . '/local/edubot/admin_reindex.php" class="btn btn-primary mt-2">' .
        get_string('manage_indexing', 'local_edubot') . '</a>'
    ));

    $ADMIN->add('localplugins', $settings);
}
