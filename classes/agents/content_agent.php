<?php
namespace local_edubot\agents;

defined('MOODLE_INTERNAL') || die();

class content_agent {
    public function run($message, $courseid, $button_action) {
        // 1. Get RAG context
        $ragengine = new \local_edubot\rag_engine();
        $maxchunks = (int) get_config('local_edubot', 'max_rag_chunks') ?: 3;
        $chunks = $ragengine->retrieve_relevant_chunks($message, $courseid, $maxchunks);
        $rag_context = $ragengine->format_rag_context($chunks);

        // 2. Determine Action Instruction
        $action_instruction = '';
        if (!empty($button_action)) {
            switch ($button_action) {
                case 'hint':
                    $action_instruction = "Apply scaffolded hints and never reveal the answer.";
                    break;
                case 'check':
                    $action_instruction = "Ask the student to explain the concept back in their own words.";
                    break;
                case 'explain':
                    $action_instruction = "Give a complete explanation grounded in the retrieved course content.";
                    break;
                case 'quiz':
                    $action_instruction = "Generate exactly 3 multiple choice questions calibrated to the student's mastery level.";
                    break;
                case 'example':
                    $action_instruction = "Generate one concrete worked example adapted to the student's learning style.";
                    break;
            }
        }

        return [
            'rag_context' => $rag_context,
            'action_instruction' => $action_instruction,
        ];
    }
}
