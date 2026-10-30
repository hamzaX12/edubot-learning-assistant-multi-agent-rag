<?php
namespace local_edubot;

defined('MOODLE_INTERNAL') || die();

class event_observers {

    public static function course_module_created(\core\event\course_module_created $event) {
        self::handle_resource_update($event);
    }

    public static function course_module_updated(\core\event\course_module_updated $event) {
        self::handle_resource_update($event);
    }

    public static function course_module_deleted(\core\event\course_module_deleted $event) {
        self::handle_resource_update($event, true);
    }

    private static function handle_resource_update($event, $is_delete = false) {
        // We only care about resource modules.
        // Some custom scripts (like upload_material) might not set modulename in the event.
        $modulename = $event->other['modulename'] ?? null;
        if (!$modulename && isset($event->other['instanceid'])) {
            global $DB;
            $modulename = $DB->get_field_sql(
                "SELECT m.name FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module WHERE cm.id = ?",
                [$event->contextinstanceid]
            );
        }

        if ($modulename !== 'resource') {
            return;
        }

        $courseid = $event->courseid;
        $cmid = $event->contextinstanceid; // contextinstanceid for CONTEXT_MODULE is the cmid.

        if ($is_delete) {
            // Deletion is fast and doesn't require APIs, so do it instantly right now!
            global $DB;
            $DB->delete_records('local_edubot_rag_chunks', ['courseid' => $courseid, 'resource_id' => $cmid]);
            return;
        }

        // For Creation/Updates, queue the adhoc task for background API processing
        $task = new \local_edubot\task\background_indexing_task();
        $task->set_custom_data([
            'courseid' => $courseid,
            'cmid' => $cmid,
            'is_delete' => false
        ]);
        
        \core\task\manager::queue_adhoc_task($task);
    }
}
