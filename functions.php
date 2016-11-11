<?php
	function process_csv($file) {
		global $conn;
		$all_time_employee = array();
		$floating_employees = array();
		$base_query = "INSERT INTO `temp` (`name`, `stime`, `cost`, `etime`, `duty`) VALUES ";
		$qpart = array();
		$fields = array();

		$file = fopen($file, 'r');
		$counter = 0;
		while(! feof($file)) {
			$row = fgetcsv($file);
			if(empty($row)) {
				continue;
			}
			$counter++;

			if($counter == 1) {
				$fields = $row;
				continue;
			}

			$temp = array();
			foreach($row as $key => $element) {
				$temp[strtolower($fields[$key])] = $element;
			}

			if(empty($temp['time'])) {
				$all_time_employee[] = $temp;
			} else {
				$floating_employees[] = $temp;
			}
			$qpart[] = '("'.$temp['name'].'", "'.get_day_time($temp['time']).'", "'.$temp['cost'].'", "'.get_day_time($temp['time'], $temp['duty']).'", "'.$temp['duty'].'")';
		}
		fclose($file);

		$query = $base_query.implode(',', $qpart);
		$conn->query("TRUNCATE `temp`");
		$conn->query($query);
	}

	function get_day_time($time, $duty=0) {
		return empty($time) ? 0 : (empty($duty) ? (strtotime("01-01-1970 $time") - strtotime("01-01-1970 00:00:00")) : get_day_time($time)+($duty*3600));
	}

	function fit_anywhere($stime, $etime, $row) {
		global $schedule;
		global $slots;
		if($stime <= $slots['min'] && $etime > $slots['min'] && ($slots['min'] - $etime != 0)) {
			if(!isset($schedule[$slots['min']])) {
				$schedule[$slots['min']] = $row;
				$slots['min'] = $etime;
			}
		} elseif($stime >= $slots['min'] && ($slots['min'] - $etime != 0)) {
			if(!isset($schedule[$slots['min']])) {
				$schedule[$slots['min']] = $row;
				$slots['min'] = $etime;
			}
		}
	}

	function fit_regular_employees() {
		global $conn;
		$sql_1 = "SELECT * FROM `temp` where stime != 0 order by stime, cost ASC";
		$result_1 = $conn->query($sql_1);
		if ($result_1->num_rows > 0) {
			while($row = $result_1->fetch_assoc()) {
				fit_anywhere($row['stime'], $row['etime'], $row);
			}
		} else {
			die("Zero employees available");
		}
	}

	function get_empty_slots() {
		global $empty_slots;
		global $schedule;
		global $slots_copy;

		if(!empty($schedule)) {
			$starting_time = $slots_copy['min'];
			$closing_time = $slots_copy['max'];
			$counter = 0;
			foreach($schedule as $start_time=>$row) {
				$counter++;
				if($start_time < $row['stime']) {
					$empty_slots[] = array('min' => $start_time, 'max' => $row['stime'], 'gap' => ($row['stime'] - $start_time));
				}
				if($counter == 1) {
					$empty_slots[] = array('min' => $starting_time, 'max' => $start_time, 'gap' => ($start_time - $starting_time));
				}
				if(count($schedule) == $counter && $closing_time > $row['stime']) {
					$empty_slots[] = array('min' => $row['etime'], 'max' => $closing_time, 'gap' => ($closing_time - $row['etime']));
				}
			}
		}
	}

	function fit_freelancers() {
		global $conn;
		global $empty_slots;
		global $schedule;
		$floating_employees = array();
		$sql_2 = "SELECT * FROM `temp` where stime = 0 order by stime, cost ASC";
		$result_2 = $conn->query($sql_2);
		if ($result_2->num_rows > 0) {
			while($row = $result_2->fetch_assoc()) {
				$floating_employees[] = (object) $row;
			}
			foreach($empty_slots as $e_slot) {
				for($i=0; $i<count($floating_employees); $i++) {
					$f_employ = $floating_employees[$i];
					if($f_employ->duty > 0) {
						if($e_slot['gap'] <= $f_employ->duty*3600) {
							if(isset($schedule[$e_slot['min']])) {
								$schedule[$schedule[$e_slot['min']]['stime']] = $schedule[$e_slot['min']];
								$f_employ->stime = $e_slot['min'];
								$f_employ->etime = $schedule[$e_slot['min']]['stime'];
								$schedule[$e_slot['min']] = (array) $f_employ;
							} else {
								$f_employ->stime = $e_slot['min'];
								$f_employ->etime = $e_slot['max'];
								$schedule[$e_slot['min']] = (array) $f_employ;
							}
							$f_employ->duty = (($f_employ->duty*3600) - $e_slot['gap'])/3600;
							$i = count($floating_employees);
						} else {
							// refill remaining slot
						}
					}
				}
			}
		} else {
			die("Zero employees available");
		}
	}

	function fit_horses_to_stable() {
		global $conn;
		global $min_working_time;
		global $schedule;
		global $schedule_v2;

		$sql_1 = "SELECT * FROM `temp` where duty > $min_working_time AND stime != 0 ORDER BY cost ASC, duty DESC";
		$horses = $conn->query($sql_1);
		if ($horses->num_rows > 0) {
			while($horse = $horses->fetch_assoc()) {
				fit_horse($horse['stime'], $horse['etime'], $horse);
			}
			foreach($schedule_v2 as $key => $sch) {
				list($stime, $etime) = explode('_', $key);
				$schedule[$stime] = $sch;
			}
		} else {
			die("Zero employees available");
		}
	}

	function fit_horse_manner($min, $max, $row) {
		global $schedule_v2;
		global $slots;
		$min = $slots['min'] > $min ? $slots['min'] : $min;
		$max = $slots['max'] < $max ? $slots['max'] : $max;
		if($max > $min) {
			$row['stime'] = $min;
			$row['etime'] = $max;
			$schedule_v2[implode('_', array($min, $max))] = $row;
		}
	}

	function fit_horse($stime, $etime, $row) {
		global $schedule_v2;

		if(count($schedule_v2) > 0) {
			$struct_with_slab = false;
			$impacted_slots = array();
			$impacted_slots_min = array();
			$impacted_slots_max = array();
			foreach($schedule_v2 as $slot=>$data) {
				list($min, $max) = explode('_', $slot);
				if($stime < $max && $etime > $min) {
					$struct_with_slab = true;
					$impacted_slots[] = $slot;
					$impacted_slots_min[] = $min;
					$impacted_slots_max[] = $max;
				}
			}
			if($struct_with_slab) {
				ksort($impacted_slots);
				for($i=0; $i<count($impacted_slots); $i++) {
					list($min_1, $max_1) = explode('_', $impacted_slots[$i]);
					if(!empty($impacted_slots[$i+1])) {
						list($min_2, $max_2) = explode('_', $impacted_slots[$i+1]);
					} else {
						list($min_2, $max_2) = array(0, 0);
					}
					
					if($stime < $min_1) {
						$x = $stime;
						$y = $min_1;
						fit_horse_manner($x, $y, $row);
					}
					if($stime >= $min_1) {
						if(empty($min_2)) {
							$x = $max_1;
							$y = $etime;
							fit_horse_manner($x, $y, $row);
						} else {
							if($max_1 == $min_2) {
								$stime = $max_2;
							} else {
								$x = $max_1;
								$y = $min_2;
								fit_horse_manner($x, $y, $row);
							}
						}
					}
				}
			} else {
				fit_horse_manner($stime, $etime, $row);
			}
			
		} else {
			fit_horse_manner($stime, $etime, $row);
		}
	}
?>
