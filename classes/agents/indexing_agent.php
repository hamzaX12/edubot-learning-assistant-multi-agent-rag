<?php
namespace local_edubot\agents;

defined('MOODLE_INTERNAL') || die();

class indexing_agent {
    public function run($courseid, $cmid = null, $is_delete = false) {
        global $DB;

        if ($cmid) {
            $DB->delete_records('local_edubot_rag_chunks', ['courseid' => $courseid, 'resource_id' => $cmid]);
            if ($is_delete) {
                return [
                    'success' => true,
                    'files_processed' => 1,
                    'chunks_created' => 0,
                    'errors' => []
                ];
            }
        } else {
            // Clear all chunks for this course if no specific module is provided
            $DB->delete_records('local_edubot_rag_chunks', ['courseid' => $courseid]);
        }

        // Trigger the core rag engine indexing process
        $ragengine = new \local_edubot\rag_engine();
        $result = $ragengine->index_course_pdfs($courseid, $cmid);

        return [
            'success' => empty($result['errors']),
            'files_processed' => $result['files_processed'] ?? 0,
            'chunks_created' => $result['chunks_created'] ?? 0,
            'errors' => $result['errors'] ?? []
        ];
    }
}
