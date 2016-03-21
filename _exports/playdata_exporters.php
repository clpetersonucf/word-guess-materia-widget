<?php

return [
	'All Guesses' => function($inst, $semesters_string)
	{
		$csvs = [];

		foreach ($semesters_string as $semester)
		{
			list($year, $term) = explode('-', $semester);

			// Get all scores for each semester
			$logs = \Materia\Session_Play::get_by_inst_id($inst->id, $term, $year);

			$results = [];
			foreach ($logs as $play)
			{
				$play_id = $play['id'];
				if ( ! isset($results[$play_id])) $results[$play_id] = [];

				$play_events = \Materia\Session_Logger::get_logs($play_id);
				foreach ($play_events as $play_event)
				{
					$r                   = [];
					$r['qset_id']        = $play['qset_id'];
					$r['semester']       = $semester;
					$r['type']           = $play_event->type;
					$r['item_id']        = $play_event->item_id;
					$r['text']           = $play_event->text;
					$r['value']          = $play_event->value;
					$r['created_at']     = $play_event->created_at;
					$results[$play_id][] = $r;
				}
			}

			// If we didn't find any results, just return with nothing.
			if ( ! count($results)) return false;

			foreach ($results as $playid => $playlog)
			{
				$qset_id = $playlog[0]['qset_id'];

				// If we don't have the qset for this version, get it and do all of the setup.
				if (empty($csvs[$qset_id]))
				{
					$cur_csv = $csvs[$qset_id] = [];
					$cur_csv['questions'] = [];
					$cur_csv['rows'] = [];
					$cur_csv['timestamp'] = $playlog[0]['created_at'];

					// Get the qset with this qset_id.
					$qset = $inst->get_specific_qset($qset_id);

					// Get the data from the qset and decode it then get questions from it.
					$data = $qset[0]['data'];
					$decoded_data = json_decode(base64_decode($data), true);
					$questions = \Materia\Widget_Instance::find_questions($decoded_data);

					// Legacy QSets follow the inane [items][items] scheme
					if (isset($questions[0]) && isset($questions[0]['items']))
					{
						$questions = $questions[0]['items'];
					}

					// Question_text is the question headers in the form of a string.
					$cur_csv['question_text'] = '';
					foreach ($questions as $q)
					{
						$clean_str = str_replace(["\r","\n", ','], '', $q->questions[0]['text']);
						$clean_str .= '('.str_replace(["\r","\n", ','], '', $q->answers[0]['text']).')';

						$cur_csv['question_text'] .= $clean_str.', ';
						$cur_csv['questions'][] = $q->id;
					}

					// Important to figure out where each response should go in the response array.
					$cur_csv['num_questions'] = count($questions);
				}
				else
				{
					$cur_csv = $csvs[$qset_id];
				}

				// Array for the current row. Initialize with empty strings so when it is
				// concatenated later it takes in account empty spots.
				$logs = array_fill(0, $cur_csv['num_questions'], '');

				foreach ($playlog as $r)
				{
					// Append each response to the row.
					// If a response is logged for the same question TWICE, use the last response?
					if ($r['type'] == 'SCORE_QUESTION_ANSWERED')
					{
						// If the question id is in the question array for the current csv.
						if (array_search($r['item_id'], $cur_csv['questions']) !== false)
						{
							$position = array_search($r['item_id'], $cur_csv['questions']);
							$logs[$position] = str_replace(["\r","\n", ','], '', $r['text']);
						}
					}
				}

				$cur_csv['rows'][] = implode(', ', $logs);
				$csvs[$qset_id] = $cur_csv;
			}

			// Return the csv zip.
			$tempname = tempnam('/tmp', 'materia_raw_log_csv');
			$zip = new \ZipArchive();
			$zip->open($tempname);
			foreach ($csvs as $key => $csv)
			{
				$string = $csv['question_text']."\r\n".implode("\r\n", $csv['rows']);
				$zip->addFromString($inst->name.' (created '.date('m-d-y h_ia', $csv['timestamp'] ).').csv', $string);
			}
			$zip->close();

			$data = file_get_contents($tempname);
			unlink($tempname);

			return [$data, '.zip'];
		}
	},
];
