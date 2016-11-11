<?php
$start_time = round(microtime(true) * 1000);
require_once('connection.php'); // Mysql Connection and logging
require_once('functions.php');

$slots_copy = $slots = array('min' => get_day_time("03:00:00"), 'max' => get_day_time("23:00:00"));
$date = "2016-11-01";

//$dates = array('min' => "09-11-2016", 'max' => "10-11-2016");
//$secs = strtotime($dates['max']." 00:00:00") - strtotime($dates['min']." 00:00:00");
//$schedule_for_days = ($secs / 86400) + 1;
//for($i=0; $i<$schedule_for_days; $i++) {
//	//echo $i;
//}

$schedule = array();
$schedule_v2 = array();
$empty_slots = array();
/* ------------------ Company Options ------------------------ */
$min_working_time = 3600/3600;
/* ----------------------------------------------------------- */


//process_csv('/home/ashish/Desktop/Problem.csv');

//fit_regular_employees();                            =============
//get_empty_slots();                                             ====== Old Version Code
//fit_freelancers();                                  =============

fit_horses_to_stable();
get_empty_slots();
fit_freelancers();

ksort($schedule); // Sort by Key (Final Output)

/* ------------------------ Formatting Output ---------------------- */
$base_query = "INSERT INTO `schedule` (`date`, `stime`, `etime`, `emp_id`, `cost`, `duty`, `status`) VALUES ";
$qpart = array();
$total_cost = 0;
$formatted_output = array();
$suggestions = array();
foreach($schedule as $key=>$item) {
	$schedule[$key]['stime'] = $key;
	$schedule[$key]['duty'] = ($schedule[$key]['etime'] - $schedule[$key]['stime'])/3600;

	$qpart[] = "('".$date."', '".$schedule[$key]['stime']."', '".$schedule[$key]['etime']."', '".$schedule[$key]['id']."', '".$schedule[$key]['cost']."', '".$schedule[$key]['duty']."', 'Pending')";

	echo $schedule[$key]['id']." :: ".$schedule[$key]['cost']." :: ".$schedule[$key]['duty']."\n";
	$total_cost += $schedule[$key]['cost']*$schedule[$key]['duty'];

	if(isset($suggestions[$schedule[$key]['id']])) {
		$suggestions[$schedule[$key]['id']][] = array('stime' => (int) $schedule[$key]['stime'], 'etime' => (int) $schedule[$key]['etime'], 'duty' => $schedule[$key]['duty']);
		continue;
	} else {
		$suggestions[$schedule[$key]['id']] = array();
		$suggestions[$schedule[$key]['id']][] = array('stime' => (int) $schedule[$key]['stime'], 'etime' => (int) $schedule[$key]['etime'], 'duty' => $schedule[$key]['duty']);
	}

	$temp = array('user_id' => $schedule[$key]['id'], 'name' => $schedule[$key]['name'], 'cost' => $schedule[$key]['cost']);
	$formatted_output[] = $temp;
}

$conn->query("TRUNCATE `schedule`");
if(count($qpart) > 0) {
	$sql = $base_query.implode(', ', $qpart);
	$conn->query($sql);
}

foreach($formatted_output as $key=>$output) {
	$formatted_output[$key] = array_merge($formatted_output[$key], array('count' => count($suggestions[$formatted_output[$key]['user_id']]), 'suggestions' => $suggestions[$formatted_output[$key]['user_id']]));
}

$api_output = json_encode($formatted_output, true);
echo $api_output;

$end_time = round(microtime(true) * 1000);
echo "\nTime taken: ".($end_time - $start_time)." Micro Seconds\n";
echo "\nTotal Cost: $total_cost Dollar\n";
?>
