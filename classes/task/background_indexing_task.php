<?php
namespace local_edubot\task;

defined('MOODLE_INTERNAL') || die();

class background_indexing_task extends \core\task\adhoc_task {

    public function get_name() {
        return get_string('task_background_indexing', 'local_edubot') ?? 'EduBot Background PDF Indexing';
    }

    public function execute() {
        $data = $this->get_custom_data();
        if (!isset($data->courseid)) {
            mtrace('EduBot Indexing Task: No course ID provided.');
            return;
        }

        $courseid = $data->courseid;
        $cmid = $data->cmid ?? null;
        $is_delete = $data->is_delete ?? false;
        mtrace("EduBot Indexing Task: Starting indexing for course $courseid, cmid $cmid...");

        $agent = new \local_edubot\agents\indexing_agent();
        $result = $agent->run($courseid, $cmid, $is_delete);

        if ($result['success']) {
            mtrace("EduBot Indexing Task: Successfully processed {$result['files_processed']} files and generated {$result['chunks_created']} chunks.");
        } else {
            mtrace("EduBot Indexing Task: Completed with errors.");
            foreach ($result['errors'] as $error) {
                mtrace(" - $error");
            }
        }
    }
}
