<?php
namespace local_edubot\agents;

defined('MOODLE_INTERNAL') || die();

class reflection_agent {
    public function run($raw_response, $strategy, $affective_state) {
        $failures = [];
        $lower_response = strtolower($raw_response);
        $constraint_mode = get_config('local_edubot', 'constraint_mode') ?: 'GUIDED';

        // CHECK 1 — Answer leak detection
        if (in_array($constraint_mode, ['GUIDED', 'STRICT'])) {
            $leak_phrases = [
                "the answer is", "the solution is", "it equals", "the result is", 
                "the correct answer is", "the output is", "the query is", 
                "you should write", "the code is"
            ];
            foreach ($leak_phrases as $phrase) {
                if (strpos($lower_response, $phrase) !== false) {
                    $failures[] = "Rephrase using a hint or a Socratic question instead of revealing the answer.";
                    break;
                }
            }
        }

        // CHECK 2 — Response length
        // A rough word count approximation by splitting on whitespace
        $word_count = str_word_count(strip_tags($raw_response));
        $limit = 150;
        if (in_array($strategy, ['EMOTIONAL_SUPPORT', 'CONSOLIDATION', 'ACTIVATION'])) {
            $limit = 200;
        }

        if ($word_count > $limit) {
            $failures[] = "Your response has {$word_count} words. The limit is {$limit} words. Make the response shorter and more focused.";
        }

        // CHECK 3 — Emotional acknowledgment
        if (in_array($affective_state, ['FRUSTRATED', 'DISCOURAGED', 'ANXIOUS'])) {
            $first_150 = substr($lower_response, 0, 150);
            $empathy_words = [
                "understand", "hear", "frustrat", "difficult", "challeng", 
                "normal", "common", "persistence", "effort", "tried", 
                "okay", "together", "help", "support", "relax"
            ];
            
            $found_empathy = false;
            foreach ($empathy_words as $word) {
                if (strpos($first_150, $word) !== false) {
                    $found_empathy = true;
                    break;
                }
            }
            
            if (!$found_empathy) {
                $failures[] = "Add a warm empathetic opening sentence before any content.";
            }
        }

        // CHECK 4 — Ends with a question
        $question_count = substr_count($raw_response, '?');
        
        if ($question_count == 0) {
            $failures[] = "Add one focused question at the end.";
        } else if ($question_count > 2) {
            $failures[] = "Keep only the single most important question and remove the rest.";
        }

        $is_approved = empty($failures);
        $retry_feedback = $is_approved ? '' : implode(' ', $failures);

        return [
            'is_approved' => $is_approved,
            'retry_feedback' => $retry_feedback
        ];
    }
}
